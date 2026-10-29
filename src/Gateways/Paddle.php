<?php

declare(strict_types=1);

namespace Bayarkit\Gateways;

use Bayarkit\CallbackInput;
use Bayarkit\CreatedPayment;
use Bayarkit\CreatePaymentInput;
use Bayarkit\GatewayError;
use Bayarkit\GatewayInfo;
use Bayarkit\Http\CurlTransport;
use Bayarkit\Http\Transport;
use Bayarkit\Http\TransportError;
use Bayarkit\PaymentRecord;
use Bayarkit\PaymentStatus;
use Bayarkit\RefundInput;
use Bayarkit\RefundRecord;
use Bayarkit\Refunds;
use Bayarkit\RefundStatus;
use Bayarkit\VerifiedCallback;
use Closure;
use DateTimeImmutable;
use Exception;

/**
 * Paddle Billing — the PHP twin of @bayarkit/paddle, written from the official docs (read 2026-10-29,
 * gateways/paddle/spec.md); NOT yet measured on the sandbox. The TS adapter's header gives the reasons; in short:
 * Paddle has no MYR, so one currency per instance (default USD) is declared in `info()->currency` and any other is
 * refused before calling Paddle — pass `currency:` to CreatePaymentInput, whose default is MYR. Amounts are minor-unit
 * strings, the price is tax-inclusive (`tax_mode: internal`) and the amount read back is
 * `details.totals.grand_total`. `Paddle-Version: 1` is pinned and the key decides the host (`pdl_sdbx_` = sandbox,
 * `pdl_live_` = live). Your reference travels in `custom_data.reference`; the payment link is your default payment
 * link plus `?_ptxn=<id>`. `returnUrl`, `callbackUrl` and `expiresAt` are not sent (Paddle has no field for them).
 * `paid`/`completed` = paid; a refund leaves a transaction `completed`. Refunds are adjustments: `full` when the whole
 * amount is still refundable, else `partial` on the one line item; they start `pending_approval` (= pending).
 * Notifications: HMAC-SHA256 over `<ts>:<raw body>` with the destination's secret, any `h1` may match (rotation), then
 * an age check (default 5 s, one-sided like the TS twin).
 */
final class Paddle implements Refunds
{
    public const VERSION = '1';

    public const SANDBOX_HOST = 'sandbox-api.paddle.com';

    public const LIVE_HOST = 'api.paddle.com';

    /** The 33 payment currencies Paddle lists (spec §6). MYR is not one. */
    public const CURRENCIES = ['USD', 'GBP', 'EUR', 'ARS', 'AUD', 'BRL', 'CAD', 'CHF', 'CLP', 'CNY', 'COP', 'CZK', 'DKK',
        'HKD', 'HUF', 'ILS', 'INR', 'JPY', 'KRW', 'MXN', 'NOK', 'NZD', 'PEN', 'PLN', 'RUB', 'SEK', 'SGD', 'THB', 'TRY', 'TWD',
        'UAH', 'VND', 'ZAR'];

    /** Minimum charges the docs state, minor units (spec §6). Other currencies: Paddle has the final word. */
    public const MINIMUMS = ['USD' => 70, 'EUR' => 65, 'GBP' => 55, 'SGD' => 90, 'JPY' => 100];

    public const STATUS = [
        'paid' => PaymentStatus::Paid, 'completed' => PaymentStatus::Paid,
        'draft' => PaymentStatus::Pending, 'ready' => PaymentStatus::Pending, 'billed' => PaymentStatus::Pending,
        'canceled' => PaymentStatus::Failed, 'past_due' => PaymentStatus::Failed,
    ];

    private const REFERENCE_MAX = 200;

    private const TXN_ID = '/^txn_[a-z\d]{26}$/';

    private const SECRETISH = '/\b(pdl_[A-Za-z0-9_]+|test_[A-Za-z0-9]{8,})/';

    private readonly string $base;

    /** @var Closure(): int */
    private readonly Closure $now;

    /**
     * @param  string  $apiKey  `pdl_sdbx_apikey_…` (sandbox) or `pdl_live_apikey_…` (live); the key decides the host
     * @param  string  $webhookSecret  the notification destination's secret key, `pdl_ntfset_…`
     * @param  string  $currency  every payment is charged in this one; must be one Paddle takes (not MYR)
     * @param  string  $taxCategory  `product.tax_category`; must be enabled on your account
     * @param  string|null  $checkoutUrl  a checkout page on an approved domain; default = your default payment link
     * @param  int  $toleranceSeconds  maximum notification age (paddle-node-sdk's 5); must be above 0
     * @param  (Closure(): int)|null  $now  clock in seconds since the epoch (tests)
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly string $webhookSecret,
        private readonly string $currency = 'USD',
        private readonly string $taxCategory = 'standard',
        private readonly ?string $checkoutUrl = null,
        private readonly int $toleranceSeconds = 5,
        ?string $baseUrl = null,
        private readonly Transport $transport = new CurlTransport,
        private readonly int $timeoutMs = 15_000,
        ?Closure $now = null,
    ) {
        $host = str_starts_with($apiKey, 'pdl_sdbx_') ? self::SANDBOX_HOST : (str_starts_with($apiKey, 'pdl_live_') ? self::LIVE_HOST : null);
        if ($baseUrl === null && $host === null) {
            throw new GatewayError('apiKey must be a Paddle key (pdl_sdbx_… or pdl_live_…)', 'paddle', 0, 'CONFIG');
        }
        if ($toleranceSeconds <= 0) {
            // 0 would accept a captured notification forever.
            throw new GatewayError('toleranceSeconds must be greater than 0', 'paddle', 0, 'CONFIG');
        }
        if (! in_array($currency, self::CURRENCIES, true)) {
            throw new GatewayError("Paddle does not charge in {$currency}; pick one of ".implode(', ', self::CURRENCIES), 'paddle', 0, 'CURRENCY');
        }
        $this->base = rtrim($baseUrl ?? 'https://'.$host, '/');
        $this->now = $now ?? static fn (): int => time();
    }

    public function info(): GatewayInfo
    {
        return new GatewayInfo('paddle', self::MINIMUMS[$this->currency] ?? 1, 'docs', '2026-10-29',
            'developer.paddle.com + paddle-node-sdk (not yet measured on the sandbox)', $this->currency);
    }

    public static function mapStatus(mixed $status): PaymentStatus
    {
        return is_string($status) ? (self::STATUS[$status] ?? PaymentStatus::Unknown) : PaymentStatus::Unknown;
    }

    /**
     * `ts` and every `h1` from a `Paddle-Signature` header; other keys are dropped. `ts` is -1 when absent.
     *
     * @return array{ts: int, h1: list<string>}
     */
    public static function parseSignatureHeader(string $header): array
    {
        $ts = -1;
        $h1 = [];
        foreach (explode(';', $header) as $item) {
            $parts = explode('=', trim($item));
            $k = $parts[0];
            $v = $parts[1] ?? '';
            if ($k === 'ts' && preg_match('/^\d+$/', $v) && strlen($v) <= 15) {
                $ts = (int) $v;
            }
            if ($k === 'h1' && $v !== '') {
                $h1[] = $v;
            }
        }

        return ['ts' => $ts, 'h1' => $h1];
    }

    /** Verify a notification as the docs describe (see the class comment). Returns false instead of throwing. */
    public static function verifySignature(string $rawBody, string $header, string $secret, int $toleranceSeconds, int $nowSeconds): bool
    {
        if ($secret === '' || $header === '' || $toleranceSeconds <= 0) {
            return false;
        }
        ['ts' => $ts, 'h1' => $signatures] = self::parseSignatureHeader($header);
        if ($ts < 0 || $signatures === []) {
            return false;
        }
        $expected = hash_hmac('sha256', "{$ts}:{$rawBody}", $secret);
        $match = false;
        foreach ($signatures as $s) {
            $match = hash_equals($expected, $s) || $match;
        }

        return $match && $nowSeconds - $ts <= $toleranceSeconds;
    }

    public function createPayment(CreatePaymentInput $input): CreatedPayment
    {
        $minimum = $this->info()->minimumAmount;
        if ($input->amount < $minimum) {
            throw new GatewayError("amount must be a whole number of minor units, at least {$minimum} {$this->currency}", 'paddle', 0, 'INVALID_AMOUNT');
        }
        if ($input->currency !== $this->currency) {
            throw new GatewayError("this adapter charges in {$this->currency}, not {$input->currency}", 'paddle', 0, 'CURRENCY');
        }
        if ($input->reference === '' || mb_strlen($input->reference) > self::REFERENCE_MAX) {
            throw new GatewayError('reference must be 1 to '.self::REFERENCE_MAX.' characters', 'paddle', 0, 'INVALID_REFERENCE');
        }
        $words = trim($input->description);
        $words = $words !== '' ? $words : "Order {$input->reference}";
        $body = [
            'items' => [[
                'quantity' => 1,
                'price' => [
                    // `description` is internal (2–500), `name` is shown at checkout (1–150).
                    'description' => mb_substr(mb_strlen($words) >= 2 ? $words : "Order {$input->reference}", 0, 500),
                    'name' => mb_substr($words, 0, 150),
                    'unit_price' => ['amount' => (string) $input->amount, 'currency_code' => $this->currency],
                    'tax_mode' => 'internal',
                    'product' => ['name' => mb_substr($words, 0, 200), 'tax_category' => $this->taxCategory],
                ],
            ]],
            'custom_data' => ['reference' => $input->reference],
            'collection_mode' => 'automatic',
        ];
        if ($this->checkoutUrl !== null && $this->checkoutUrl !== '') {
            $body['checkout'] = ['url' => $this->checkoutUrl];
        }
        $t = $this->request('POST', '/transactions', $body);
        $url = is_array($t['checkout'] ?? null) ? ($t['checkout']['url'] ?? null) : null;
        if (! is_string($t['id'] ?? null) || ! is_string($url) || ! preg_match('#^https?://#', $url)) {
            throw new GatewayError('transaction without id or checkout url', 'paddle', 201);
        }

        return new CreatedPayment($t['id'], $url, $t);
    }

    public function getPayment(string $id): PaymentRecord
    {
        if (! preg_match(self::TXN_ID, $id)) {
            throw new GatewayError('invalid transaction id', 'paddle', 0, 'INVALID_ID');
        }
        $t = $this->request('GET', '/transactions/'.$id, null);
        $totals = self::obj(self::obj($t['details'] ?? null)['totals'] ?? null);
        $status = self::mapStatus($t['status'] ?? null);
        $paidAt = null;
        foreach (is_array($t['payments'] ?? null) ? $t['payments'] : [] as $p) {
            if (is_array($p) && ($p['status'] ?? null) === 'captured' && is_string($p['captured_at'] ?? null)) {
                try {
                    $paidAt = $status === PaymentStatus::Paid ? new DateTimeImmutable($p['captured_at']) : null;
                } catch (Exception) {
                    $paidAt = null;
                }
                break;
            }
        }

        return new PaymentRecord(
            is_string($t['id'] ?? null) && $t['id'] !== '' ? $t['id'] : $id,
            self::text(self::obj($t['custom_data'] ?? null)['reference'] ?? null),
            $status,
            self::units($totals['grand_total'] ?? null),
            self::text($t['currency_code'] ?? null),
            $paidAt,
            self::units($totals['fee'] ?? null),
            $t,
        );
    }

    public function refundPayment(RefundInput $input): RefundRecord
    {
        $record = $this->getPayment($input->paymentId);
        if ($record->status !== PaymentStatus::Paid) {
            throw new GatewayError("payment is {$record->status->value}, not paid", 'paddle', 0, 'NOT_PAID');
        }
        $details = self::obj(self::obj($record->raw)['details'] ?? null);
        $left = self::units(self::obj($details['adjusted_totals'] ?? null)['grand_total'] ?? null);
        $input->check('paddle', $left ?? $record->amount ?? 0);
        $items = is_array($details['line_items'] ?? null) ? array_values($details['line_items']) : [];
        $body = ['action' => 'refund', 'transaction_id' => $input->paymentId, 'reason' => trim($input->reason ?? '') ?: 'refund'];
        if ($input->amount === $record->amount && $left === $record->amount) {
            $body['type'] = 'full';
        } else {
            $itemId = self::text(self::obj($items[0] ?? null)['id'] ?? null);
            if (count($items) !== 1 || $itemId === '') {
                throw new GatewayError('a partial refund needs a transaction with exactly one line item', 'paddle', 0, 'REFUND_ITEMS');
            }
            $body['type'] = 'partial';
            $body['items'] = [['item_id' => $itemId, 'type' => 'partial', 'amount' => (string) $input->amount]];
        }
        $adj = $this->request('POST', '/adjustments', $body);
        $amount = self::units(self::obj($adj['totals'] ?? null)['total'] ?? null);
        if ($amount === null) {
            throw new GatewayError('adjustment without a readable total', 'paddle', 201);
        }
        $status = $adj['status'] ?? null;

        return new RefundRecord(
            self::text($adj['id'] ?? null),
            self::text($adj['transaction_id'] ?? null) ?: $input->paymentId,
            match ($status) {
                'pending_approval' => RefundStatus::Pending,
                'approved' => RefundStatus::Succeeded,
                'rejected' => RefundStatus::Failed,
                default => RefundStatus::Unknown,
            },
            $amount,
            self::text($adj['currency_code'] ?? null) ?: $record->currency,
            $adj,
        );
    }

    public function verifyCallback(CallbackInput $callback): ?VerifiedCallback
    {
        $raw = $callback->rawBody;
        $header = $callback->headers['paddle-signature'] ?? '';
        if ($raw === '' || ! self::verifySignature($raw, $header, $this->webhookSecret, $this->toleranceSeconds, ($this->now)())) {
            return null;
        }
        // JSON is parsed only after the signature checks out.
        $event = json_decode($raw, true);
        if (! is_array($event) || array_is_list($event)) {
            return null;
        }
        $type = self::text($event['event_type'] ?? null);
        $t = self::obj($event['data'] ?? null);
        $id = self::text($t['id'] ?? null);
        if (! str_starts_with($type, 'transaction.') || ! preg_match(self::TXN_ID, $id)) {
            return null;
        }
        $claimed = self::mapStatus($t['status'] ?? null);
        if ($type === 'transaction.payment_failed' && $claimed !== PaymentStatus::Paid) {
            $claimed = PaymentStatus::Failed;
        }
        $reference = self::text(self::obj($t['custom_data'] ?? null)['reference'] ?? null);

        return new VerifiedCallback($id, $reference, $claimed, [
            'event_id' => self::text($event['event_id'] ?? null),
            'event_type' => $type,
            'transaction_id' => $id,
            'reference' => $reference,
            'status' => self::text($t['status'] ?? null),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return array<mixed> the response's `data` object
     */
    private function request(string $method, string $path, ?array $body): array
    {
        $headers = ['Authorization' => 'Bearer '.$this->apiKey, 'Paddle-Version' => self::VERSION, 'Accept' => 'application/json'];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        try {
            // The key travels in a request header only; messages quote the RESPONSE.
            $res = $this->transport->send($method, $this->base.$path, $headers,
                $body !== null ? json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null, $this->timeoutMs);
        } catch (TransportError $e) {
            throw new GatewayError('unreachable ('.$e->getMessage().')', 'paddle', 0);
        }
        $json = self::obj(json_decode($res->body, true));
        $data = self::obj($json['data'] ?? null);
        if ($res->status < 200 || $res->status > 299 || $data === []) {
            // Paddle's own words only: `{ error: { type, code, detail } }`, with anything key-shaped masked.
            $err = self::obj($json['error'] ?? null);
            $detail = self::text($err['detail'] ?? null) ?: "HTTP {$res->status}";
            $code = self::text($err['code'] ?? null);
            throw new GatewayError((string) preg_replace(self::SECRETISH, '[redacted]', $detail), 'paddle', $res->status, $code !== '' ? $code : null);
        }

        return $data;
    }

    /** @return array<mixed> */
    private static function obj(mixed $v): array
    {
        return is_array($v) && ! array_is_list($v) ? $v : [];
    }

    private static function text(mixed $v): string
    {
        return is_string($v) ? $v : '';
    }

    /** A minor-unit string ("1050") as an int; null for anything else, so it never matches. */
    private static function units(mixed $v): ?int
    {
        return is_string($v) && preg_match('/^\d{1,15}$/', $v) ? (int) $v : null;
    }
}
