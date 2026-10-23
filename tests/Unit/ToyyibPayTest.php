<?php

declare(strict_types=1);

namespace Bayarkit\Tests\Unit;

use Bayarkit\CallbackInput;
use Bayarkit\Gateways\ToyyibPay;
use Bayarkit\PaymentStatus;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/** Same vectors as packages/toyyibpay/test/toyyibpay.test.ts. */
final class ToyyibPayTest extends TestCase
{
    public function test_cleans_bill_text_to_what_toyyibpay_accepts(): void
    {
        $this->assertSame('Panel Solar 550W x2 RM1 050', ToyyibPay::clean('Panel Solar 550W (x2) — RM1,050!', 30));
        $this->assertSame(30, strlen(ToyyibPay::clean(str_repeat('a', 50), 30)));
    }

    public function test_round_trips_malaysia_time_and_rejects_impossible_dates(): void
    {
        $d = new DateTimeImmutable('2026-03-02T08:24:25Z');
        $this->assertSame('02-03-2026 16:24:25', ToyyibPay::malaysiaTime($d));
        $this->assertSame($d->getTimestamp(), ToyyibPay::parseMalaysiaTime('02-03-2026 16:24:25')?->getTimestamp());
        $this->assertNull(ToyyibPay::parseMalaysiaTime('31-02-2026 10:00:00'));
        $this->assertNull(ToyyibPay::parseMalaysiaTime('2026-03-02 16:24:25'));
    }

    public function test_verifies_the_documented_callback_hash(): void
    {
        $g = new ToyyibPay('secret', 'cat', baseUrl: 'http://127.0.0.1:9');
        $fields = ['billcode' => 'abc123', 'order_id' => 'ORD1', 'status' => '1', 'refno' => 'TP1', 'hash' => md5('secret1ORD1TP1ok')];
        $cb = $g->verifyCallback(new CallbackInput($fields));
        $this->assertNotNull($cb);
        $this->assertSame('abc123', $cb->id);
        $this->assertSame('ORD1', $cb->reference);
        $this->assertSame(PaymentStatus::Paid, $cb->claimedStatus);
        $this->assertNull($g->verifyCallback(new CallbackInput(['hash' => md5('other1ORD1TP1ok')] + $fields)));
        $this->assertNull($g->verifyCallback(new CallbackInput(['status' => '3'] + $fields)));
    }
}
