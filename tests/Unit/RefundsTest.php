<?php

declare(strict_types=1);

namespace Bayarkit\Tests\Unit;

use Bayarkit\CallbackInput;
use Bayarkit\Confirm;
use Bayarkit\ConfirmResult;
use Bayarkit\CreatedPayment;
use Bayarkit\CreatePaymentInput;
use Bayarkit\Expected;
use Bayarkit\Gateway;
use Bayarkit\GatewayError;
use Bayarkit\GatewayInfo;
use Bayarkit\PaymentRecord;
use Bayarkit\RefundInput;
use Bayarkit\RefundStatus;
use Bayarkit\VerifiedCallback;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** The core rules for refunds and for a verify call that fails, the same as in @bayarkit/core's tests. */
final class RefundsTest extends TestCase
{
    public function test_accepts_a_full_or_partial_refund(): void
    {
        (new RefundInput('p', 500))->check('stub', 500);
        (new RefundInput('p', 1))->check('stub', 500);
        $this->addToAssertionCount(2);
    }

    public function test_refuses_more_than_was_paid_zero_or_negative_sen_before_any_request(): void
    {
        foreach ([501 => 'stub: refund of 501 sen exceeds the 500 sen paid', 0 => null, -1 => null] as $amount => $message) {
            try {
                (new RefundInput('p', $amount))->check('stub', 500);
                $this->fail("accepted {$amount}");
            } catch (GatewayError $e) {
                $this->assertSame(0, $e->status);
                if ($message !== null) {
                    $this->assertSame($message, $e->getMessage());
                }
            }
        }
    }

    public function test_refuses_an_empty_payment_id(): void
    {
        $this->expectException(GatewayError::class);
        (new RefundInput('', 1))->check('stub', 500);
    }

    public function test_statuses_are_the_typescript_ones(): void
    {
        $this->assertSame(['pending', 'succeeded', 'failed', 'unknown'], array_map(fn (RefundStatus $s) => $s->value, RefundStatus::cases()));
    }

    public function test_a_verify_call_that_fails_is_a_gateway_error_quoting_the_gateway_only(): void
    {
        $gateway = new class implements Gateway
        {
            public ?\Throwable $throw = null;

            public function info(): GatewayInfo
            {
                return new GatewayInfo('stub', 100, 'docs', '2026-10-29', 'test');
            }

            public function createPayment(CreatePaymentInput $input): CreatedPayment
            {
                throw new RuntimeException('unused');
            }

            public function getPayment(string $id): PaymentRecord
            {
                throw new RuntimeException('must not be called');
            }

            public function verifyCallback(CallbackInput $callback): ?VerifiedCallback
            {
                throw $this->throw ?? new RuntimeException('unset');
            }
        };
        $gateway->throw = new GatewayError('unreachable (timeout)', 'stub');
        $r = Confirm::payment($gateway, new CallbackInput, new Expected('R1', 100));
        $this->assertSame(ConfirmResult::GATEWAY_ERROR, $r->reason);
        $this->assertSame('stub: unreachable (timeout)', $r->detail);
        $gateway->throw = new \TypeError('token=abc in a message');
        $r = Confirm::payment($gateway, new CallbackInput, new Expected('R1', 100));
        $this->assertSame('stub: verifyCallback threw TypeError', $r->detail);
    }
}
