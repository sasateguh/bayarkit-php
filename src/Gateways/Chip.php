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
use Bayarkit\VerifiedCallback;
use DateTimeImmutable;
use OpenSSLAsymmetricKey;

/**
 * CHIP Collect — the PHP twin of @bayarkit/chip, written from the official OpenAPI (read 2026-10-23); NOT yet measured
 * against CHIP's test mode. The TS adapter's header lists every docs-vs-SDK difference; the ones that change code:
 * one host (a test SECRET KEY makes test purchases); amounts are integer sen; `success_callback` must not spell out a
 * port, not even :443; redirect/callback URLs go at the TOP level of the body; callbacks are signed in `X-Signature`
 * (base64 RSA PKCS#1 v1.5, SHA-256) over the RAW body; `GET /public_key/` answers a JSON-ENCODED PEM string (the
 * official PHP SDK reads `public_key` and is wrong). `error`/`blocked` are failed attempts, so they stay pending;
 * refunds and chargebacks are `unknown`, never paid.
 */
final class Chip implements Gateway
{
    public const BASE_URL = 'https://gate.chip-in.asia/api/v1';

    public const STATUS = [
        'created' => PaymentStatus::Pending, 'sent' => PaymentStatus::Pending, 'viewed' => PaymentStatus::Pending,
        'overdue' => PaymentStatus::Pending, 'error' => PaymentStatus::Pending, 'blocked' => PaymentStatus::Pending,
        'hold' => PaymentStatus::Pending, 'pending_execute' => PaymentStatus::Pending, 'pending_charge' => PaymentStatus::Pending,
        'pending_capture' => PaymentStatus::Pending, 'pending_release' => PaymentStatus::Pending, 'preauthorized' => PaymentStatus::Pending,
        'paid' => PaymentStatus::Paid, 'cleared' => PaymentStatus::Paid, 'settled' => PaymentStatus::Paid,
        'cancelled' => PaymentStatus::Cancelled, 'released' => PaymentStatus::Cancelled, 'expired' => PaymentStatus::Expired,
        'chargeback' => PaymentStatus::Unknown, 'pending_refund' => PaymentStatus::Unknown, 'refunded' => PaymentStatus::Unknown,
    ];

    private readonly string $base;

    private readonly ?OpenSSLAsymmetricKey $key;

    /**
     * @param  string|null  $publicKey  PEM that signs callbacks; get it once with fetchPublicKey() (same secret key) and
     *                                  store it in config. Without it every callback is refused. Literal `\n` is accepted.
     * @param  string|null  $environment  test | live | null — guard only: a purchase whose `is_test` disagrees is refused (MODE_MISMATCH)
     * @param  list<string>  $paymentMethods  `payment_method_whitelist`; empty = all enabled methods
     */
    public function __construct(
        private readonly string $secretKey,
        private readonly string $brandId,
        ?string $publicKey = null,
        private readonly ?string $environment = null,
        private readonly array $paymentMethods = [],
        ?string $baseUrl = null,
        private readonly Transport $transport = new CurlTransport,
        private readonly int $timeoutMs = 15_000,
    ) {
        if ($environment !== null && ! in_array($environment, ['test', 'live'], true)) {
            throw new GatewayError('environment must be test, live or empty', 'chip', 0, 'CONFIG');
        }
        $this->base = rtrim($baseUrl ?? self::BASE_URL, '/');
        $this->key = $publicKey !== null && $publicKey !== '' ? self::rsaKey($publicKey) : null;
    }

    public function info(): GatewayInfo
    {
        return new GatewayInfo('chip', 100, 'docs', '2026-10-23', 'CHIP Collect OpenAPI (docs.chip-in.asia/openapi/chip-collect.yaml)');
    }

    public static function mapStatus(mixed $status): PaymentStatus
    {
        return is_string($status) ? (self::STATUS[$status] ?? PaymentStatus::Unknown) : PaymentStatus::Unknown;
    }

    /** True when the URL text spells out a port (parse_url keeps it, but the TEXT is what CHIP checks). */
    public static function hasExplicitPort(string $url): bool
    {
        $authority = preg_match('#^[a-z][a-z0-9+.-]*://([^/?\#]*)#i', $url, $m) ? $m[1] : '';
        $at = strrpos($authority, '@');
        $host = $at === false ? $authority : substr($authority, $at + 1);
        if (str_starts_with($host, '[')) {
            $host = substr($host, (int) strpos($host, ']') + 1);
        }

        return (bool) preg_match('/:\d*$/', $host);
    }

    /** Body of `GET /public_key/` (a JSON-encoded PEM string) → PEM; null for any other shape. */
    public static function decodePublicKey(string $body): ?string
    {
        $value = json_decode($body);

        return is_string($value) && str_contains($value, '-----BEGIN PUBLIC KEY-----') ? $value : null;
    }

    public function createPayment(CreatePaymentInput $input): CreatedPayment
    {
        Money::assertSen($input->amount, 100, 'chip');
        if ($input->currency !== 'MYR') {
            throw new GatewayError('only MYR is supported', 'chip', 0, 'CURRENCY');
        }
        $email = trim($input->customer->email);
        if ($email === '') {
            // ClientDetails.email is the one required client field (OpenAPI).
            throw new GatewayError('customer email is required', 'chip', 0, 'EMAIL_REQUIRED');
        }
        if (! preg_match('#^https?://#i', $input->callbackUrl)) {
            throw new GatewayError('callbackUrl must be http or https', 'chip', 0, 'webhooks_callback_scheme');
        }
        if (self::hasExplicitPort($input->callbackUrl)) {
            throw new GatewayError('callbackUrl must not contain a port (CHIP rejects even :443)', 'chip', 0, 'webhooks_callback_port');
        }
        $name = trim($input->customer->name);
        $purchase = [
            'currency' => 'MYR',
            'products' => [['name' => mb_substr(trim($input->description), 0, 256) ?: 'Payment', 'price' => $input->amount]],
        ];
        $body = [
            'brand_id' => $this->brandId,
            'client' => ['email' => $email] + ($name !== '' ? ['full_name' => mb_substr($name, 0, 1000)] : []),
            'purchase' => $purchase,
            'reference' => $input->reference,
            // Top level only: nested inside `purchase` these are ignored silently.
            'success_redirect' => $input->returnUrl,
            'failure_redirect' => $input->returnUrl,
            'success_callback' => $input->callbackUrl,
        ];
        if ($input->expiresAt !== null) {
            $body['purchase']['due_strict'] = true;
            $body['due'] = $input->expiresAt->getTimestamp();
        }
        if ($this->paymentMethods !== []) {
            $body['payment_method_whitelist'] = $this->paymentMethods;
        }
        $r = $this->request('POST', '/purchases/', json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $p = $r['json'];
        if ($r['status'] !== 201 || ! is_array($p) || ! is_string($p['id'] ?? null) || ! is_string($p['checkout_url'] ?? null)
            || ! preg_match('#^https?://#', $p['checkout_url'])) {
            $this->fail('create purchase', $r);
        }
        $this->checkMode($p);

        return new CreatedPayment($p['id'], $p['checkout_url'], $p);
    }

    public function getPayment(string $id): PaymentRecord
    {
        if (! preg_match('/^[A-Za-z0-9-]{1,64}$/', $id)) {
            throw new GatewayError('invalid purchase id', 'chip', 0, 'INVALID_ID');
        }
        $r = $this->request('GET', '/purchases/'.$id.'/', null);
        $p = $r['json'];
        if ($r['status'] < 200 || $r['status'] > 299 || ! is_array($p) || array_is_list($p)) {
            $this->fail('purchase', $r);
        }
        $this->checkMode($p);
        $details = is_array($p['purchase'] ?? null) ? $p['purchase'] : [];
        $payment = is_array($p['payment'] ?? null) ? $p['payment'] : null;
        $int = static fn (mixed $v): ?int => is_int($v) ? $v : null;
        // What was actually received once paid (`payment.amount` = net + fee + pending); before that, what is charged.
        $amount = $int($payment['amount'] ?? null) ?? $int($details['total'] ?? null);
        $paidOn = $int($payment['paid_on'] ?? null);

        return new PaymentRecord(
            is_string($p['id'] ?? null) ? $p['id'] : $id,
            is_string($p['reference'] ?? null) ? $p['reference'] : '',
            self::mapStatus($p['status'] ?? null),
            $amount,
            (string) ($payment['currency'] ?? $details['currency'] ?? ''),
            $paidOn !== null ? (new DateTimeImmutable)->setTimestamp($paidOn) : null,
            $payment !== null ? $int($payment['fee_amount'] ?? null) : null,
            $p,
        );
    }

    public function verifyCallback(CallbackInput $callback): ?VerifiedCallback
    {
        $raw = $callback->rawBody;
        $sig = trim($callback->headers['x-signature'] ?? '');
        if ($this->key === null || $raw === '' || $sig === '' || strlen($sig) % 4 !== 0 || ! preg_match('#^[A-Za-z0-9+/]+={0,2}$#', $sig)) {
            return null;
        }
        $binary = base64_decode($sig, true);
        if ($binary === false || openssl_verify($raw, $binary, $this->key, OPENSSL_ALGO_SHA256) !== 1) {
            while (openssl_error_string() !== false) {
                // drain OpenSSL's error queue so a later call does not report this one
            }

            return null;
        }
        $p = json_decode($raw, true);
        if (! is_array($p) || array_is_list($p) || ! is_string($p['id'] ?? null) || $p['id'] === '') {
            return null;
        }
        $fields = [];
        foreach ($p as $k => $v) {
            if (is_string($v) || is_int($v) || is_float($v)) {
                $fields[(string) $k] = (string) $v;
            } elseif (is_bool($v)) {
                $fields[(string) $k] = $v ? 'true' : 'false';
            }
        }

        return new VerifiedCallback($p['id'], is_string($p['reference'] ?? null) ? $p['reference'] : '', self::mapStatus($p['status'] ?? null), $fields);
    }

    /**
     * `GET /public_key/` with this secret key, JSON-decoded to a PEM. Store it and pass it as `$publicKey`.
     *
     * @throws GatewayError
     */
    public function fetchPublicKey(): string
    {
        $r = $this->request('GET', '/public_key/', null);
        if ($r['status'] < 200 || $r['status'] > 299) {
            $this->fail('public key', $r);
        }
        $pem = self::decodePublicKey($r['text']);
        if ($pem === null) {
            throw new GatewayError('public key response is not a JSON-encoded PEM string', 'chip', $r['status']);
        }
        self::rsaKey($pem);

        return $pem;
    }

    private static function rsaKey(string $pem): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_get_public(str_replace('\n', "\n", $pem));
        $details = $key !== false ? openssl_pkey_get_details($key) : false;
        if ($key === false || $details === false) {
            throw new GatewayError('publicKey is not a PEM public key', 'chip', 0, 'INVALID_PUBLIC_KEY');
        }
        if (($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
            throw new GatewayError('publicKey must be an RSA key', 'chip', 0, 'INVALID_PUBLIC_KEY');
        }

        return $key;
    }

    /** @param array<mixed> $p */
    private function checkMode(array $p): void
    {
        if ($this->environment === null || ! is_bool($p['is_test'] ?? null)) {
            return;
        }
        if ($p['is_test'] !== ($this->environment === 'test')) {
            throw new GatewayError('purchase is_test='.($p['is_test'] ? 'true' : 'false')." but environment is \"{$this->environment}\"", 'chip', 0, 'MODE_MISMATCH');
        }
    }

    /** @return array{status: int, json: mixed, text: string} */
    private function request(string $method, string $path, ?string $body): array
    {
        try {
            // The secret key travels in a request header only; messages quote the RESPONSE.
            $res = $this->transport->send($method, $this->base.$path, [
                'Authorization' => 'Bearer '.$this->secretKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ], $body, $this->timeoutMs);
        } catch (TransportError $e) {
            throw new GatewayError('unreachable ('.$e->getMessage().')', 'chip', 0);
        }

        return ['status' => $res->status, 'json' => json_decode($res->body, true), 'text' => $res->body];
    }

    /** @param array{status: int, json: mixed, text: string} $r */
    private function fail(string $what, array $r): never
    {
        $e = self::errorOf($r['json']);
        throw new GatewayError($e !== null ? mb_substr($e['message'], 0, 200) : "{$what} HTTP {$r['status']}", 'chip', $r['status'], $e['code'] ?? null);
    }

    /**
     * First `{message, code}` anywhere in a CHIP error body (`__all__` is an array live, an object in the OpenAPI).
     *
     * @return array{message: string, code?: string}|null
     */
    private static function errorOf(mixed $json): ?array
    {
        if (! is_array($json)) {
            return null;
        }
        if (is_string($json['message'] ?? null)) {
            return ['message' => $json['message']] + (is_string($json['code'] ?? null) ? ['code' => $json['code']] : []);
        }
        foreach ($json as $v) {
            $found = self::errorOf($v);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }
}
