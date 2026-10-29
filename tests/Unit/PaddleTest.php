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
use Bayarkit\Gateways\Paddle;
use Bayarkit\Http\CurlTransport;
use Bayarkit\Http\Response;
use Bayarkit\Http\Transport;
use Bayarkit\PaymentStatus;
use Bayarkit\RefundInput;
use Bayarkit\RefundStatus;
use Bayarkit\Tests\Support\Fakes;
use PHPUnit\Framework\TestCase;

/** The same cases as packages/paddle/test/paddle.test.ts, against the same fake. */
final class PaddleTest extends TestCase
{
    // The vector from paddle-node-sdk's own test suite (gateways/paddle/spec.md §4).
    private const SECRET = 'VALID_SECRET';

    private const BODY = '{"data": ["1", "2"]}';

    private const TS = 1698796800;

    private const H1 = 'a300428748dce5c70e4da19bffd60769591ea969c99dea3105d0ec9612cf43f9';

    private static int $counter = 0;

    private RecordingTransport $wire;

    protected function setUp(): void
    {
        $this->wire = new RecordingTransport(new CurlTransport);
    }

    private function gateway(string $currency = 'USD', ?string $apiKey = null): Paddle
    {
        $f = Fakes::get('paddle');

        return new Paddle($apiKey ?? $f['options']['apiKey'], $f['options']['webhookSecret'], $currency, baseUrl: $f['url'], transport: $this->wire);
    }

    private function input(int $amount = 1050, string $currency = 'USD'): CreatePaymentInput
    {
        self::$counter++;

        return new CreatePaymentInput('ORDER'.time().self::$counter, $amount, 'Paddle test order', 'https://shop.example/return',
            'https://shop.example/callback', new Customer('Ali', 'ali@example.com'), $currency);
    }

    /** A notification signed with the fake's secret, as Paddle would send it. */
    private function signed(string $body, ?int $ts = null): CallbackInput
    {
        $ts ??= time();
        $secret = Fakes::get('paddle')['options']['webhookSecret'];

        return new CallbackInput([], $body, ['Paddle-Signature' => "ts={$ts};h1=".hash_hmac('sha256', "{$ts}:{$body}", $secret)]);
    }

    public function test_accepts_the_sdk_test_vector(): void
    {
        $this->assertTrue(Paddle::verifySignature(self::BODY, 'ts='.self::TS.';h1='.self::H1, self::SECRET, 5, self::TS));
    }

    public function test_refuses_the_vector_when_one_byte_changes_or_the_hmac_is_over_ts_dot_body(): void
    {
        $header = 'ts='.self::TS.';h1='.self::H1;
        $this->assertFalse(Paddle::verifySignature(str_replace('1', '3', self::BODY), $header, self::SECRET, 5, self::TS));
        $dotted = 'ts='.self::TS.';h1='.hash_hmac('sha256', self::TS.'.'.self::BODY, self::SECRET);
        $this->assertFalse(Paddle::verifySignature(self::BODY, $dotted, self::SECRET, 5, self::TS));
    }

    public function test_refuses_a_stale_ts_and_accepts_one_inside_the_tolerance(): void
    {
        $header = 'ts='.self::TS.';h1='.self::H1;
        $this->assertFalse(Paddle::verifySignature(self::BODY, $header, self::SECRET, 5, self::TS + 6));
        $this->assertTrue(Paddle::verifySignature(self::BODY, $header, self::SECRET, 5, self::TS + 5));
    }

    public function test_accepts_when_one_of_several_h1_matches_and_refuses_when_none_does(): void
    {
        $other = hash_hmac('sha256', self::TS.':'.self::BODY, 'pdl_ntfset_old');
        $this->assertSame(['ts' => self::TS, 'h1' => [$other, self::H1]], Paddle::parseSignatureHeader('ts='.self::TS.";h1={$other};h1=".self::H1));
        $this->assertTrue(Paddle::verifySignature(self::BODY, 'ts='.self::TS.";h1={$other};h1=".self::H1, self::SECRET, 5, self::TS));
        $this->assertTrue(Paddle::verifySignature(self::BODY, 'ts='.self::TS.';h1='.self::H1.";h1={$other}", self::SECRET, 5, self::TS));
        $this->assertFalse(Paddle::verifySignature(self::BODY, 'ts='.self::TS.";h1={$other}", self::SECRET, 5, self::TS));
    }

    public function test_refuses_headers_without_ts_or_h1_and_an_empty_secret(): void
    {
        foreach (['', 'h1='.self::H1, 'ts='.self::TS, 'ts=abc;h1='.self::H1] as $header) {
            $this->assertFalse(Paddle::verifySignature(self::BODY, $header, self::SECRET, 5, self::TS), $header);
        }
        $this->assertFalse(Paddle::verifySignature(self::BODY, 'ts='.self::TS.';h1='.self::H1, '', 5, self::TS));
    }

    public function test_refuses_a_tolerance_of_zero_or_less_when_created(): void
    {
        $this->expectException(GatewayError::class);
        new Paddle('pdl_sdbx_apikey_x', 'pdl_ntfset_x', toleranceSeconds: 0);
    }

    public function test_pins_paddle_version_and_sends_an_inclusive_non_catalog_price_as_a_string(): void
    {
        $input = $this->input(1999);
        $created = $this->gateway()->createPayment($input);
        $sent = $this->wire->last();
        $this->assertSame(Paddle::VERSION, $sent['headers']['Paddle-Version']);
        $body = json_decode((string) $sent['body'], true);
        $this->assertCount(1, $body['items']);
        $this->assertSame(1, $body['items'][0]['quantity']);
        $this->assertSame('internal', $body['items'][0]['price']['tax_mode']);
        $this->assertSame(['amount' => '1999', 'currency_code' => 'USD'], $body['items'][0]['price']['unit_price']);
        $this->assertSame(['reference' => $input->reference], $body['custom_data']);
        $this->assertSame("https://shop.example/pay?_ptxn={$created->id}", $created->url);
        $this->assertMatchesRegularExpression('/^txn_[a-z\d]{26}$/', $created->id);
    }

    public function test_declares_its_currency_and_refuses_myr_before_calling_paddle(): void
    {
        $g = $this->gateway();
        $this->assertSame('USD', $g->info()->currency);
        try {
            $g->createPayment($this->input(currency: 'MYR'));
            $this->fail('charged MYR');
        } catch (GatewayError $e) {
            $this->assertSame('CURRENCY', $e->gatewayCode);
        }
        $this->assertSame([], $this->wire->sent);
        try {
            $this->gateway('MYR');
            $this->fail('configured MYR');
        } catch (GatewayError $e) {
            $this->assertSame('CURRENCY', $e->gatewayCode);
        }
        $eur = $this->gateway('EUR');
        $this->assertSame('EUR', $eur->info()->currency);
        $this->assertSame(65, $eur->info()->minimumAmount);
        $created = $eur->createPayment($this->input(currency: 'EUR'));
        $this->assertSame('EUR', $eur->getPayment($created->id)->currency);
    }

    public function test_an_order_confirmed_without_a_currency_is_compared_against_the_adapter_currency(): void
    {
        $g = $this->gateway();
        $input = $this->input();
        $created = $g->createPayment($input);
        Fakes::markPaid('paddle', $created->id);
        $cb = Fakes::callbackFor('paddle', $created->id);
        $ok = Confirm::payment($g, $cb, new Expected($input->reference, $input->amount));
        $this->assertTrue($ok->ok, $ok->detail);
        $this->assertSame('USD', $ok->payment?->currency);
        $this->assertSame(ConfirmResult::MISMATCH, Confirm::payment($g, $cb, new Expected($input->reference, $input->amount, 'MYR'))->reason);
    }

    public function test_reads_the_amount_from_grand_total_not_the_subtotal(): void
    {
        $id = 'txn_'.str_repeat('a', 26);
        $g = new Paddle('pdl_sdbx_apikey_x', 'pdl_ntfset_x', transport: new StubTransport(200, ['data' => [
            'id' => $id, 'status' => 'completed', 'currency_code' => 'USD', 'custom_data' => ['reference' => 'R1'],
            'details' => ['totals' => ['subtotal' => '1050', 'tax' => '105', 'grand_total' => '1155', 'fee' => null]],
            'payments' => [['status' => 'captured', 'captured_at' => '2026-10-29T01:02:03Z']],
        ]]));
        $record = $g->getPayment($id);
        $this->assertSame(1155, $record->amount);
        $this->assertSame(PaymentStatus::Paid, $record->status);
        $this->assertNull($record->fee);
        $this->assertSame('2026-10-29T01:02:03+00:00', $record->paidAt?->format(DATE_ATOM));
    }

    public function test_maps_every_transaction_status(): void
    {
        $id = 'txn_'.str_repeat('b', 26);
        $cases = ['draft' => PaymentStatus::Pending, 'ready' => PaymentStatus::Pending, 'billed' => PaymentStatus::Pending,
            'paid' => PaymentStatus::Paid, 'completed' => PaymentStatus::Paid, 'canceled' => PaymentStatus::Failed,
            'past_due' => PaymentStatus::Failed, 'something_new' => PaymentStatus::Unknown];
        foreach ($cases as $status => $expected) {
            $g = new Paddle('pdl_sdbx_apikey_x', 'pdl_ntfset_x', transport: new StubTransport(200, ['data' => ['id' => $id, 'status' => $status]]));
            $this->assertSame($expected, $g->getPayment($id)->status, $status);
        }
        $this->assertSame(PaymentStatus::Pending, $this->gateway()->getPayment($this->gateway()->createPayment($this->input())->id)->status);
    }

    public function test_a_payment_failed_notification_claims_failed_and_another_kind_is_refused(): void
    {
        $g = $this->gateway();
        $id = $g->createPayment($this->input())->id;
        $event = static fn (string $type) => (string) json_encode(['event_id' => 'evt_1', 'event_type' => $type,
            'data' => ['id' => $id, 'status' => 'ready', 'custom_data' => ['reference' => 'R1']]]);
        $failed = $g->verifyCallback($this->signed($event('transaction.payment_failed')));
        $this->assertSame($id, $failed?->id);
        $this->assertSame(PaymentStatus::Failed, $failed->claimedStatus);
        $this->assertNull($g->verifyCallback($this->signed($event('adjustment.created'))));
    }

    public function test_refuses_a_correctly_signed_notification_older_than_five_seconds(): void
    {
        $g = $this->gateway();
        $id = $g->createPayment($this->input())->id;
        $body = Fakes::callbackFor('paddle', $id)->rawBody;
        $this->assertNull($g->verifyCallback($this->signed($body, time() - 10)));
        $this->assertSame($id, $g->verifyCallback($this->signed($body))?->id);
    }

    public function test_refunds_full_and_partial_both_pending_until_approval(): void
    {
        $g = $this->gateway();
        $input = $this->input();
        $id = $g->createPayment($input)->id;
        Fakes::markPaid('paddle', $id);
        $full = $g->refundPayment(new RefundInput($id, $input->amount, 'duplicate'));
        $sent = json_decode((string) $this->wire->last()['body'], true);
        $this->assertSame(['action' => 'refund', 'transaction_id' => $id, 'reason' => 'duplicate', 'type' => 'full'], $sent);
        $this->assertSame([RefundStatus::Pending, $input->amount, 'USD'], [$full->status, $full->amount, $full->currency]);

        $other = $this->input();
        $second = $g->createPayment($other)->id;
        Fakes::markPaid('paddle', $second);
        $part = $g->refundPayment(new RefundInput($second, 300));
        $sent = json_decode((string) $this->wire->last()['body'], true);
        $this->assertSame('partial', $sent['type']);
        $itemId = $g->getPayment($second)->raw['details']['line_items'][0]['id'];
        $this->assertSame([['item_id' => $itemId, 'type' => 'partial', 'amount' => '300']], $sent['items']);
        $this->assertSame(RefundStatus::Pending, $part->status);
        // Paddle allows no new refund while one awaits approval: its own error is quoted.
        try {
            $g->refundPayment(new RefundInput($second, 100));
            $this->fail('a second refund while one awaits approval');
        } catch (GatewayError $e) {
            $this->assertSame('adjustment_pending_refund_request', $e->gatewayCode);
        }
    }

    public function test_an_approved_refund_lowers_what_is_left_before_paddle_is_asked(): void
    {
        $id = 'txn_'.str_repeat('c', 26);
        $wire = new StubTransport(200, ['data' => [
            'id' => $id, 'status' => 'completed', 'currency_code' => 'USD', 'custom_data' => ['reference' => 'R1'],
            'details' => ['totals' => ['grand_total' => '1050'], 'adjusted_totals' => ['grand_total' => '750'], 'line_items' => [['id' => 'txnitm_1']]],
        ]]);
        $g = new Paddle('pdl_sdbx_apikey_x', 'pdl_ntfset_x', transport: $wire);
        try {
            $g->refundPayment(new RefundInput($id, 751));
            $this->fail('refunded more than is left');
        } catch (GatewayError $e) {
            $this->assertStringContainsString('exceeds the 750 sen paid', $e->getMessage());
        }
        $this->assertSame(['GET'], array_column($wire->sent, 'method'));
        $g->refundPayment(new RefundInput($id, 750));
        $sent = json_decode((string) $wire->last()['body'], true);
        // 750 is all that is left but not the whole payment: a partial refund on the line item, not `full`.
        $this->assertSame('partial', $sent['type']);
    }

    public function test_quotes_paddles_error_never_the_api_key(): void
    {
        try {
            $this->gateway(apiKey: 'pdl_sdbx_apikey_wrong')->createPayment($this->input());
            $this->fail('accepted a wrong key');
        } catch (GatewayError $e) {
            $this->assertSame([403, 'invalid_token'], [$e->status, $e->gatewayCode]);
            $this->assertStringNotContainsString('apikey_wrong', $e->getMessage());
        }
    }

    public function test_masks_anything_key_shaped_in_paddles_error_text(): void
    {
        $g = new Paddle('pdl_sdbx_apikey_x', 'pdl_ntfset_x', transport: new StubTransport(400, ['error' => [
            'type' => 'request_error', 'code' => 'bad_request', 'detail' => 'key pdl_sdbx_apikey_leaked is wrong']]));
        try {
            $g->getPayment('txn_'.str_repeat('d', 26));
            $this->fail('no error');
        } catch (GatewayError $e) {
            $this->assertSame('paddle: key [redacted] is wrong', $e->getMessage());
        }
    }

    public function test_refuses_an_id_that_is_not_a_paddle_transaction_id_before_calling_paddle(): void
    {
        try {
            $this->gateway()->getPayment('../adjustments');
            $this->fail('accepted a path');
        } catch (GatewayError $e) {
            $this->assertSame('INVALID_ID', $e->gatewayCode);
        }
        $this->assertSame([], $this->wire->sent);
    }

    public function test_the_key_decides_the_host(): void
    {
        $this->assertSame(['sandbox-api.paddle.com', 'api.paddle.com'], [Paddle::SANDBOX_HOST, Paddle::LIVE_HOST]);
        $id = 'txn_'.str_repeat('a', 26);
        foreach (['pdl_sdbx_apikey_x' => Paddle::SANDBOX_HOST, 'pdl_live_apikey_x' => Paddle::LIVE_HOST] as $key => $host) {
            $wire = new StubTransport(404, ['error' => ['type' => 'request_error', 'code' => 'not_found', 'detail' => 'x']]);
            try {
                (new Paddle($key, 'pdl_ntfset_x', transport: $wire))->getPayment($id);
            } catch (GatewayError) {
            }
            $this->assertSame("https://{$host}/transactions/{$id}", $wire->sent[0]['url']);
        }
    }

    public function test_refuses_a_key_that_says_neither_sandbox_nor_live_unless_a_base_url_is_given(): void
    {
        new Paddle('sk_test_x', 'pdl_ntfset_x', baseUrl: 'http://127.0.0.1:1');
        $this->expectException(GatewayError::class);
        new Paddle('sk_test_x', 'pdl_ntfset_x');
    }

    public function test_verifies_with_the_configured_tolerance_and_clock(): void
    {
        $id = 'txn_'.str_repeat('a', 26);
        $body = (string) json_encode(['event_type' => 'transaction.completed', 'data' => ['id' => $id, 'status' => 'completed', 'custom_data' => ['reference' => 'R1']]]);
        $ts = 1_700_000_000;
        $cb = new CallbackInput([], $body, ['paddle-signature' => "ts={$ts};h1=".hash_hmac('sha256', "{$ts}:{$body}", 'pdl_ntfset_x')]);
        $at = static fn (int $s) => new Paddle('pdl_sdbx_apikey_x', 'pdl_ntfset_x', toleranceSeconds: 60, now: static fn (): int => $s);
        $ok = $at($ts + 60)->verifyCallback($cb);
        $this->assertSame([$id, 'R1', PaymentStatus::Paid], [$ok?->id, $ok?->reference, $ok?->claimedStatus]);
        $this->assertNull($at($ts + 61)->verifyCallback($cb));
    }
}

/** @internal passes requests through and keeps a copy */
final class RecordingTransport implements Transport
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    public array $sent = [];

    public function __construct(private readonly Transport $inner) {}

    public function send(string $method, string $url, array $headers, ?string $body, int $timeoutMs): Response
    {
        $this->sent[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

        return $this->inner->send($method, $url, $headers, $body, $timeoutMs);
    }

    /** @return array{method: string, url: string, headers: array<string, string>, body: ?string} */
    public function last(): array
    {
        return $this->sent[array_key_last($this->sent)] ?? throw new \RuntimeException('nothing sent');
    }
}

/** @internal answers every request with the same JSON (and records it) */
final class StubTransport implements Transport
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    public array $sent = [];

    /** @param array<mixed> $json */
    public function __construct(private readonly int $status, private readonly array $json) {}

    public function send(string $method, string $url, array $headers, ?string $body, int $timeoutMs): Response
    {
        $this->sent[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        if ($method === 'POST' && str_ends_with($url, '/adjustments')) {
            return new Response(201, (string) json_encode(['data' => ['id' => 'adj_1', 'status' => 'pending_approval', 'transaction_id' => 'x', 'currency_code' => 'USD', 'totals' => ['total' => '750']]]));
        }

        return new Response($this->status, (string) json_encode($this->json));
    }

    /** @return array{method: string, url: string, headers: array<string, string>, body: ?string} */
    public function last(): array
    {
        return $this->sent[array_key_last($this->sent)] ?? throw new \RuntimeException('nothing sent');
    }
}
