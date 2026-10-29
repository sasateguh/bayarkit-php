<?php

declare(strict_types=1);

namespace Bayarkit\Tests\Unit;

use Bayarkit\CallbackInput;
use Bayarkit\Confirm;
use Bayarkit\ConfirmResult;
use Bayarkit\CreatePaymentInput;
use Bayarkit\Customer;
use Bayarkit\Expected;
use Bayarkit\GatewayError;
use Bayarkit\Gateways\PayPal;
use Bayarkit\Http\CurlTransport;
use Bayarkit\Http\Response;
use Bayarkit\Http\Transport;
use Bayarkit\Http\TransportError;
use Bayarkit\PaymentStatus;
use Bayarkit\RefundInput;
use Bayarkit\RefundStatus;
use Bayarkit\Tests\Support\Fakes;
use Closure;
use PHPUnit\Framework\TestCase;

/** The same cases as packages/paypal/test/paypal.test.ts, against the same fake. */
final class PayPalTest extends TestCase
{
    private static int $counter = 0;

    private PayPalWire $wire;

    protected function setUp(): void
    {
        $this->wire = new PayPalWire;
    }

    /** @param array<string, mixed> $options */
    private function gateway(array $options = []): PayPal
    {
        $f = Fakes::get('paypal')['options'];
        $o = $options + ['clientId' => $f['clientId'], 'clientSecret' => $f['clientSecret'], 'webhookId' => $f['webhookId'],
            'baseUrl' => Fakes::get('paypal')['url'], 'transport' => $this->wire];

        return new PayPal(...$o);
    }

    private function input(int $amount = 1050, string $currency = 'USD', ?string $reference = null): CreatePaymentInput
    {
        self::$counter++;

        return new CreatePaymentInput($reference ?? 'ORDER'.time().self::$counter, $amount, 'PayPal test order',
            'https://shop.example/return', 'https://shop.example/callback', new Customer('Ali', 'ali@example.com'), $currency);
    }

    /** @return list<string> "METHOD /path" of every request since `$from` */
    private function paths(int $from = 0): array
    {
        return array_map(static fn (array $r): string => $r['method'].' '.(string) parse_url($r['url'], PHP_URL_PATH),
            array_slice($this->wire->sent, $from));
    }

    public function test_converts_smallest_units_to_paypal_decimal_strings_and_back(): void
    {
        $vectors = [[1050, 'MYR', '10.50'], [1, 'MYR', '0.01'], [100, 'USD', '1.00'], [123456789, 'USD', '1234567.89'],
            [500, 'JPY', '500'], [1, 'JPY', '1'], [2500, 'HUF', '2500'], [30, 'TWD', '30']];
        foreach ($vectors as [$minor, $currency, $value]) {
            $this->assertSame($value, PayPal::toValue($minor, $currency));
            $this->assertSame($minor, PayPal::fromValue($value, $currency));
        }
        $this->assertSame(1050, PayPal::fromValue('10.500', 'USD'));
        $this->assertSame(500, PayPal::fromValue('500.00', 'JPY'));
        foreach ([['10.505', 'USD'], ['500.5', 'JPY'], ['-1.00', 'USD'], ['1e3', 'USD'], ['', 'USD'], [10.5, 'USD'], [null, 'USD'], ["1.00\n", 'USD']] as [$v, $c]) {
            $this->assertNull(PayPal::fromValue($v, $c), var_export($v, true));
        }
        $this->expectException(\RangeException::class);
        PayPal::toValue(-1, 'USD');
    }

    public function test_lists_the_24_currencies_myr_among_them(): void
    {
        $this->assertCount(24, PayPal::CURRENCIES);
        $this->assertContains('MYR', PayPal::CURRENCIES);
    }

    public function test_creates_an_order_intent_capture_reference_in_custom_id_amount_a_string_payer_action_link(): void
    {
        $input = $this->input(1999);
        $created = $this->gateway()->createPayment($input);
        $sent = $this->wire->last();
        $this->assertSame('/v2/checkout/orders', parse_url($sent['url'], PHP_URL_PATH));
        $body = json_decode((string) $sent['body'], true);
        $this->assertSame('CAPTURE', $body['intent']);
        $this->assertCount(1, $body['purchase_units']);
        $this->assertSame($input->reference, $body['purchase_units'][0]['custom_id']);
        $this->assertSame(['currency_code' => 'USD', 'value' => '19.99'], $body['purchase_units'][0]['amount']);
        $this->assertArrayNotHasKey('invoice_id', $body['purchase_units'][0]);
        $context = $body['payment_source']['paypal']['experience_context'];
        $this->assertSame([$input->returnUrl, $input->returnUrl, 'PAY_NOW', 'NO_SHIPPING'],
            [$context['return_url'], $context['cancel_url'], $context['user_action'], $context['shipping_preference']]);
        $this->assertSame(Fakes::get('paypal')['url'].'/checkoutnow?token='.$created->id, $created->url);
        $this->assertSame(PaymentStatus::Pending, $this->gateway()->getPayment($created->id)->status);
    }

    public function test_charges_in_the_order_currency_and_refuses_others_before_calling_paypal(): void
    {
        $g = $this->gateway();
        $this->assertSame('USD', $g->info()->currency);
        $g->createPayment($this->input(1050, 'MYR'));
        $this->assertSame(['currency_code' => 'MYR', 'value' => '10.50'], json_decode((string) $this->wire->last()['body'], true)['purchase_units'][0]['amount']);
        $g->createPayment($this->input(500, 'JPY'));
        $this->assertSame(['currency_code' => 'JPY', 'value' => '500'], json_decode((string) $this->wire->last()['body'], true)['purchase_units'][0]['amount']);
        $before = count($this->wire->sent);
        foreach ([$this->input(1050, 'IDR'), $this->input(reference: str_repeat('x', 256)), $this->input(0)] as $bad) {
            try {
                $g->createPayment($bad);
                $this->fail('accepted '.$bad->currency.' '.$bad->amount);
            } catch (GatewayError $e) {
                $this->assertSame(0, $e->status);
            }
        }
        $this->assertCount($before, $this->wire->sent);
        $this->assertSame('MYR', $this->gateway(['currency' => 'MYR'])->info()->currency);
        $this->expectException(GatewayError::class);
        $this->gateway(['currency' => 'IDR']);
    }

    public function test_caches_the_token_and_fetches_a_new_one_once_when_paypal_ends_it(): void
    {
        $g = $this->gateway();
        $created = $g->createPayment($this->input());
        $g->getPayment($created->id);
        $g->getPayment($created->id);
        $this->assertSame(1, count(array_filter($this->paths(), static fn (string $p): bool => $p === 'POST /v1/oauth2/token')));
        // PayPal ends the token early: the next order read is answered 401 once.
        $ended = false;
        $this->wire->script = static function (string $method, string $url) use ($created, &$ended): ?Response {
            if ($ended || ! str_ends_with($url, '/v2/checkout/orders/'.$created->id)) {
                return null;
            }
            $ended = true;

            return new Response(401, '{"error":"invalid_token","error_description":"Token signature verification failed"}');
        };
        $from = count($this->wire->sent);
        $this->assertSame(PaymentStatus::Pending, $g->getPayment($created->id)->status);
        $this->assertSame(['GET /v2/checkout/orders/'.$created->id, 'POST /v1/oauth2/token', 'GET /v2/checkout/orders/'.$created->id], $this->paths($from));
    }

    public function test_fetches_a_new_token_a_minute_before_it_expires(): void
    {
        $now = 1_000;
        $g = $this->gateway(['now' => static function () use (&$now): int {
            return $now;
        }]);
        $id = $g->createPayment($this->input())->id;
        $now += 32_400 - 61; // the fake's tokens last 9 hours
        $g->getPayment($id);
        $this->assertCount(1, array_filter($this->paths(), static fn (string $p): bool => $p === 'POST /v1/oauth2/token'));
        $now += 2;
        $g->getPayment($id);
        $this->assertCount(2, array_filter($this->paths(), static fn (string $p): bool => $p === 'POST /v1/oauth2/token'));
    }

    public function test_a_wrong_client_secret_quotes_paypal_never_the_secret(): void
    {
        try {
            $this->gateway(['clientSecret' => 'secret_wrong_value'])->createPayment($this->input());
            $this->fail('accepted a wrong secret');
        } catch (GatewayError $e) {
            $this->assertStringContainsString('invalid_client', $e->getMessage());
            $this->assertStringNotContainsString('secret_wrong_value', $e->getMessage());
            $this->assertStringNotContainsString(base64_encode('client_fake:secret_wrong_value'), $e->getMessage());
        }
    }

    public function test_masks_tokens_and_basic_credentials_paypal_might_echo(): void
    {
        $this->wire->script = static fn (string $method, string $url): ?Response => str_ends_with($url, '/v1/oauth2/token')
            ? new Response(200, '{"access_token":"A21AAtoken","expires_in":32400}')
            : new Response(400, '{"name":"INVALID_REQUEST","details":[{"issue":"BAD","description":"saw A21AAleaked and Basic Y2xpZW50OnNlY3JldA=="}],"debug_id":"abc123"}');
        try {
            $this->gateway()->getPayment('ORDER1');
            $this->fail('no error');
        } catch (GatewayError $e) {
            $this->assertSame('paypal: INVALID_REQUEST: BAD: saw [redacted] and [redacted] (debug_id abc123)', $e->getMessage());
            $this->assertSame([400, 'BAD'], [$e->status, $e->gatewayCode]);
        }
    }

    public function test_get_payment_only_reads_an_approved_order_is_pending_and_never_captured(): void
    {
        $g = $this->gateway();
        $input = $this->input();
        $created = $g->createPayment($input);
        Fakes::approve('paypal', $created->id);
        $from = count($this->wire->sent);
        $record = $g->getPayment($created->id);
        $this->assertSame(PaymentStatus::Pending, $record->status);
        $this->assertSame('APPROVED', $record->raw['status']);
        $this->assertSame(['GET /v2/checkout/orders/'.$created->id], $this->paths($from));
        // A signed "capture completed" event for an approved, uncaptured order is not paid.
        $result = Confirm::payment($g, Fakes::callbackFor('paypal', $created->id), new Expected($input->reference, $input->amount, 'USD'));
        $this->assertSame(ConfirmResult::NOT_PAID, $result->reason);
    }

    public function test_capture_payment_captures_once_with_the_same_request_id_then_confirm_is_ok(): void
    {
        $g = $this->gateway();
        $input = $this->input(2500);
        $created = $g->createPayment($input);
        Fakes::approve('paypal', $created->id);
        $first = $g->capturePayment($created->id);
        $retry = $g->capturePayment($created->id);
        $this->assertSame([PaymentStatus::Paid, 2500, 'USD', $input->reference], [$first->status, $first->amount, $first->currency, $first->reference]);
        $this->assertNotNull($first->paidAt);
        $captures = array_values(array_filter($this->wire->sent, static fn (array $r): bool => str_ends_with($r['url'], '/v2/checkout/orders/'.$created->id.'/capture')));
        $this->assertSame(['capture-'.$created->id, 'capture-'.$created->id], array_map(static fn (array $r): string => $r['headers']['PayPal-Request-Id'] ?? '', $captures));
        $this->assertSame('{}', $captures[0]['body']);
        $this->assertSame($first->raw, $retry->raw);
        $this->assertTrue(Confirm::payment($g, Fakes::callbackFor('paypal', $created->id), new Expected($input->reference, 2500, 'USD'))->ok);
        // Another key is another capture attempt: PayPal refuses it, nothing is captured twice.
        try {
            $g->capturePayment($created->id, 'my-key');
            $this->fail('captured twice');
        } catch (GatewayError $e) {
            $this->assertSame('ORDER_ALREADY_CAPTURED', $e->gatewayCode);
        }
        $this->assertSame('my-key', $this->wire->last()['headers']['PayPal-Request-Id'] ?? '');
    }

    public function test_capturing_before_approval_is_paypals_422_and_a_bad_id_never_reaches_a_url(): void
    {
        $g = $this->gateway();
        $created = $g->createPayment($this->input());
        try {
            $g->capturePayment($created->id);
            $this->fail('captured an unapproved order');
        } catch (GatewayError $e) {
            $this->assertSame([422, 'ORDER_NOT_APPROVED'], [$e->status, $e->gatewayCode]);
        }
        $from = count($this->wire->sent);
        foreach (['../x', "ORDER1\n", ''] as $bad) {
            try {
                $g->capturePayment($bad);
                $this->fail('accepted '.$bad);
            } catch (GatewayError $e) {
                $this->assertSame('INVALID_ID', $e->gatewayCode);
            }
        }
        $this->assertSame([], $this->paths($from));
    }

    public function test_reads_paid_from_the_capture_never_from_the_order(): void
    {
        $order = static fn (string $orderStatus, ?string $captureStatus): array => ['id' => 'ORDER1', 'status' => $orderStatus,
            'purchase_units' => [['custom_id' => 'R1', 'amount' => ['currency_code' => 'USD', 'value' => '10.50'],
                'payments' => $captureStatus === null ? null : ['captures' => [['id' => 'CAP1', 'status' => $captureStatus,
                    'amount' => ['currency_code' => 'USD', 'value' => '10.50'], 'create_time' => '2026-10-29T01:02:03Z',
                    'seller_receivable_breakdown' => ['paypal_fee' => ['currency_code' => 'USD', 'value' => '0.85']]]]]]]];
        $cases = [['CREATED', null, PaymentStatus::Pending], ['PAYER_ACTION_REQUIRED', null, PaymentStatus::Pending],
            ['APPROVED', null, PaymentStatus::Pending], ['VOIDED', null, PaymentStatus::Cancelled], ['SOMETHING', null, PaymentStatus::Unknown],
            ['COMPLETED', 'COMPLETED', PaymentStatus::Paid], ['COMPLETED', 'DECLINED', PaymentStatus::Failed],
            ['COMPLETED', 'PENDING', PaymentStatus::Pending], ['COMPLETED', 'REFUNDED', PaymentStatus::Paid],
            ['COMPLETED', 'PARTIALLY_REFUNDED', PaymentStatus::Paid], ['COMPLETED', 'FAILED', PaymentStatus::Failed]];
        foreach ($cases as [$o, $c, $expected]) {
            $this->wire->script = static fn (string $method, string $url): Response => str_ends_with($url, '/v1/oauth2/token')
                ? new Response(200, '{"access_token":"t","expires_in":32400}') : new Response(200, (string) json_encode($order($o, $c)));
            $record = $this->gateway()->getPayment('ORDER1');
            $this->assertSame($expected, $record->status, "{$o}/{$c}");
            $this->assertSame([1050, 'USD', 'R1'], [$record->amount, $record->currency, $record->reference]);
            if ($expected === PaymentStatus::Paid) {
                $this->assertSame([85, '2026-10-29T01:02:03+00:00'], [$record->fee, $record->paidAt?->format(DATE_ATOM)]);
            } else {
                $this->assertNull($record->paidAt);
            }
        }
    }

    public function test_verifies_through_the_postback_with_the_raw_body_spliced_in_byte_for_byte(): void
    {
        $g = $this->gateway();
        $created = $g->createPayment($this->input());
        Fakes::markPaid('paypal', $created->id);
        $cb = Fakes::callbackFor('paypal', $created->id);
        $from = count($this->wire->sent);
        $verified = $g->verifyCallback($cb);
        $this->assertSame([$created->id, PaymentStatus::Paid], [$verified?->id, $verified?->claimedStatus]);
        $this->assertSame($g->getPayment($created->id)->raw['purchase_units'][0]['payments']['captures'][0]['id'], $verified?->raw['capture_id']);
        $posts = array_values(array_filter(array_slice($this->wire->sent, $from), static fn (array $r): bool => str_ends_with($r['url'], '/v1/notifications/verify-webhook-signature')));
        $this->assertCount(1, $posts);
        $post = $posts[0];
        $this->assertStringEndsWith(',"webhook_event":'.$cb->rawBody.'}', (string) $post['body']);
        $sent = json_decode((string) $post['body'], true);
        $this->assertSame(['WH_FAKE', $cb->headers['paypal-transmission-sig']], [$sent['webhook_id'], $sent['transmission_sig']]);
        // One byte changed, a re-serialised body, or another webhook id: PayPal says FAILURE.
        $this->assertNull($g->verifyCallback(new CallbackInput([], str_replace('COMPLETED', 'COMPLETEd', $cb->rawBody), $cb->headers)));
        $this->assertNull($g->verifyCallback(new CallbackInput([], (string) json_encode(json_decode($cb->rawBody)), $cb->headers)));
        $this->assertNull($this->gateway(['webhookId' => 'WH_OTHER'])->verifyCallback($cb));
    }

    public function test_refuses_without_calling_paypal_when_a_header_is_missing_or_the_body_is_not_one_json_object(): void
    {
        $g = $this->gateway();
        $created = $g->createPayment($this->input());
        $cb = Fakes::callbackFor('paypal', $created->id);
        $from = count($this->wire->sent);
        $headers = $cb->headers;
        unset($headers['paypal-cert-url']);
        $this->assertNull($g->verifyCallback(new CallbackInput([], $cb->rawBody, $headers)));
        foreach (['', '[1]', '"x"', $cb->rawBody.',"webhook_id":"x"', $cb->rawBody.'}', "\xff"] as $raw) {
            $this->assertNull($g->verifyCallback(new CallbackInput([], $raw, $cb->headers)), $raw);
        }
        $this->assertNull($g->verifyCallback(new CallbackInput([], $cb->rawBody, ['paypal-cert-url' => "\xff"] + $cb->headers)));
        $this->assertSame([], $this->paths($from));
    }

    public function test_a_verify_call_that_fails_is_a_gateway_error_in_confirm_not_a_forgery(): void
    {
        $g = $this->gateway();
        $input = $this->input();
        $created = $g->createPayment($input);
        $this->wire->fail = '/verify-webhook-signature';
        $result = Confirm::payment($g, Fakes::callbackFor('paypal', $created->id), new Expected($input->reference, $input->amount, 'USD'));
        $this->assertSame(ConfirmResult::GATEWAY_ERROR, $result->reason);
    }

    public function test_an_authentic_event_that_names_no_order_is_not_a_payment_callback(): void
    {
        $g = $this->gateway();
        $created = $g->createPayment($this->input());
        $cb = Fakes::callbackFor('paypal', $created->id);
        // The fake signs whatever it sends; swap in an event of another kind through the postback answer.
        $this->wire->script = static fn (string $method, string $url): ?Response => str_ends_with($url, '/verify-webhook-signature')
            ? new Response(200, '{"verification_status":"SUCCESS"}') : null;
        $dispute = (string) json_encode(['id' => 'WH-1', 'event_type' => 'CUSTOMER.DISPUTE.CREATED', 'resource' => ['id' => $created->id]]);
        $this->assertNull($g->verifyCallback(new CallbackInput([], $dispute, $cb->headers)));
        $approved = (string) json_encode(['id' => 'WH-2', 'event_type' => 'CHECKOUT.ORDER.APPROVED',
            'resource' => ['id' => $created->id, 'status' => 'APPROVED', 'purchase_units' => [['custom_id' => 'R9']]]]);
        $v = $g->verifyCallback(new CallbackInput([], $approved, $cb->headers));
        $this->assertSame([$created->id, 'R9', PaymentStatus::Pending], [$v?->id, $v?->reference, $v?->claimedStatus]);
        $denied = (string) json_encode(['id' => 'WH-3', 'event_type' => 'PAYMENT.CAPTURE.DENIED', 'resource' => ['id' => 'CAP1',
            'status' => 'DECLINED', 'custom_id' => 'R9', 'supplementary_data' => ['related_ids' => ['order_id' => $created->id]]]]);
        $this->assertSame(PaymentStatus::Failed, $g->verifyCallback(new CallbackInput([], $denied, $cb->headers))?->claimedStatus);
    }

    public function test_refunds_by_capture_id_reports_pending_and_counts_earlier_refunds(): void
    {
        $g = $this->gateway();
        $input = $this->input(1000);
        $created = $g->createPayment($input);
        Fakes::markPaid('paypal', $created->id);
        $captureId = $g->getPayment($created->id)->raw['purchase_units'][0]['payments']['captures'][0]['id'];
        $r = $g->refundPayment(new RefundInput($created->id, 400, 'damaged', 'r-1'));
        $this->assertSame([$created->id, RefundStatus::Succeeded, 400, 'USD'], [$r->paymentId, $r->status, $r->amount, $r->currency]);
        $sent = $this->wire->last();
        $this->assertStringEndsWith('/v2/payments/captures/'.$captureId.'/refund', $sent['url']);
        $this->assertSame('r-1', $sent['headers']['PayPal-Request-Id'] ?? '');
        $this->assertSame(['amount' => ['currency_code' => 'USD', 'value' => '4.00'], 'note_to_payer' => 'damaged'], json_decode((string) $sent['body'], true));
        $before = count($this->wire->sent);
        try {
            $g->refundPayment(new RefundInput($created->id, 601));
            $this->fail('refunded more than is left');
        } catch (GatewayError $e) {
            $this->assertStringContainsString('exceeds the 600 sen paid', $e->getMessage());
        }
        $this->assertSame([], array_filter($this->paths($before), static fn (string $p): bool => str_ends_with($p, '/refund')));
        $this->assertSame(RefundStatus::Succeeded, $g->refundPayment(new RefundInput($created->id, 600))->status);
        // Fully refunded: still reads as paid (the refund is the record), and nothing is left to refund.
        $this->assertSame(PaymentStatus::Paid, $g->getPayment($created->id)->status);
        $this->expectException(GatewayError::class);
        $g->refundPayment(new RefundInput($created->id, 1));
    }

    public function test_maps_refund_statuses_and_refuses_an_unpaid_order(): void
    {
        foreach (['PENDING' => RefundStatus::Pending, 'COMPLETED' => RefundStatus::Succeeded, 'FAILED' => RefundStatus::Failed,
            'CANCELLED' => RefundStatus::Failed, 'NEW' => RefundStatus::Unknown] as $status => $expected) {
            $this->wire->script = static fn (string $method, string $url): Response => match (true) {
                str_ends_with($url, '/v1/oauth2/token') => new Response(200, '{"access_token":"t","expires_in":32400}'),
                str_ends_with($url, '/refund') => new Response(201, (string) json_encode(['id' => 'REF1', 'status' => $status,
                    'amount' => ['currency_code' => 'USD', 'value' => '1.00']])),
                default => new Response(200, (string) json_encode(['id' => 'ORDER1', 'status' => 'COMPLETED', 'purchase_units' => [[
                    'custom_id' => 'R1', 'payments' => ['captures' => [['id' => 'CAP1', 'status' => 'COMPLETED',
                        'amount' => ['currency_code' => 'USD', 'value' => '10.00']]]]]]])),
            };
            $this->assertSame($expected, $this->gateway()->refundPayment(new RefundInput('ORDER1', 100))->status, $status);
        }
        $g = $this->gateway();
        $this->wire->script = null;
        $created = $g->createPayment($this->input());
        try {
            $g->refundPayment(new RefundInput($created->id, 100));
            $this->fail('refunded an unpaid order');
        } catch (GatewayError $e) {
            $this->assertSame('NOT_PAID', $e->gatewayCode);
        }
    }

    public function test_refuses_a_config_without_credentials_or_webhook_id_and_an_unknown_environment(): void
    {
        foreach ([['clientSecret' => ''], ['clientId' => ''], ['webhookId' => ''], ['environment' => 'prod']] as $bad) {
            try {
                $this->gateway($bad);
                $this->fail('accepted '.json_encode($bad));
            } catch (GatewayError $e) {
                $this->assertSame('CONFIG', $e->gatewayCode);
            }
        }
    }

    public function test_the_environment_decides_the_host_sandbox_by_default(): void
    {
        $this->assertSame(['api-m.sandbox.paypal.com', 'api-m.paypal.com'], [PayPal::SANDBOX_HOST, PayPal::LIVE_HOST]);
        foreach ([[[], 'api-m.sandbox.paypal.com'], [['environment' => 'live'], 'api-m.paypal.com']] as [$options, $host]) {
            $this->wire->fail = '/oauth2/token';
            try {
                $this->gateway($options + ['baseUrl' => null])->getPayment('ORDER1');
            } catch (GatewayError) {
            }
            $this->assertSame($host, parse_url($this->wire->last()['url'], PHP_URL_HOST));
        }
    }
}

/** @internal records every request; `script` may answer one instead of the fake, `fail` makes a matching URL unreachable */
final class PayPalWire implements Transport
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    public array $sent = [];

    /** @var (Closure(string, string, array<string, string>): ?Response)|null */
    public ?Closure $script = null;

    public ?string $fail = null;

    private readonly Transport $inner;

    public function __construct()
    {
        $this->inner = new CurlTransport;
    }

    public function send(string $method, string $url, array $headers, ?string $body, int $timeoutMs): Response
    {
        $this->sent[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        if ($this->fail !== null && str_contains($url, $this->fail)) {
            throw new TransportError('connection refused');
        }

        return ($this->script !== null ? ($this->script)($method, $url, $headers) : null)
            ?? $this->inner->send($method, $url, $headers, $body, $timeoutMs);
    }

    /** @return array{method: string, url: string, headers: array<string, string>, body: ?string} */
    public function last(): array
    {
        return $this->sent[array_key_last($this->sent)] ?? throw new \RuntimeException('nothing sent');
    }
}
