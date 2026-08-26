<?php

namespace Tests\Feature;

use App\Engines\Evaluation\EvaluationEngine;
use App\Engines\Evaluation\EvaluationParameterResolver;
use App\Engines\Evaluation\MarketRegimeScoreMapper;
use App\Engines\Market\MarketAnalysisEngine;
use App\Engines\Pipeline\DailyDecisionPipeline;
use App\Models\Candidate;
use App\Models\DiscoveryRun;
use App\Models\EvaluationResult;
use App\Models\EvaluationRun;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\User;
use App\Models\WatchlistItem;
use App\Services\IndexCatalogService;
use App\Services\StrategyConfigurationService;
use App\Services\WatchlistService;
use App\Support\TradingOsConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\MarksDailyDatasetPublished;
use Tests\TestCase;

class EvaluationMarketRegimeTest extends TestCase
{
    use MarksDailyDatasetPublished;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            TradingOsConfig::KEY_ENABLED => true,
            TradingOsConfig::KEY_EVALUATION.'.min_bars' => 15,
            TradingOsConfig::KEY_NOTIFICATION.'.notify_on_generate' => false,
            TradingOsConfig::KEY_DISCOVERY.'.include_screener_hits' => false,
            TradingOsConfig::KEY_DISCOVERY.'.include_patterns' => false,
        ]);
    }

    public function test_bullish_regime_scores_100(): void
    {
        $this->assertEvaluationMapsRegime('Bullish', 100.0);
    }

    public function test_neutral_regime_scores_50(): void
    {
        $this->assertEvaluationMapsRegime('Neutral', 50.0);
    }

    public function test_bearish_regime_scores_0(): void
    {
        $this->assertEvaluationMapsRegime('Bearish', 0.0);
    }

    public function test_unavailable_market_analysis_uses_neutral_50(): void
    {
        $this->mock(MarketAnalysisEngine::class, function ($mock): void {
            $mock->shouldReceive('latest')->once()->andReturn([
                'available' => false,
                'market_phase' => 'Consolidation',
                'market_regime' => 'Neutral',
                'sentiment' => ['score' => 50, 'label' => 'Neutral'],
            ]);
        });

        [$profile, $stock] = $this->seedEvaluableStock();
        $discoveryRun = $this->discoveryWithCandidate($profile, $stock);
        $result = app(EvaluationEngine::class)->run($profile, $discoveryRun);
        $evidence = $result['results'][0]->evidence;

        $this->assertFalse((bool) ($evidence['skipped'] ?? false));
        $this->assertSame('Neutral', $evidence['market_regime']);
        $this->assertEqualsWithDelta(50.0, (float) $evidence['market_regime_score'], 0.0001);
        $this->assertEqualsWithDelta(50.0, (float) $evidence['indicator_scores']['market_regime'], 0.0001);
    }

    public function test_does_not_use_sentiment_as_regime_score(): void
    {
        $this->mock(MarketAnalysisEngine::class, function ($mock): void {
            $mock->shouldReceive('latest')->once()->andReturn([
                'available' => true,
                'market_phase' => 'Bear',
                'market_regime' => 'Bearish',
                'sentiment' => ['score' => 82, 'label' => 'Greed'],
            ]);
        });

        [$profile, $stock] = $this->seedEvaluableStock();
        $discoveryRun = $this->discoveryWithCandidate($profile, $stock);
        $evidence = app(EvaluationEngine::class)->run($profile, $discoveryRun)['results'][0]->evidence;

        $this->assertSame('Bearish', $evidence['market_regime']);
        $this->assertEqualsWithDelta(0.0, (float) $evidence['market_regime_score'], 0.0001);
        $this->assertNotEquals(82.0, (float) $evidence['indicator_scores']['market_regime']);
    }

    public function test_other_factor_scores_do_not_change_with_regime(): void
    {
        [$profile, $stock] = $this->seedEvaluableStock();
        $discoveryRun = $this->discoveryWithCandidate($profile, $stock);

        $bullish = $this->evaluateWithRegime($profile, $discoveryRun, 'Bullish');
        $bearish = $this->evaluateWithRegime($profile, $discoveryRun, 'Bearish');

        foreach (['relative_strength', 'momentum_score', 'trend_score', 'breakout_score', 'volume_score', 'sector_strength', 'risk_score'] as $key) {
            $this->assertEqualsWithDelta(
                (float) $bullish['indicator_scores'][$key],
                (float) $bearish['indicator_scores'][$key],
                0.0001,
                $key,
            );
        }
        $this->assertEqualsWithDelta(100.0, (float) $bullish['indicator_scores']['market_regime'], 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $bearish['indicator_scores']['market_regime'], 0.0001);
    }

    public function test_evaluation_and_strategy_weights_unchanged(): void
    {
        $evalWeights = TradingOsConfig::evaluation()['weights'];
        $this->assertSame([
            'trend' => 0.30,
            'momentum' => 0.25,
            'relative_strength' => 0.25,
            'volume' => 0.10,
            'pattern_bonus' => 0.10,
        ], $evalWeights);

        $config = app(StrategyConfigurationService::class)->normalizeConfig([]);
        $regime = collect($config['indicators'])->firstWhere('key', 'market_regime');
        $this->assertNotNull($regime);
        $this->assertEqualsWithDelta(5.0, (float) $regime['weight'], 0.01);
        $this->assertEqualsWithDelta(60.0, (float) $regime['minimum'], 0.01);
    }

    public function test_feat_021_parameter_overrides_still_apply_with_regime_wiring(): void
    {
        $this->mockMarketRegime('Neutral');
        [$profile, $stock] = $this->seedEvaluableStock();
        $discoveryRun = $this->discoveryWithCandidate($profile, $stock);
        $globals = app(EvaluationParameterResolver::class)->globals();
        $override = array_merge($globals, [
            'rsi_period' => 5,
            'sma_fast' => 8,
            'sma_slow' => 21,
        ]);

        $evidence = app(EvaluationEngine::class)->run($profile, $discoveryRun, $override)['results'][0]->evidence;

        $this->assertSame(5, $evidence['evaluation_parameters']['rsi_period']);
        $this->assertSame(8, $evidence['evaluation_parameters']['sma_fast']);
        $this->assertSame(21, $evidence['evaluation_parameters']['sma_slow']);
        $this->assertSame('Neutral', $evidence['market_regime']);
        $this->assertEqualsWithDelta(50.0, (float) $evidence['market_regime_score'], 0.0001);
    }

    public function test_pipeline_carries_authoritative_regime_into_evaluation(): void
    {
        $this->mock(MarketAnalysisEngine::class, function ($mock): void {
            $mock->shouldReceive('latest')->andReturn([
                'available' => true,
                'market_phase' => 'Strong Bull',
                'market_regime' => 'Bullish',
                'allocation_multiplier' => 1.15,
                'new_entry_allowed' => true,
                'sentiment' => ['score' => 80, 'label' => 'Greed'],
            ]);
        });
        [$profile, $stock] = $this->seedEvaluableStock(withWatchlist: true);
        $this->markDailyDatasetPublished();

        $result = app(DailyDecisionPipeline::class)->run($profile, [
            'notify' => false,
            'review' => false,
        ]);

        $this->assertSame('completed', $result['pipeline_run']->status);
        $this->assertTrue($result['stages']['publish_gate']['allowed'] ?? false);
        $run = EvaluationRun::query()->findOrFail($result['stages']['evaluation']['run_id']);
        $row = EvaluationResult::query()->where('evaluation_run_id', $run->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('Bullish', $row->evidence['market_regime'] ?? null);
        $this->assertEqualsWithDelta(100.0, (float) ($row->evidence['market_regime_score'] ?? -1), 0.0001);
        $this->assertEqualsWithDelta(100.0, (float) ($row->evidence['indicator_scores']['market_regime'] ?? -1), 0.0001);
        $this->assertSame($stock->id, (int) $row->candidate->security_id);
    }

    public function test_real_market_analysis_regime_reaches_evaluation(): void
    {
        $benchmark = app(IndexCatalogService::class)->primaryBenchmarkStock();
        $this->seedSmoothUptrend($benchmark, bars: 220, start: 100.0, step: 0.8);

        $payload = app(MarketAnalysisEngine::class)->analyze($benchmark);
        $this->assertTrue($payload['available'] ?? false);
        $this->assertContains($payload['market_regime'], ['Bullish', 'Neutral', 'Bearish']);
        $expectedScore = app(MarketRegimeScoreMapper::class)->score($payload['market_regime']);

        [$profile, $stock] = $this->seedEvaluableStock();
        $discoveryRun = $this->discoveryWithCandidate($profile, $stock);
        $evidence = app(EvaluationEngine::class)->run($profile, $discoveryRun)['results'][0]->evidence;

        $this->assertFalse((bool) ($evidence['skipped'] ?? false));
        $this->assertSame($payload['market_regime'], $evidence['market_regime']);
        $this->assertEqualsWithDelta($expectedScore, (float) $evidence['market_regime_score'], 0.0001);
        $this->assertEqualsWithDelta($expectedScore, (float) $evidence['indicator_scores']['market_regime'], 0.0001);
        $this->assertContains($evidence['market_regime'], ['Bullish', 'Neutral', 'Bearish']);
    }

    public function test_real_unavailable_benchmark_follows_neutral_contract(): void
    {
        $benchmark = app(IndexCatalogService::class)->primaryBenchmarkStock();
        StockPrice::query()->where('stock_id', $benchmark->id)->delete();
        $this->seedSmoothUptrend($benchmark, bars: 10, start: 100.0, step: 1.0);

        $payload = app(MarketAnalysisEngine::class)->analyze($benchmark);
        $this->assertFalse($payload['available'] ?? true);
        $this->assertSame('Neutral', $payload['market_regime']);

        [$profile, $stock] = $this->seedEvaluableStock();
        $discoveryRun = $this->discoveryWithCandidate($profile, $stock);
        $evidence = app(EvaluationEngine::class)->run($profile, $discoveryRun)['results'][0]->evidence;

        $this->assertSame('Neutral', $evidence['market_regime']);
        $this->assertEqualsWithDelta(50.0, (float) $evidence['market_regime_score'], 0.0001);
    }

    protected function assertEvaluationMapsRegime(string $regime, float $score): void
    {
        $this->mockMarketRegime($regime);
        [$profile, $stock] = $this->seedEvaluableStock();
        $discoveryRun = $this->discoveryWithCandidate($profile, $stock);
        $evidence = app(EvaluationEngine::class)->run($profile, $discoveryRun)['results'][0]->evidence;

        $this->assertFalse((bool) ($evidence['skipped'] ?? false));
        $this->assertSame($regime, $evidence['market_regime']);
        $this->assertEqualsWithDelta($score, (float) $evidence['market_regime_score'], 0.0001);
        $this->assertEqualsWithDelta($score, (float) $evidence['factor_scores']['market_regime'], 0.0001);
        $this->assertEqualsWithDelta($score, (float) $evidence['indicator_scores']['market_regime'], 0.0001);
        $this->assertIsString($evidence['market_regime']);
    }

    /**
     * @return array<string, mixed>
     */
    protected function evaluateWithRegime($profile, DiscoveryRun $discoveryRun, string $regime): array
    {
        $this->mockMarketRegime($regime);
        $result = app(EvaluationEngine::class)->run($profile, $discoveryRun);

        return $result['results'][0]->evidence;
    }

    protected function mockMarketRegime(string $regime): void
    {
        $this->mock(MarketAnalysisEngine::class, function ($mock) use ($regime): void {
            $mock->shouldReceive('latest')->once()->andReturn([
                'available' => true,
                'market_phase' => 'Consolidation',
                'market_regime' => $regime,
                'sentiment' => ['score' => 61, 'label' => 'Neutral'],
            ]);
        });
    }

    /**
     * @return array{0: \App\Models\PortfolioProfile, 1: Stock}
     */
    protected function seedEvaluableStock(bool $withWatchlist = false): array
    {
        $user = User::query()->create([
            'name' => 'Regime User',
            'email' => 'regime-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);
        $stock = Stock::query()->create([
            'symbol' => 'R'.strtoupper(Str::random(4)),
            'exchange' => 'NSE',
            'name' => 'Regime Test Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        if ($withWatchlist) {
            $watchlist = app(WatchlistService::class)->ensureDefaultWatchlist($profile);
            WatchlistItem::query()->create([
                'profile_id' => $profile->id,
                'watchlist_id' => $watchlist->id,
                'stock_id' => $stock->id,
                'note' => null,
            ]);
        }

        $n = 80;
        for ($i = 0; $i < $n; $i++) {
            $close = 80.0 + ($i * 0.8) + (($i % 7) * 0.35);
            StockPrice::query()->create([
                'stock_id' => $stock->id,
                'price_date' => now()->subDays($n - $i)->toDateString(),
                'open_price' => $close - 0.4,
                'high_price' => $close + 1.2,
                'low_price' => $close - 1.1,
                'close_price' => $close,
                'volume' => 20000 + ($i * 900),
                'data_source' => 'test',
                'created_at' => now(),
            ]);
        }

        return [$profile, $stock];
    }

    protected function discoveryWithCandidate($profile, Stock $stock): DiscoveryRun
    {
        $discoveryRun = DiscoveryRun::query()->create([
            'profile_id' => $profile->id,
            'dataset_version' => 'test',
            'status' => 'completed',
            'started_at' => now(),
            'completed_at' => now(),
        ]);
        Candidate::query()->create([
            'discovery_run_id' => $discoveryRun->id,
            'security_id' => $stock->id,
            'source' => 'test',
            'evidence' => [],
            'created_at' => now(),
        ]);

        return $discoveryRun;
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
                'volume' => 1_000_000,
                'data_source' => 'test',
                'created_at' => now(),
            ]);
        }
    }
}
