<?php

namespace Tests\Unit;

use App\Services\Analytics\MarketDepthService;
use Tests\TestCase;

class MarketDepthServiceTest extends TestCase
{
    public function test_evaluate_stock_flags_above_sma_rs_and_rising(): void
    {
        $svc = app(MarketDepthService::class);

        $closes = [];
        for ($i = 0; $i < 60; $i++) {
            $closes[] = 100.0 + $i;
        }
        $bench = array_fill(0, 60, 100.0);

        $flags = $svc->evaluateStock($closes, $bench, [20, 50], 55);

        $this->assertTrue($flags['rising']);
        $this->assertTrue($flags['above_sma_20']);
        $this->assertTrue($flags['above_sma_50']);
        $this->assertTrue($flags['rs_55_positive']);
    }

    public function test_evaluate_stock_rising_false_when_flat_or_down(): void
    {
        $svc = app(MarketDepthService::class);
        $closes = [100.0, 99.0];
        $flags = $svc->evaluateStock($closes, [100.0, 100.0], [20], 55);
        $this->assertFalse($flags['rising']);
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
        $flags = $svc->evaluateStock([10.0], [10.0], [20, 50], 55);

        $this->assertNull($flags['rising']);
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

    public function test_filter_matrix_by_exchange_keeps_matching_indexes(): void
    {
        $svc = app(MarketDepthService::class);
        $payload = [
            'rows' => [
                ['symbol' => 'NIFTY50', 'exchange' => 'NSE', 'name' => 'Nifty 50'],
                ['symbol' => 'SENSEX', 'exchange' => 'BSE', 'name' => 'Sensex'],
            ],
            'columns' => [],
        ];

        $nse = $svc->filterMatrixByExchange($payload, 'nse');
        $this->assertCount(1, $nse['rows']);
        $this->assertSame('NIFTY50', $nse['rows'][0]['symbol']);

        $bse = $svc->filterMatrixByExchange($payload, 'bse');
        $this->assertCount(1, $bse['rows']);
        $this->assertSame('SENSEX', $bse['rows'][0]['symbol']);
    }
}
