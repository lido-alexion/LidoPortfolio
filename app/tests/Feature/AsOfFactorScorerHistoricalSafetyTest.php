<?php

namespace Tests\Feature;

use App\Engines\Market\MarketAnalysisEngine;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Services\Backtest\AsOfFactorScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AsOfFactorScorerHistoricalSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_does_not_call_live_market_analysis_and_stubs_regime_at_50(): void
    {
        $this->mock(MarketAnalysisEngine::class, function ($mock): void {
            $mock->shouldReceive('latest')->never();
            $mock->shouldReceive('analyze')->never();
        });

        $stock = Stock::query()->create([
            'symbol' => 'H'.strtoupper(Str::random(4)),
            'exchange' => 'NSE',
            'name' => 'Historical Score Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);
        $n = 80;
        for ($i = 0; $i < $n; $i++) {
            $close = 50.0 + $i;
            StockPrice::query()->create([
                'stock_id' => $stock->id,
                'price_date' => now()->subDays($n - $i)->toDateString(),
                'open_price' => $close,
                'high_price' => $close + 1,
                'low_price' => $close - 1,
                'close_price' => $close,
                'volume' => 10000,
                'data_source' => 'test',
            ]);
        }

        $asOf = now()->subDay()->toDateString();
        $result = app(AsOfFactorScorer::class)->score($stock->id, $asOf, ['min_bars' => 15]);

        $this->assertFalse($result['skipped']);
        $this->assertEqualsWithDelta(50.0, (float) $result['indicator_scores']['market_regime'], 0.0001);
        $this->assertEqualsWithDelta(50.0, (float) $result['indicator_scores']['sector_strength'], 0.0001);
        $this->assertEqualsWithDelta(50.0, (float) $result['indicator_scores']['breakout_score'], 0.0001);
        $this->assertArrayNotHasKey('market_regime_score', $result);
    }
}
