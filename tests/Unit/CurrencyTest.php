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
use Bayarkit\GatewayInfo;
use Bayarkit\PaymentRecord;
use Bayarkit\PaymentStatus;
use Bayarkit\VerifiedCallback;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** The same rule as the TypeScript twin: an order without a currency is compared against the gateway's own. */
final class CurrencyTest extends TestCase
{
    private function gateway(string $charges, ?string $declares): Gateway
    {
        return new class($charges, $declares) implements Gateway
        {
            public function __construct(private string $charges, private ?string $declares) {}

            public function info(): GatewayInfo
            {
                return $this->declares === null
                    ? new GatewayInfo('stub', 100, 'docs', '2026-10-29', 'test')
                    : new GatewayInfo('stub', 100, 'docs', '2026-10-29', 'test', $this->declares);
            }

            public function createPayment(CreatePaymentInput $input): CreatedPayment
            {
                throw new RuntimeException('unused');
            }

            public function getPayment(string $id): PaymentRecord
            {
                return new PaymentRecord($id, 'R1', PaymentStatus::Paid, 500, $this->charges, raw: []);
            }

            public function verifyCallback(CallbackInput $callback): ?VerifiedCallback
            {
                return new VerifiedCallback('x', 'R1', PaymentStatus::Paid, []);
            }
        };
    }

    public function test_an_order_without_a_currency_is_compared_against_the_gateways_own(): void
    {
        $cb = new CallbackInput;
        $this->assertTrue(Confirm::payment($this->gateway('USD', 'USD'), $cb, new Expected('R1', 500))->ok);
        $this->assertSame(ConfirmResult::MISMATCH, Confirm::payment($this->gateway('MYR', 'USD'), $cb, new Expected('R1', 500))->reason);
    }

    public function test_the_orders_currency_wins_and_no_declared_currency_means_myr(): void
    {
        $cb = new CallbackInput;
        $this->assertSame(ConfirmResult::MISMATCH, Confirm::payment($this->gateway('USD', 'USD'), $cb, new Expected('R1', 500, 'EUR'))->reason);
        $this->assertTrue(Confirm::payment($this->gateway('EUR', 'USD'), $cb, new Expected('R1', 500, 'EUR'))->ok);
        $this->assertSame(ConfirmResult::MISMATCH, Confirm::payment($this->gateway('USD', null), $cb, new Expected('R1', 500))->reason);
        $this->assertTrue(Confirm::payment($this->gateway('MYR', null), $cb, new Expected('R1', 500))->ok);
        $this->assertSame('MYR', (new GatewayInfo('stub', 100, 'docs', '2026-10-29', 'test'))->currency);
    }
}
