<?php

declare(strict_types=1);

namespace Bayarkit\Gateways;

use Bayarkit\CallbackInput;
use Bayarkit\CreatedPayment;
use Bayarkit\CreatePaymentInput;
use Bayarkit\Gateway;
use Bayarkit\GatewayError;
use Bayarkit\GatewayInfo;
use Bayarkit\Http\CurlTransport;
use Bayarkit\Http\Transport;
use Bayarkit\Http\TransportError;
use Bayarkit\Money;
use Bayarkit\PaymentRecord;
use Bayarkit\PaymentStatus;
use Bayarkit\Text;
use Bayarkit\VerifiedCallback;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * toyyibPay — the PHP twin of @bayarkit/toyyibpay. Shapes MEASURED on dev.toyyibpay.com (10 October 2026, incl. one
 * test-bank FPX payment), not the documented ones; the TS adapter's header has the full docs-vs-sandbox table:
 * bodies prefixed with tabs and sent as text/html; "No data found!" for a bill without transactions; `{CategoryName}`;
 * bracketed error codes or `{"status":"error","msg":…}`; Malaysia-time dates; 100 sen minimum (inclusive);
 * billPayorInfo=1 requires an email; real callbacks are multipart and the documented hash holds.
 */
final class ToyyibPay implements Gateway
{
    public const HOSTS = ['sandbox' => 'https://dev.toyyibpay.com', 'production' => 'https://toyyibpay.com'];

    public const MINIMUM_SEN = 100;

    private const CHANNEL = ['fpx' => '0', 'card' => '1', 'both' => '2'];

    private const STATUS = ['1' => PaymentStatus::Paid, '2' => PaymentStatus::Pending, '3' => PaymentStatus::Failed, '4' => PaymentStatus::Pending];

    private readonly string $host;

    /**
     * @param  string  $environment  sandbox | production (checked: it usually comes from config)
     * @param  string  $channel  fpx (default, avoids card chargebacks) | card | both
     * @param  bool  $chargeFeeToCustomer  off by default: when on, the paid amount can exceed what you charged and
     *                                     `Confirm::payment` (exact amount match) refuses the payment
     */
    public function __construct(
        private readonly string $secretKey,
        private readonly string $categoryCode,
        string $environment = 'sandbox',
        private readonly string $channel = 'fpx',
        private readonly bool $chargeFeeToCustomer = false,
        ?string $baseUrl = null,
        private readonly Transport $transport = new CurlTransport,
        private readonly int $timeoutMs = 15_000,
    ) {
        if (! isset(self::HOSTS[$environment])) {
            throw new GatewayError('environment must be sandbox or production', 'toyyibpay', 0, 'CONFIG');
        }
        if (! isset(self::CHANNEL[$channel])) {
            throw new GatewayError('channel must be fpx, card or both', 'toyyibpay', 0, 'CONFIG');
        }
        $this->host = rtrim($baseUrl ?? self::HOSTS[$environment], '/');
    }

    public function info(): GatewayInfo
    {
        return new GatewayInfo('toyyibpay', self::MINIMUM_SEN, 'measured', '2026-10-10', 'dev.toyyibpay.com sandbox, incl. one test-bank FPX payment');
    }

    public function createPayment(CreatePaymentInput $input): CreatedPayment
    {
        Money::assertSen($input->amount, self::MINIMUM_SEN, 'toyyibpay');
        if ($input->currency !== 'MYR') {
            throw new GatewayError('only MYR is supported', 'toyyibpay', 0, 'CURRENCY');
        }
        $email = trim($input->customer->email);
        $fields = [
            'userSecretKey' => $this->secretKey,
            'categoryCode' => $this->categoryCode,
            'billName' => self::clean($input->description, 30) ?: 'Payment',
            'billDescription' => self::clean($input->description, 100) ?: 'Payment',
            'billPriceSetting' => '1',
            // Measured: payorInfo=1 requires an email; 0 makes the customer type their details on the bill page.
            'billPayorInfo' => $email !== '' ? '1' : '0',
            'billAmount' => (string) $input->amount,
            'billReturnUrl' => $input->returnUrl,
            'billCallbackUrl' => $input->callbackUrl,
            'billExternalReferenceNo' => $input->reference,
            'billTo' => mb_substr($input->customer->name, 0, 80),
            'billEmail' => $email,
            'billPhone' => $input->customer->phone,
            'billPaymentChannel' => self::CHANNEL[$this->channel],
            'billChargeToCustomer' => $this->chargeFeeToCustomer ? '0' : '',
        ];
        if ($input->expiresAt !== null) {
            $fields['billExpiryDate'] = self::malaysiaTime($input->expiresAt);
        }
        $r = $this->call('createBill', $fields);
        $code = is_array($r['json']) && array_is_list($r['json']) && is_array($r['json'][0] ?? null) ? ($r['json'][0]['BillCode'] ?? null) : null;
        if ($r['status'] === 200 && is_string($code) && preg_match('/^[A-Za-z0-9]{4,40}$/', $code)) {
            return new CreatedPayment($code, $this->host.'/'.$code, $r['json']);
        }
        $this->fail($r);
    }

    public function getPayment(string $id): PaymentRecord
    {
        // The owner's key (measured on the sandbox 27 Oct 2026): without it the payer fields come back empty, and with
        // another account's key the answer is `No data found!`, so a foreign bill code never reads as paid.
        $r = $this->call('getBillTransactions', ['billCode' => $id, 'userSecretKey' => $this->secretKey]);
        if ($r['status'] !== 200) {
            $this->fail($r);
        }
        // Measured: a bill with no transactions answers this exact text, not [].
        if ($r['text'] === 'No data found!') {
            return new PaymentRecord($id, '', PaymentStatus::Pending, 0, 'MYR', raw: []);
        }
        if (! is_array($r['json']) || ! array_is_list($r['json'])) {
            $this->fail($r);
        }
        /** @var list<array<string, mixed>> $txs */
        $txs = array_values(array_filter($r['json'], 'is_array'));
        $status = static fn (array $t): ?PaymentStatus => self::STATUS[Text::of($t['billpaymentStatus'] ?? null)] ?? null;
        $pick = null;
        foreach ([PaymentStatus::Paid, PaymentStatus::Pending] as $want) {
            foreach ($txs as $t) {
                if ($pick === null && $status($t) === $want) {
                    $pick = $t;
                }
            }
        }
        $pick ??= $txs === [] ? null : $txs[count($txs) - 1];
        if ($pick === null) {
            return new PaymentRecord($id, '', PaymentStatus::Pending, 0, 'MYR', raw: $txs);
        }
        $charge = Text::of($pick['transactionCharge'] ?? null);

        return new PaymentRecord(
            $id,
            Text::of($pick['billExternalReferenceNo'] ?? null),
            $status($pick) ?? PaymentStatus::Unknown,
            Money::toSen($pick['billpaymentAmount'] ?? null),
            'MYR',
            self::parseMalaysiaTime(Text::of($pick['billPaymentDate'] ?? null)),
            trim($charge) === '' ? null : Money::toSen($charge),
            $txs,
        );
    }

    public function verifyCallback(CallbackInput $callback): ?VerifiedCallback
    {
        $b = $callback->fields;
        $given = strtolower($b['hash'] ?? '');
        if (! preg_match('/^[0-9a-f]{32}$/', $given) || ($b['billcode'] ?? '') === '') {
            return null;
        }
        $expected = md5($this->secretKey.($b['status'] ?? '').($b['order_id'] ?? '').($b['refno'] ?? '').'ok');
        if (! hash_equals($expected, $given)) {
            return null;
        }

        return new VerifiedCallback($b['billcode'], $b['order_id'] ?? '', self::STATUS[$b['status'] ?? ''] ?? PaymentStatus::Unknown, $b);
    }

    /**
     * Close a bill so it can no longer be paid. Returns true when it was already inactive.
     *
     * @throws GatewayError
     */
    public function deactivate(string $billCode): bool
    {
        // The parameter is `secretKey`, not `userSecretKey` (inactiveBill docs).
        $r = $this->call('inactiveBill', ['secretKey' => $this->secretKey, 'billCode' => $billCode]);
        $j = is_array($r['json']) ? $r['json'] : [];
        if ($r['status'] === 200 && ($j['status'] ?? null) === 'success') {
            return false;
        }
        if ($r['status'] === 200 && ($j['status'] ?? null) === 'failed' && ($j['result'] ?? null) === 'Bill is inactive') {
            return true;
        }
        // Measured: {status:"error", result:"Invalid Secret Key!"} for a wrong key AND for a bill that does not exist.
        throw new GatewayError(mb_substr(Text::of($j['result'] ?? $r['text']), 0, 200), 'toyyibpay', $r['status'], is_string($j['status'] ?? null) ? $j['status'] : null);
    }

    /**
     * Check the secret key and category without creating a bill; returns the category name.
     *
     * @throws GatewayError
     */
    public function checkConnection(): string
    {
        $r = $this->call('getCategoryDetails', ['userSecretKey' => $this->secretKey, 'categoryCode' => $this->categoryCode]);
        // Docs: [{categoryName}]; sandbox: {CategoryName}. Accept both.
        $row = is_array($r['json']) && array_is_list($r['json']) ? ($r['json'][0] ?? null) : $r['json'];
        $name = is_array($row) ? ($row['CategoryName'] ?? $row['categoryName'] ?? null) : null;
        if ($r['status'] === 200 && is_string($name)) {
            return $name;
        }
        $this->fail($r);
    }

    /** toyyibPay accepts only letters, digits, spaces and `_` in billName/billDescription. */
    public static function clean(string $text, int $max): string
    {
        $t = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^A-Za-z0-9 _]+/', ' ', $text)));

        return trim(substr($t, 0, $max));
    }

    /** Date → "dd-mm-yyyy HH:MM:SS" in Malaysia time (measured for billExpiryDate). */
    public static function malaysiaTime(DateTimeInterface $date): string
    {
        return DateTimeImmutable::createFromInterface($date)->setTimezone(new DateTimeZone('Asia/Kuala_Lumpur'))->format('d-m-Y H:i:s');
    }

    /** "02-03-2021 16:24:25" (Malaysia time) → date; null for any other shape or an impossible date. */
    public static function parseMalaysiaTime(string $text): ?DateTimeImmutable
    {
        $text = trim($text);
        if (! preg_match('/^\d{2}-\d{2}-\d{4} \d{2}:\d{2}:\d{2}$/', $text)) {
            return null;
        }
        $d = DateTimeImmutable::createFromFormat('!d-m-Y H:i:s', $text, new DateTimeZone('Asia/Kuala_Lumpur'));

        return $d !== false && $d->format('d-m-Y H:i:s') === $text ? $d : null;
    }

    /**
     * @param  array<string, string>  $fields
     * @return array{status: int, json: mixed, text: string}
     */
    private function call(string $path, array $fields): array
    {
        try {
            $res = $this->transport->send('POST', $this->host.'/index.php/api/'.$path, ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query($fields), $this->timeoutMs);
        } catch (TransportError $e) {
            throw new GatewayError('unreachable ('.$e->getMessage().')', 'toyyibpay', 0);
        }
        $text = trim($res->body); // measured: leading tabs

        return ['status' => $res->status, 'json' => json_decode($text, true), 'text' => mb_substr($text, 0, 300)];
    }

    /**
     * Error text quotes the RESPONSE only; the secret travels in the request body and never reaches a message.
     *
     * @param  array{status: int, json: mixed, text: string}  $r
     */
    private function fail(array $r): never
    {
        $j = $r['json'];
        if (is_array($j) && ! array_is_list($j) && ($j['status'] ?? null) === 'error' && isset($j['msg'])) {
            $msg = mb_substr(Text::of($j['msg']), 0, 200);
            throw new GatewayError('refused: '.$msg, 'toyyibpay', $r['status'], $msg);
        }
        $code = preg_match('/^\[([A-Z-]+)\]$/', $r['text'], $m) ? $m[1] : null;
        throw new GatewayError($r['text'] !== '' ? $r['text'] : 'empty response (wrong secret key or category?)', 'toyyibpay', $r['status'], $code);
    }
}
