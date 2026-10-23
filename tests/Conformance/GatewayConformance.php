<?php

declare(strict_types=1);

namespace Bayarkit\Tests\Conformance;

use Bayarkit\CallbackInput;
use Bayarkit\Confirm;
use Bayarkit\ConfirmResult;
use Bayarkit\CreatePaymentInput;
use Bayarkit\Customer;
use Bayarkit\Expected;
use Bayarkit\Gateway;
use Bayarkit\GatewayError;
use Bayarkit\PaymentStatus;
use Bayarkit\Tests\Support\Fakes;
use PHPUnit\Framework\TestCase;

/**
 * The PHP twin of `gatewayConformance` in @bayarkit/testing: the same cases, against the same fakes. A PHP adapter
 * and its TypeScript twin therefore agree on every rule that decides "paid".
 */
abstract class GatewayConformance extends TestCase
{
    private static int $counter = 0;

    abstract protected function fake(): string;

    abstract protected function gateway(): Gateway;

    protected function input(?int $amount = null, ?string $reference = null): CreatePaymentInput
    {
        self::$counter++;

        return new CreatePaymentInput(
            reference: $reference ?? 'ORDER'.time().self::$counter,
            amount: $amount ?? 1050,
            description: 'Conformance test order',
            returnUrl: 'https://shop.example/return',
            callbackUrl: 'https://shop.example/callback',
            customer: new Customer('Ali bin Abu', 'ali@example.com', '0123456789'),
        );
    }

    private function deliveredCallback(string $id, bool $tampered = false): CallbackInput
    {
        return Fakes::callbackFor($this->fake(), $id, $tampered);
    }

    public function test_declares_where_its_behaviour_comes_from(): void
    {
        $info = $this->gateway()->info();
        $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $info->name);
        $this->assertSame($this->fake(), $info->name);
        $this->assertGreaterThan(0, $info->minimumAmount);
        $this->assertContains($info->evidence, ['measured', 'docs']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $info->evidenceDate);
    }

    public function test_creates_a_payment_with_an_id_and_a_redirect_url(): void
    {
        $created = $this->gateway()->createPayment($this->input());
        $this->assertNotSame('', $created->id);
        $this->assertMatchesRegularExpression('#^https?://#', $created->url);
    }

    public function test_rejects_an_amount_below_its_minimum_before_calling_the_gateway(): void
    {
        $this->expectException(GatewayError::class);
        $this->gateway()->createPayment($this->input($this->gateway()->info()->minimumAmount - 1));
    }

    public function test_reports_an_unpaid_payment_as_not_paid(): void
    {
        $created = $this->gateway()->createPayment($this->input());
        $cb = $this->gateway()->verifyCallback($this->deliveredCallback($created->id));
        $this->assertNotNull($cb);
        $this->assertNotSame(PaymentStatus::Paid, $this->gateway()->getPayment($cb->id)->status);
    }

    public function test_confirms_a_paid_payment_from_its_callback(): void
    {
        $input = $this->input();
        $created = $this->gateway()->createPayment($input);
        Fakes::markPaid($this->fake(), $created->id);
        $result = Confirm::payment($this->gateway(), $this->deliveredCallback($created->id), new Expected($input->reference, $input->amount));
        $this->assertTrue($result->ok, $result->detail);
        $this->assertSame($input->reference, $result->payment?->reference);
        $this->assertSame($input->amount, $result->payment?->amount);
        $this->assertSame('MYR', $result->payment?->currency);
    }

    public function test_refuses_a_callback_whose_signature_was_tampered_with(): void
    {
        $input = $this->input();
        $created = $this->gateway()->createPayment($input);
        Fakes::markPaid($this->fake(), $created->id);
        $result = Confirm::payment($this->gateway(), $this->deliveredCallback($created->id, true), new Expected($input->reference, $input->amount));
        $this->assertFalse($result->ok);
        $this->assertSame(ConfirmResult::INVALID_SIGNATURE, $result->reason);
    }

    public function test_refuses_a_correctly_signed_callback_when_the_gateway_says_it_is_not_paid(): void
    {
        $input = $this->input();
        $created = $this->gateway()->createPayment($input);
        $result = Confirm::payment($this->gateway(), $this->deliveredCallback($created->id), new Expected($input->reference, $input->amount));
        $this->assertFalse($result->ok);
        $this->assertSame(ConfirmResult::NOT_PAID, $result->reason);
    }

    public function test_refuses_a_paid_payment_whose_amount_differs_from_what_you_charged(): void
    {
        $input = $this->input();
        $created = $this->gateway()->createPayment($input);
        Fakes::markPaid($this->fake(), $created->id);
        $result = Confirm::payment($this->gateway(), $this->deliveredCallback($created->id), new Expected($input->reference, $input->amount + 1));
        $this->assertSame(ConfirmResult::MISMATCH, $result->reason);
    }

    public function test_refuses_a_paid_payment_that_belongs_to_a_different_order(): void
    {
        $input = $this->input();
        $created = $this->gateway()->createPayment($input);
        Fakes::markPaid($this->fake(), $created->id);
        $result = Confirm::payment($this->gateway(), $this->deliveredCallback($created->id), new Expected('SOMEONE-ELSE', $input->amount));
        $this->assertSame(ConfirmResult::MISMATCH, $result->reason);
    }

    public function test_never_throws_on_a_malformed_callback_body(): void
    {
        $junk = [
            new CallbackInput,
            new CallbackInput(['hash' => 'x'], '{', ['content-type' => 'application/json']),
            new CallbackInput(['checksum' => 'zz', 'transaction_id' => '1']),
            new CallbackInput(['billcode' => '../../etc'], 'not json', ['x-signature' => '!!', 'stripe-signature' => 't=1,v1=zz']),
            new CallbackInput(['billcode' => 'X', 'hash' => str_repeat('a', 32)], '', ['x-signature' => base64_encode('nope')]),
        ];
        foreach ($junk as $cb) {
            $this->assertNull($this->gateway()->verifyCallback($cb));
        }
    }

    public function test_confirming_the_same_callback_twice_gives_the_same_answer(): void
    {
        $input = $this->input();
        $created = $this->gateway()->createPayment($input);
        Fakes::markPaid($this->fake(), $created->id);
        $body = $this->deliveredCallback($created->id);
        $expected = new Expected($input->reference, $input->amount);
        $first = Confirm::payment($this->gateway(), $body, $expected);
        $second = Confirm::payment($this->gateway(), $body, $expected);
        $this->assertTrue($first->ok);
        $this->assertEquals($first, $second);
    }
}
