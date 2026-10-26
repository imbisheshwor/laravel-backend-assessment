<?php

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_decimal_arithmetic_does_not_round_using_floats(): void
    {
        $this->assertSame('0.30', Money::decimal(Money::cents('0.10') * 3));
        $this->assertSame(9999999999, Money::cents('99999999.99'));
        $this->assertSame('99999999990.00', Money::decimal(Money::cents('99999999.99') * 1000));
        $this->assertSame('1.20', Money::decimal(Money::cents('1.2')));
    }

    public function test_invalid_money_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::cents('1.001');
    }
}
