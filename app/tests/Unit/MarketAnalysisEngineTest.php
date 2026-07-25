<?php

namespace Tests\Unit;

use App\Engines\Market\MarketAnalysisEngine;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Services\IndexCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketAnalysisEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_strong_uptrend_produces_bullish_sentiment_and_phase(): void
    {
        $benchmark = app(IndexCatalogService::class)->primaryBenchmarkStock();
        $this->seedSmoothUptrend($benchmark, bars: 220, start: 100.0, step: 0.8);

        $payload = app(MarketAnalysisEngine::class)->analyze($benchmark);

        $this->assertTrue($payload['available'] ?? false);
        $this->assertArrayHasKey('sentiment', $payload);
        $this->assertGreaterThanOrEqual(60, (float) $payload['sentiment']['score']);
        $this->assertContains($payload['market_phase'], [
            MarketAnalysisEngine::PHASE_STRONG_BULL,
            MarketAnalysisEngine::PHASE_BULL,
            MarketAnalysisEngine::PHASE_PULLBACK,
            MarketAnalysisEngine::PHASE_CONSOLIDATION,
            MarketAnalysisEngine::PHASE_RECOVERY,
        ]);
        $this->assertNotEmpty($payload['explainability']['reasons'] ?? []);
        $this->assertNotEmpty($payload['explainability']['phase_rule'] ?? null);
        $this->assertSame('market_analysis_engine', $payload['owner']);
    }

    public function test_latest_persists_and_is_deterministic_for_same_bars(): void
    {
        $benchmark = app(IndexCatalogService::class)->primaryBenchmarkStock();
        $this->seedSmoothUptrend($benchmark, bars: 220, start: 100.0, step: 0.5);

        $engine = app(MarketAnalysisEngine::class);
        $a = $engine->analyze($benchmark);
        $b = $engine->analyze($benchmark);

        $this->assertSame($a['market_phase'], $b['market_phase']);
        $this->assertSame($a['sentiment']['score'], $b['sentiment']['score']);
        $this->assertSame($a['trend']['label'], $b['trend']['label']);

        $engine->latest(forceRefresh: true);
        $this->assertDatabaseHas('portfolio_tos_market_analytics', [
            'benchmark_stock_id' => $benchmark->id,
            'market_phase' => $a['market_phase'],
        ]);
    }

    public function test_insufficient_bars_returns_unavailable_payload(): void
    {
        $benchmark = app(IndexCatalogService::class)->primaryBenchmarkStock();
        $this->seedSmoothUptrend($benchmark, bars: 10, start: 100.0, step: 1.0);

        $payload = app(MarketAnalysisEngine::class)->analyze($benchmark);

        $this->assertFalse($payload['available'] ?? true);
        $this->assertSame(50.0, (float) $payload['sentiment']['score']);
    }

    protected function seedSmoothUptrend(Stock $stock, int $bars, float $start, float $step): void
    {
        StockPrice::query()->where('stock_id', $stock->id)->delete();

        for ($i = 0; $i < $bars; $i++) {
            $close = $start + ($i * $step);
            StockPrice::query()->create([
                'stock_id' => $stock->id,
                'price_date' => now()->subDays($bars - $i)->toDateString(),
                'open_price' => $close - 0.4,
                'high_price' => $close + 0.8,
                'low_price' => $close - 0.8,
                'close_price' => $close,
                'volume' => 100000 + ($i * 100),
                'data_source' => 'test',
                'created_at' => now(),
            ]);
        }
    }
}
