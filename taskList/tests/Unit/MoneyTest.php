<?php

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use Tests\TestCase;

class MoneyTest extends TestCase
{
    public function test_from_major_string_parses_to_minor_units(): void
    {
        $this->assertSame(1050, Money::fromMajor('10.50', 'GHS')->minor);
        $this->assertSame(1000, Money::fromMajor('10', 'GHS')->minor);
        $this->assertSame(5, Money::fromMajor('0.05', 'GHS')->minor);
    }

    public function test_to_major_string_formats_from_minor_units(): void
    {
        $this->assertSame('10.50', Money::of(1050, 'GHS')->toMajorString());
        $this->assertSame('0.05', Money::of(5, 'GHS')->toMajorString());
        // UGX has no minor subdivision (factor 1)
        $this->assertSame('1500', Money::of(1500, 'UGX')->toMajorString());
    }

    public function test_fee_at_bps_rounds_half_up(): void
    {
        // 1.5% of 1050 = 15.75 -> 16
        $this->assertSame(16, Money::of(1050, 'GHS')->feeAtBps(150)->minor);
        $this->assertSame(0, Money::of(1, 'GHS')->feeAtBps(150)->minor);
    }

    public function test_arithmetic_and_currency_guard(): void
    {
        $this->assertSame(300, Money::of(100, 'GHS')->add(Money::of(200, 'GHS'))->minor);

        $this->expectException(InvalidArgumentException::class);
        Money::of(100, 'GHS')->add(Money::of(200, 'KES'));
    }

    public function test_rejects_unsupported_currency(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Money(100, 'XYZ');
    }
}
