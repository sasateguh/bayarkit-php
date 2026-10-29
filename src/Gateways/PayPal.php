<?php

declare(strict_types=1);

namespace Bayarkit\Gateways;

use Bayarkit\CallbackInput;
use Bayarkit\CreatedPayment;
use Bayarkit\CreatePaymentInput;
use Bayarkit\GatewayError;
use Bayarkit\GatewayInfo;
use Bayarkit\Http\CurlTransport;
use Bayarkit\Http\Response;
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
use JsonException;
use RangeException;

/**
 * PayPal Orders v2 — the PHP twin of @bayarkit/paypal, written from the official docs (read 2026-10-29,
 * gateways/paypal/spec.md); NOT yet measured on the sandbox. The TS adapter's header gives the reasons; in short:
 * OAuth client credentials, the token cached for this object's life until a minute before it expires (and fetched
 * again once on a 401); `environment` sandbox by default. Amounts are integers in PayPal's smallest unit, sent as
 * PayPal's decimal strings (`toValue`/`fromValue`; HUF, JPY and TWD have no decimals). The order is charged in
 * CreatePaymentInput's `currency` (default MYR, which only a Malaysian PayPal account takes); `info()->currency` is the
 * configured one (default USD) that Confirm expects when Expected names none. The reference goes in
 * `purchase_units[0].custom_id`. The buyer link is `payer-action` (or `approve`).
 *
 * Paying is two steps: the buyer approves (order `APPROVED`, NOT paid), then your return URL or the
 * `CHECKOUT.ORDER.APPROVED` webhook calls `capturePayment($orderId)` (always with a `PayPal-Request-Id`, default
 * `capture-<order id>`, so a retry returns the first capture). `getPayment` only reads; paid is read from the capture
 * (`COMPLETED`, or refunded), never from the order. Mark the order paid with Confirm on the `PAYMENT.CAPTURE.COMPLETED`
 * webhook. Refunds go to the capture: what is left = the capture minus refunds that are not `FAILED`/`CANCELLED`.
 *
 * Webhooks are verified by PayPal's postback (`verify-webhook-signature`), which blocks: the five `PAYPAL-*` headers,
 * `webhookId` and the raw body spliced in byte for byte (after checking it is one JSON object). `FAILURE` = null; a
 * failed call throws GatewayError, which Confirm reports as `gateway_error`. Deduplicate on `raw['event_id']`.
 */
final class PayPal implements Refunds
{
    public const SANDBOX_HOST = 'api-m.sandbox.paypal.com';

    public const LIVE_HOST = 'api-m.paypal.com';

    /** The 24 currencies PayPal lists (spec §7). MYR only for a Malaysian account. */
    public const CURRENCIES = ['AUD', 'BRL', 'CAD', 'CNY', 'CZK', 'DKK', 'EUR', 'HKD', 'HUF', 'ILS', 'JPY', 'MYR', 'MXN',
        'TWD', 'NZD', 'NOK', 'PHP', 'PLN', 'GBP', 'SGD', 'SEK', 'CHF', 'THB', 'USD'];

    /** No decimal places in PayPal, whatever ISO 4217 says (spec §7). */
    public const ZERO_DECIMAL = ['HUF', 'JPY', 'TWD'];

    private const REFERENCE_MAX = 255;

    private const REQUEST_ID_MAX = 108;

    /** Refresh a cached token this many seconds before PayPal says it expires. */
    private const TOKEN_MARGIN = 60;

    /** The webhook headers that go into the postback (spec §5), postback field => header. */
    private const HEADERS = [
        'auth_algo' => 'paypal-auth-algo',
        'cert_url' => 'paypal-cert-url',
        'transmission_id' => 'paypal-transmission-id',
        'transmission_sig' => 'paypal-transmission-sig',
        'transmission_time' => 'paypal-transmission-time',
    ];

    /** PayPal order and capture ids are upper-case letters and digits; anything else never reaches a URL. */
    private const ID = '/^[A-Z0-9]{1,64}\z/';

    /** Access tokens (`A21AA…`) and Basic credentials, should PayPal ever echo one. */
    private const SECRETISH = '/\b(A21AA[A-Za-z0-9_-]+|Basic\s+[A-Za-z0-9+\/=]+)/';

    private const JSON = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    private readonly string $base;

    /** @var Closure(): int */
    private readonly Closure $now;

    private ?string $token = null;

    private int $tokenUntil = 0;

    /**
     * @param  string  $webhookId  the id PayPal gave the webhook you added to this REST app
     * @param  string  $environment  `sandbox` (default) or `live`
     * @param  string  $currency  `info()->currency`: what Confirm expects when Expected names none; one of CURRENCIES
     * @param  string|null  $brandName  shown on the PayPal page instead of the account's business name (≤ 127)
     * @param  string  $shippingPreference  `NO_SHIPPING` (default) or `GET_FROM_FILE`
     * @param  (Closure(): int)|null  $now  clock in seconds since the epoch (tests)
     */
    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $webhookId,
        string $environment = 'sandbox',
        private readonly string $currency = 'USD',
        private readonly ?string $brandName = null,
        private readonly string $shippingPreference = 'NO_SHIPPING',
        ?string $baseUrl = null,
        private readonly Transport $transport = new CurlTransport,
        private readonly int $timeoutMs = 15_000,
        ?Closure $now = null,
    ) {
        if ($clientId === '' || $clientSecret === '') {
            throw new GatewayError('clientId and clientSecret are required', 'paypal', 0, 'CONFIG');
        }
        if ($webhookId === '') {
            throw new GatewayError('webhookId is required (the id of the webhook on your REST app)', 'paypal', 0, 'CONFIG');
        }
        if ($environment !== 'sandbox' && $environment !== 'live') {
            throw new GatewayError("environment must be 'sandbox' or 'live'", 'paypal', 0, 'CONFIG');
        }
        if (! in_array($currency, self::CURRENCIES, true)) {
            throw new GatewayError("PayPal does not take {$currency}; pick one of ".implode(', ', self::CURRENCIES), 'paypal', 0, 'CURRENCY');
        }
        $this->base = rtrim($baseUrl ?? 'https://'.($environment === 'live' ? self::LIVE_HOST : self::SANDBOX_HOST), '/');
        $this->now = $now ?? static fn (): int => time();
    }

    public function info(): GatewayInfo
    {
        return new GatewayInfo('paypal', 1, 'docs', '2026-10-29',
            'developer.paypal.com + paypal-rest-api-specifications (not yet measured on the sandbox)', $this->currency);
    }

    /** An integer in PayPal's smallest unit as the decimal string PayPal takes: 1050 USD → "10.50", 500 JPY → "500". */
    public static function toValue(int $minor, string $currency): string
    {
        if ($minor < 0) {
            throw new RangeException('amount must be a whole number ≥ 0');
        }
        if (in_array($currency, self::ZERO_DECIMAL, true)) {
            return (string) $minor;
        }

        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    /** A PayPal decimal string back to the smallest unit; null for anything else (more decimals than the currency has). */
    public static function fromValue(mixed $value, string $currency): ?int
    {
        if (! is_string($value) || ! preg_match('/^(\d{1,15})(?:\.(\d+))?\z/', $value, $m)) {
            return null;
        }
        $decimals = in_array($currency, self::ZERO_DECIMAL, true) ? 0 : 2;
        $frac = $m[2] ?? '';
        if (strlen($frac) > $decimals && ! preg_match('/^0*$/', substr($frac, $decimals))) {
            return null;
        }

        return (int) $m[1] * 10 ** $decimals + (int) str_pad(substr($frac, 0, $decimals), $decimals, '0');
    }

    public static function captureStatus(mixed $status): PaymentStatus
    {
        return match ($status) {
            'COMPLETED', 'PARTIALLY_REFUNDED', 'REFUNDED' => PaymentStatus::Paid,
            'PENDING' => PaymentStatus::Pending,
            'DECLINED', 'FAILED' => PaymentStatus::Failed,
            default => PaymentStatus::Unknown,
        };
    }

    public static function orderStatus(mixed $status): PaymentStatus
    {
        return match ($status) {
            // APPROVED: the buyer said yes; nothing is captured.
            'CREATED', 'SAVED', 'PAYER_ACTION_REQUIRED', 'APPROVED' => PaymentStatus::Pending,
            'VOIDED' => PaymentStatus::Cancelled,
            default => PaymentStatus::Unknown,
        };
    }

    public function createPayment(CreatePaymentInput $input): CreatedPayment
    {
        $code = $input->currency;
        if (! in_array($code, self::CURRENCIES, true)) {
            throw new GatewayError("PayPal does not take {$code}", 'paypal', 0, 'CURRENCY');
        }
        if ($input->amount < $this->info()->minimumAmount) {
            throw new GatewayError("amount must be a whole number of {$code} minor units, at least 1", 'paypal', 0, 'INVALID_AMOUNT');
        }
        if ($input->reference === '' || mb_strlen($input->reference) > self::REFERENCE_MAX) {
            throw new GatewayError('reference must be 1 to '.self::REFERENCE_MAX.' characters', 'paypal', 0, 'INVALID_REFERENCE');
        }
        $unit = ['custom_id' => $input->reference, 'amount' => ['currency_code' => $code, 'value' => self::toValue($input->amount, $code)]];
        $description = mb_substr(trim($input->description), 0, 127);
        if ($description !== '') {
            $unit['description'] = $description;
        }
        $context = ['return_url' => $input->returnUrl, 'cancel_url' => $input->returnUrl, 'user_action' => 'PAY_NOW',
            'shipping_preference' => $this->shippingPreference];
        if ($this->brandName !== null && $this->brandName !== '') {
            $context['brand_name'] = mb_substr($this->brandName, 0, 127);
        }
        $order = $this->request('POST', '/v2/checkout/orders', $this->encode([
            'intent' => 'CAPTURE',
            'purchase_units' => [$unit],
            'payment_source' => ['paypal' => ['experience_context' => $context]],
        ]));
        $id = self::text($order['id'] ?? null);
        $url = '';
        foreach (is_array($order['links'] ?? null) ? $order['links'] : [] as $link) {
            if (is_array($link) && in_array($link['rel'] ?? null, ['payer-action', 'approve'], true)) {
                $url = self::text($link['href'] ?? null);
                break;
            }
        }
        if (! preg_match(self::ID, $id) || ! preg_match('#^https?://#', $url)) {
            throw new GatewayError('order without id or approval link', 'paypal', 201);
        }

        return new CreatedPayment($id, $url, $order);
    }

    public function getPayment(string $id): PaymentRecord
    {
        self::checkId($id);

        return $this->toRecord($this->request('GET', '/v2/checkout/orders/'.$id), $id);
    }

    /**
     * Capture an order the buyer approved. Idempotent: the same `$idempotencyKey` (default `capture-<orderId>`) returns
     * the first capture. The record is PayPal's answer; mark the order paid with Confirm on the webhook.
     */
    public function capturePayment(string $orderId, ?string $idempotencyKey = null): PaymentRecord
    {
        self::checkId($orderId);
        self::checkRequestId($idempotencyKey);

        return $this->toRecord($this->request('POST', "/v2/checkout/orders/{$orderId}/capture", '{}', $idempotencyKey ?? "capture-{$orderId}"), $orderId);
    }

    public function refundPayment(RefundInput $input): RefundRecord
    {
        self::checkRequestId($input->idempotencyKey);
        $record = $this->getPayment($input->paymentId);
        $order = self::obj($record->raw);
        $capture = self::captureOf($order);
        if ($record->status !== PaymentStatus::Paid || $capture === null) {
            throw new GatewayError("payment is {$record->status->value}, not paid", 'paypal', 0, 'NOT_PAID');
        }
        $captureId = self::text($capture['id'] ?? null);
        if (! preg_match(self::ID, $captureId)) {
            throw new GatewayError('the order has no capture id', 'paypal', 0, 'NOT_PAID');
        }
        $refunded = 0;
        foreach (self::list(self::obj(self::unitOf($order)['payments'] ?? null)['refunds'] ?? null) as $r) {
            if (! in_array($r['status'] ?? null, ['FAILED', 'CANCELLED'], true)) {
                $refunded += self::fromValue(self::obj($r['amount'] ?? null)['value'] ?? null, $record->currency) ?? 0;
            }
        }
        $left = ($capture['status'] ?? null) === 'REFUNDED' ? 0 : ($record->amount ?? 0) - $refunded;
        $input->check('paypal', $left);
        $body = ['amount' => ['currency_code' => $record->currency, 'value' => self::toValue($input->amount, $record->currency)]];
        $reason = mb_substr(trim($input->reason ?? ''), 0, 255);
        if ($reason !== '') {
            $body['note_to_payer'] = $reason;
        }
        $r = $this->request('POST', "/v2/payments/captures/{$captureId}/refund", $this->encode($body), $input->idempotencyKey);
        $money = self::obj($r['amount'] ?? null);
        $code = self::text($money['currency_code'] ?? null) ?: $record->currency;
        $amount = self::fromValue($money['value'] ?? null, $code);
        if ($amount === null) {
            throw new GatewayError('refund without a readable amount', 'paypal', 201);
        }

        return new RefundRecord(self::text($r['id'] ?? null), $input->paymentId, match ($r['status'] ?? null) {
            'PENDING' => RefundStatus::Pending,
            'COMPLETED' => RefundStatus::Succeeded,
            'FAILED', 'CANCELLED' => RefundStatus::Failed,
            default => RefundStatus::Unknown,
        }, $amount, $code, $r);
    }

    /**
     * PayPal's postback (see the class comment). Null for anything not authentic, without calling PayPal when a header
     * is missing or the body is not one JSON object; throws GatewayError only when the call to PayPal fails.
     */
    public function verifyCallback(CallbackInput $callback): ?VerifiedCallback
    {
        $raw = $callback->rawBody;
        $fields = [];
        foreach (self::HEADERS as $field => $header) {
            $fields[$field] = $callback->headers[$header] ?? '';
            if ($fields[$field] === '') {
                return null;
            }
        }
        try {
            // Decoding proves the raw text is exactly one JSON object, so splicing it below cannot add other fields.
            if (! json_decode($raw, false, 512, JSON_THROW_ON_ERROR) instanceof \stdClass) {
                return null;
            }
            $event = self::obj(json_decode($raw, true, 512, JSON_THROW_ON_ERROR));
            $head = json_encode($fields + ['webhook_id' => $this->webhookId], self::JSON);
        } catch (JsonException) {
            return null;
        }
        $answer = $this->request('POST', '/v1/notifications/verify-webhook-signature', substr($head, 0, -1).',"webhook_event":'.$raw.'}');
        if (($answer['verification_status'] ?? null) !== 'SUCCESS') {
            return null;
        }

        // Authentic. Only checkout-order and capture events name an order; anything else is not a payment callback.
        $type = self::text($event['event_type'] ?? null);
        $resource = self::obj($event['resource'] ?? null);
        $orderId = $captureId = $reference = '';
        $claimed = PaymentStatus::Unknown;
        if (str_starts_with($type, 'CHECKOUT.ORDER.')) {
            $record = $this->toRecord($resource, self::text($resource['id'] ?? null));
            $orderId = $record->id;
            $captureId = self::text(self::captureOf($resource)['id'] ?? null);
            $reference = $record->reference;
            $claimed = $record->status;
        } elseif (str_starts_with($type, 'PAYMENT.CAPTURE.')) {
            $orderId = self::text(self::obj(self::obj($resource['supplementary_data'] ?? null)['related_ids'] ?? null)['order_id'] ?? null);
            $captureId = self::text($resource['id'] ?? null);
            $reference = self::text($resource['custom_id'] ?? null);
            $claimed = $type === 'PAYMENT.CAPTURE.DENIED' ? PaymentStatus::Failed : self::captureStatus($resource['status'] ?? null);
        }
        if (! preg_match(self::ID, $orderId)) {
            return null;
        }

        return new VerifiedCallback($orderId, $reference, $claimed, [
            'event_id' => self::text($event['id'] ?? null),
            'event_type' => $type,
            'order_id' => $orderId,
            'capture_id' => $captureId,
            'reference' => $reference,
            'status' => self::text($resource['status'] ?? null),
        ]);
    }

    /** @param array<mixed> $order */
    private function toRecord(array $order, string $id): PaymentRecord
    {
        $unit = self::unitOf($order);
        $capture = self::captureOf($order);
        $money = self::obj(($capture ?? $unit)['amount'] ?? null);
        $code = self::text($money['currency_code'] ?? null);
        // Paid is read from the capture, never from the order: a COMPLETED order can hold a DECLINED capture.
        $status = $capture !== null ? self::captureStatus($capture['status'] ?? null) : self::orderStatus($order['status'] ?? null);
        $fee = null;
        $paidAt = null;
        if ($capture !== null) {
            $feeMoney = self::obj(self::obj($capture['seller_receivable_breakdown'] ?? null)['paypal_fee'] ?? null);
            $fee = self::fromValue($feeMoney['value'] ?? null, self::text($feeMoney['currency_code'] ?? null));
            if ($status === PaymentStatus::Paid && is_string($capture['create_time'] ?? null)) {
                try {
                    $paidAt = new DateTimeImmutable($capture['create_time']);
                } catch (Exception) {
                    $paidAt = null;
                }
            }
        }

        return new PaymentRecord(self::text($order['id'] ?? null) ?: $id, self::text($unit['custom_id'] ?? null), $status,
            self::fromValue($money['value'] ?? null, $code), $code, $paidAt, $fee, $order);
    }

    private function bearer(): string
    {
        $cached = $this->token;
        if ($cached !== null && $this->tokenUntil > ($this->now)()) {
            return $cached;
        }
        $res = $this->send('POST', '/v1/oauth2/token', [
            'Authorization' => 'Basic '.base64_encode("{$this->clientId}:{$this->clientSecret}"),
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json',
        ], 'grant_type=client_credentials');
        $json = self::obj(json_decode($res->body, true));
        $value = self::text($json['access_token'] ?? null);
        if ($res->status < 200 || $res->status > 299 || $value === '') {
            // OAuth's own words (`error`, `error_description`) only.
            $words = implode(': ', array_filter([self::text($json['error'] ?? null), self::text($json['error_description'] ?? null)]));
            $code = self::text($json['error'] ?? null);
            throw new GatewayError(self::mask('token: '.($words !== '' ? $words : "HTTP {$res->status}")), 'paypal', $res->status, $code !== '' ? $code : null);
        }
        $this->token = $value;
        $this->tokenUntil = ($this->now)() + (is_int($json['expires_in'] ?? null) ? $json['expires_in'] : 0) - self::TOKEN_MARGIN;

        return $value;
    }

    /** @return array<mixed> PayPal's JSON object */
    private function request(string $method, string $path, ?string $body = null, ?string $requestId = null): array
    {
        $headers = static function (string $token) use ($body, $requestId): array {
            $h = ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json', 'Prefer' => 'return=representation'];
            if ($body !== null) {
                $h['Content-Type'] = 'application/json';
            }
            if ($requestId !== null) {
                $h['PayPal-Request-Id'] = $requestId;
            }

            return $h;
        };
        $used = $this->bearer();
        $res = $this->send($method, $path, $headers($used), $body);
        if ($res->status === 401) {
            // PayPal ended the token early: fetch a new one once.
            if ($this->token === $used) {
                $this->token = null;
            }
            $res = $this->send($method, $path, $headers($this->bearer()), $body);
        }
        $json = json_decode($res->body, true);
        if ($res->status < 200 || $res->status > 299 || ! is_array($json) || array_is_list($json)) {
            // PayPal's own words only: `name`, `details[].issue`, `details[].description`, `debug_id`.
            $e = self::obj($json);
            $detail = self::list($e['details'] ?? null)[0] ?? [];
            $issue = self::text($detail['issue'] ?? null);
            $words = implode(': ', array_filter([self::text($e['name'] ?? null), $issue,
                self::text($detail['description'] ?? null) ?: self::text($e['message'] ?? null)]));
            $debug = self::text($e['debug_id'] ?? null);
            $code = $issue ?: self::text($e['name'] ?? null);
            throw new GatewayError(self::mask(($words !== '' ? $words : "HTTP {$res->status}").($debug !== '' ? " (debug_id {$debug})" : '')),
                'paypal', $res->status, $code !== '' ? $code : null);
        }

        return $json;
    }

    /** @param array<string, string> $headers */
    private function send(string $method, string $path, array $headers, ?string $body): Response
    {
        try {
            // Credentials travel in request headers only; messages quote the RESPONSE.
            return $this->transport->send($method, $this->base.$path, $headers, $body, $this->timeoutMs);
        } catch (TransportError $e) {
            throw new GatewayError('unreachable ('.$e->getMessage().')', 'paypal', 0);
        }
    }

    /** @param array<mixed> $body */
    private function encode(array $body): string
    {
        return json_encode($body, self::JSON);
    }

    private static function checkId(string $id): void
    {
        if (! preg_match(self::ID, $id)) {
            throw new GatewayError('invalid order id', 'paypal', 0, 'INVALID_ID');
        }
    }

    private static function checkRequestId(?string $key): void
    {
        if ($key !== null && ($key === '' || strlen($key) > self::REQUEST_ID_MAX)) {
            throw new GatewayError('idempotencyKey must be 1 to '.self::REQUEST_ID_MAX.' characters', 'paypal', 0, 'INVALID_KEY');
        }
    }

    private static function mask(string $text): string
    {
        return (string) preg_replace(self::SECRETISH, '[redacted]', $text);
    }

    /**
     * @param  array<mixed>  $order
     * @return array<mixed>
     */
    private static function unitOf(array $order): array
    {
        return self::list($order['purchase_units'] ?? null)[0] ?? [];
    }

    /**
     * @param  array<mixed>  $order
     * @return array<mixed>|null
     */
    private static function captureOf(array $order): ?array
    {
        return self::list(self::obj(self::unitOf($order)['payments'] ?? null)['captures'] ?? null)[0] ?? null;
    }

    /** @return array<mixed> */
    private static function obj(mixed $v): array
    {
        return is_array($v) && ! array_is_list($v) ? $v : [];
    }

    /** @return list<array<mixed>> */
    private static function list(mixed $v): array
    {
        return is_array($v) && array_is_list($v) ? array_map(self::obj(...), $v) : [];
    }

    private static function text(mixed $v): string
    {
        return is_string($v) ? $v : '';
    }
}
