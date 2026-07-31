<?php

namespace Tests\Unit\Backtest;

use App\Services\Backtest\BacktestMath;
use PHPUnit\Framework\TestCase;

class BacktestMathTest extends TestCase
{
    public function test_one_day_trade_cagr_is_null_not_exploded(): void
    {
        // ~3% in one day annualizes to millions of percent — must not be stored.
        $cagr = BacktestMath::cagrPercent(820.35, 845.0, 1);
        $this->assertNull($cagr);
    }

    public function test_cagr_for_long_hold_is_finite_and_clamped(): void
    {
        $cagr = BacktestMath::cagrPercent(100.0, 110.0, 365);
        $this->assertNotNull($cagr);
        $this->assertTrue(is_finite($cagr));
        $this->assertLessThan(BacktestMath::DECIMAL_12_6_MAX, abs($cagr));
        $this->assertEqualsWithDelta(10.0, $cagr, 0.5);
    }

    public function test_clamp_decimal_bounds(): void
    {
        $this->assertSame(BacktestMath::DECIMAL_12_6_MAX, BacktestMath::clampDecimal12_6(5_000_000));
        $this->assertSame(-BacktestMath::DECIMAL_12_6_MAX, BacktestMath::clampDecimal12_6(-5_000_000));
        $this->assertNull(BacktestMath::clampDecimal12_6(INF));
    }
}
