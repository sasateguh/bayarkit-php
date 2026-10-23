<?php

declare(strict_types=1);

namespace Bayarkit\Tests\Unit;

use Bayarkit\Money;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function test_converts_without_floating_point_drift(): void
    {
        $this->assertSame('10.50', Money::toRinggit(1050));
        $this->assertSame('0.05', Money::toRinggit(5));
        $this->assertSame('-1.00', Money::toRinggit(-100));
        $this->assertSame(1050, Money::toSen('10.5'));
        $this->assertSame(1050, Money::toSen('10.50'));
        $this->assertSame(29, Money::toSen(0.29));
        $this->assertSame(29, Money::toSen('0.29'));
        $this->assertSame(1000, Money::toSen(10));
        $this->assertSame(1051, Money::toSen('10.505'));
        $this->assertSame(1050, Money::toSen(' 10.50 '));
    }

    public function test_refuses_anything_that_is_not_a_plain_number(): void
    {
        foreach (['', 'abc', '1,050.00', 'RM10', '10.5.1', null, [], true] as $bad) {
            $this->assertNull(Money::toSen($bad), var_export($bad, true));
        }
        $this->assertNull(Money::toSen(NAN));
    }
}
