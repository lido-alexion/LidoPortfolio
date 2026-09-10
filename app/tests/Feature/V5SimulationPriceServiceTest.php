<?php

namespace Tests\Feature;

use App\Models\Stock;
use App\Models\StockPrice;
use App\Services\Simulation\SimulationPriceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class V5SimulationPriceServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_pinned_ohlc_methods_and_adverse_slippage_are_reproducible(): void
    {
        $stock = Stock::query()->create(['symbol' => 'SIM', 'exchange' => 'NSE', 'name' => 'Simulation']);
        StockPrice::query()->create([
            'stock_id' => $stock->id, 'price_date' => '2026-01-02',
            'open_price' => 100, 'high_price' => 120, 'low_price' => 90, 'close_price' => 110,
            'data_source' => 'test-feed', 'created_at' => '2026-01-02 18:00:00',
        ]);
        $prices = app(SimulationPriceService::class);

        $this->assertSame(100.0, $prices->resolve($stock->id, '2026-01-02', 'next_open')['execution_price']);
        $this->assertSame(110.0, $prices->resolve($stock->id, '2026-01-02', 'next_close')['execution_price']);
        $this->assertSame(105.0, $prices->resolve($stock->id, '2026-01-02', 'ohlc_average')['execution_price']);
        $this->assertSame(105.0, $prices->resolve($stock->id, '2026-01-02', 'high_low_midpoint')['execution_price']);
        $this->assertSame(101.0, $prices->resolve($stock->id, '2026-01-02', 'next_open', 'buy', 1)['execution_price']);
        $sell = $prices->resolve($stock->id, '2026-01-02', 'next_open', 'sell', 1);
        $this->assertSame(99.0, $sell['execution_price']);
        $this->assertSame('test-feed', $sell['source']['provider']);
        $this->assertNotEmpty($sell['source']['fingerprint']);
    }

    public function test_missing_exact_session_or_required_observation_blocks_without_future_leakage(): void
    {
        $stock = Stock::query()->create(['symbol' => 'GAP', 'exchange' => 'NSE', 'name' => 'Gap']);
        StockPrice::query()->create([
            'stock_id' => $stock->id, 'price_date' => '2026-01-03',
            'open_price' => 100, 'close_price' => 110, 'data_source' => 'future', 'created_at' => now(),
        ]);
        $prices = app(SimulationPriceService::class);

        $this->assertSame('blocked', $prices->resolve($stock->id, '2026-01-02', 'next_open')['status']);
        $result = $prices->resolve($stock->id, '2026-01-03', 'ohlc_average');
        $this->assertSame('blocked', $result['status']);
        $this->assertContains('missing_high_price', $result['limitations']);
    }
}
