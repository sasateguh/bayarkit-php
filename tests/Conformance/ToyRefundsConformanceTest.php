<?php

declare(strict_types=1);

namespace Bayarkit\Tests\Conformance;

use Bayarkit\CallbackInput;
use Bayarkit\CreatedPayment;
use Bayarkit\CreatePaymentInput;
use Bayarkit\Gateway;
use Bayarkit\GatewayError;
use Bayarkit\GatewayInfo;
use Bayarkit\PaymentRecord;
use Bayarkit\PaymentStatus;
use Bayarkit\RefundInput;
use Bayarkit\RefundRecord;
use Bayarkit\Refunds;
use Bayarkit\RefundStatus;
use Bayarkit\VerifiedCallback;

/**
 * The optional refund cases of the suite, run against an in-memory toy (the twin of the toy in
 * packages/testing/test/conformance-optional.test.ts), so they are exercised before any real adapter refunds.
 */
final class ToyRefundsConformanceTest extends GatewayConformance
{
    private static ?ToyRefundsGateway $toy = null;

    protected function fake(): string
    {
        return 'toy';
    }

    protected function gateway(): Gateway
    {
        return self::$toy ??= new ToyRefundsGateway;
    }

    protected function markPaid(string $id): void
    {
        self::$toy?->markPaid($id);
    }

    protected function deliveredCallback(string $id, bool $tampered = false): CallbackInput
    {
        $body = (string) json_encode(['id' => $id, 'reference' => self::$toy?->reference($id) ?? '', 'status' => 'paid']);
        $signature = hash_hmac('sha256', $body, ToyRefundsGateway::KEY);

        return new CallbackInput([], $body, ['x-toy-signature' => $tampered ? strrev($signature) : $signature]);
    }

    public function test_a_second_refund_cannot_take_more_than_is_left(): void
    {
        $g = $this->gateway();
        $this->assertInstanceOf(Refunds::class, $g);
        $created = $g->createPayment($this->input(1000));
        $this->markPaid($created->id);
        $g->refundPayment(new RefundInput($created->id, 600));
        $this->expectExceptionMessage('toy: refund of 401 sen exceeds the 400 sen paid');
        $g->refundPayment(new RefundInput($created->id, 401));
    }
}

/** @internal test double only */
final class ToyRefundsGateway implements Refunds
{
    public const KEY = 'toy-signing-key';

    /** @var array<string, array{reference: string, amount: int, paid: bool, refunded: int}> */
    private array $payments = [];

    public function info(): GatewayInfo
    {
        return new GatewayInfo('toy', 100, 'docs', '2026-10-29', 'test');
    }

    public function createPayment(CreatePaymentInput $input): CreatedPayment
    {
        if ($input->amount < 100) {
            throw new GatewayError('bad amount', 'toy');
        }
        $id = 'toy_'.(count($this->payments) + 1);
        $this->payments[$id] = ['reference' => $input->reference, 'amount' => $input->amount, 'paid' => false, 'refunded' => 0];

        return new CreatedPayment($id, "https://pay.toy.example/{$id}");
    }

    public function getPayment(string $id): PaymentRecord
    {
        $p = $this->find($id);

        return new PaymentRecord($id, $p['reference'], $p['paid'] ? PaymentStatus::Paid : PaymentStatus::Pending, $p['amount'], 'MYR');
    }

    public function verifyCallback(CallbackInput $callback): ?VerifiedCallback
    {
        $got = $callback->headers['x-toy-signature'] ?? null;
        if (! is_string($got) || $callback->rawBody === '' || ! hash_equals(hash_hmac('sha256', $callback->rawBody, self::KEY), $got)) {
            return null;
        }
        $json = json_decode($callback->rawBody, true);
        if (! is_array($json) || ! is_string($json['id'] ?? null) || ! is_string($json['reference'] ?? null)) {
            return null;
        }

        return new VerifiedCallback($json['id'], $json['reference'], PaymentStatus::Paid, []);
    }

    public function refundPayment(RefundInput $input): RefundRecord
    {
        $p = $this->find($input->paymentId);
        if (! $p['paid']) {
            throw new GatewayError('payment is not paid', 'toy');
        }
        $input->check('toy', $p['amount'] - $p['refunded']);
        $this->payments[$input->paymentId]['refunded'] += $input->amount;

        return new RefundRecord('re_'.$input->paymentId.'_'.$this->payments[$input->paymentId]['refunded'], $input->paymentId, RefundStatus::Succeeded, $input->amount, 'MYR');
    }

    public function markPaid(string $id): void
    {
        $this->find($id);
        $this->payments[$id]['paid'] = true;
    }

    public function reference(string $id): string
    {
        return $this->find($id)['reference'];
    }

    /** @return array{reference: string, amount: int, paid: bool, refunded: int} */
    private function find(string $id): array
    {
        return $this->payments[$id] ?? throw new GatewayError('no such payment', 'toy', 404);
    }
}
