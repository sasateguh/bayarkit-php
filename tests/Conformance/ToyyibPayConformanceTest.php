<?php

declare(strict_types=1);

namespace Bayarkit\Tests\Conformance;

use Bayarkit\Gateway;
use Bayarkit\GatewayError;
use Bayarkit\PaymentStatus;
use Bayarkit\Gateways\ToyyibPay;
use Bayarkit\Tests\Support\Fakes;

final class ToyyibPayConformanceTest extends GatewayConformance
{
    protected function fake(): string
    {
        return 'toyyibpay';
    }

    protected function gateway(): Gateway
    {
        $f = Fakes::get('toyyibpay');

        return new ToyyibPay($f['options']['secretKey'], $f['options']['categoryCode'], baseUrl: $f['url']);
    }

    public function test_deactivates_once_then_reports_already_inactive(): void
    {
        $g = $this->gateway();
        $this->assertInstanceOf(ToyyibPay::class, $g);
        $created = $g->createPayment($this->input());
        $this->assertFalse($g->deactivate($created->id));
        $this->assertTrue($g->deactivate($created->id));
    }

    public function test_another_accounts_key_never_reads_a_paid_bill_as_paid(): void
    {
        $f = Fakes::get('toyyibpay');
        $created = $this->gateway()->createPayment($this->input());
        Fakes::markPaid('toyyibpay', $created->id);
        $this->assertSame(PaymentStatus::Paid, $this->gateway()->getPayment($created->id)->status);
        $this->assertNotNull($this->gateway()->getPayment($created->id)->fee); // owner's key: the fee is returned
        $other = new ToyyibPay('someone-else', $f['options']['categoryCode'], baseUrl: $f['url']);
        $this->assertSame(PaymentStatus::Pending, $other->getPayment($created->id)->status);
    }

    public function test_checks_the_connection_without_creating_a_bill(): void
    {
        $g = $this->gateway();
        $this->assertInstanceOf(ToyyibPay::class, $g);
        $this->assertNotSame('', $g->checkConnection());
    }

    public function test_a_wrong_secret_key_quotes_the_gateway_but_never_the_key(): void
    {
        $f = Fakes::get('toyyibpay');
        $g = new ToyyibPay('WRONG-SECRET-123', $f['options']['categoryCode'], baseUrl: $f['url']);
        try {
            $g->createPayment($this->input());
            $this->fail('expected a GatewayError');
        } catch (GatewayError $e) {
            $this->assertStringNotContainsString('WRONG-SECRET-123', $e->getMessage());
            $this->assertSame('toyyibpay', $e->gateway);
        }
    }
}
