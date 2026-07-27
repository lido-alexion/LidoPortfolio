<?php

namespace Tests\Unit;

use App\Services\Analytics\MarketDepthService;
use Tests\TestCase;

class MarketDepthServiceTest extends TestCase
{
    public function test_evaluate_stock_flags_above_sma_and_rs(): void
    {
        $svc = app(MarketDepthService::class);

        // 60 closes: rising stock ending at 160; flat bench at 100
        $closes = [];
        for ($i = 0; $i < 60; $i++) {
            $closes[] = 100.0 + $i;
        }
        $bench = array_fill(0, 60, 100.0);

        $flags = $svc->evaluateStock($closes, $bench, [20, 50], 55);

        $this->assertTrue($flags['above_sma_20']);
        $this->assertTrue($flags['above_sma_50']);
        $this->assertTrue($flags['rs_55_positive']);
    }

    public function test_evaluate_stock_rs_negative_when_underperforming_bench(): void
    {
        $svc = app(MarketDepthService::class);

        $closes = array_fill(0, 60, 100.0);
        $bench = [];
        for ($i = 0; $i < 60; $i++) {
            $bench[] = 100.0 + $i;
        }

        $flags = $svc->evaluateStock($closes, $bench, [20], 55);

        $this->assertFalse($flags['rs_55_positive']);
    }

    public function test_evaluate_stock_returns_null_when_insufficient_history(): void
    {
        $svc = app(MarketDepthService::class);
        $flags = $svc->evaluateStock([10.0, 11.0], [10.0, 11.0], [20, 50], 55);

        $this->assertNull($flags['above_sma_20']);
        $this->assertNull($flags['above_sma_50']);
        $this->assertNull($flags['rs_55_positive']);
    }

    public function test_pct_rounds_and_handles_empty(): void
    {
        $svc = app(MarketDepthService::class);

        $this->assertNull($svc->pct(0, 0));
        $this->assertSame(50, $svc->pct(1, 2));
        $this->assertSame(48, $svc->pct(24, 50));
    }
}
