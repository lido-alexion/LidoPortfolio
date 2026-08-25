<?php

namespace Tests\Feature;

use App\Engines\Recommendation\RecommendationEngine;
use App\Engines\Recommendation\RecommendationGenerationPipeline;
use App\Engines\Strategy\FactoryMomentumStrategy;
use App\Models\BacktestRun;
use App\Models\BacktestTrade;
use App\Models\Candidate;
use App\Models\DiscoveryRun;
use App\Models\EvaluationResult;
use App\Models\EvaluationRun;
use App\Models\Holding;
use App\Models\PortfolioProfile;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\TradingRecommendation;
use App\Models\TradingStrategy;
use App\Models\TradingStrategyVersion;
use App\Models\User;
use App\Services\Analytics\MarketAnalyticsService;
use App\Services\CashManagementService;
use App\Services\ProfileSettingsService;
use App\Services\Strategy\PortfolioCapitalAccountingService;
use App\Services\Strategy\StrategyRegistrySupport;
use App\Services\StrategyConfigurationService;
use App\Support\TradingOsConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

class V3RecommendationGenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_multiple_enabled_strategies_generate_independently(): void
    {
        [$profile, $stock, $run, $first, $second] = $this->seedTwoStrategies();
        $this->mockMarket();
        app(CashManagementService::class)->deposit($profile, 500000, 'seed');

        $result = app(RecommendationEngine::class)->generate($profile, $run);
        $recs = collect($result['recommendations'])->where('security_id', $stock->id);

        $this->assertCount(2, $recs);
        $versionIds = $recs->pluck('strategy_version_id')->sort()->values()->all();
        $this->assertEqualsCanonicalizing(
            [$first->active_version_id, $second->active_version_id],
            $versionIds
        );
        $this->assertCount(2, $result['strategies']);
    }

    public function test_strategy_a_does_not_cancel_strategy_b_stale_recommendations(): void
    {
        [$profile, $stock, $run, $first, $second] = $this->seedTwoStrategies();

        $recA = $this->staleRec($profile, $first->active_version_id, $stock->id);
        $recB = $this->staleRec($profile, $second->active_version_id, $stock->id);

        $pipeline = app(RecommendationGenerationPipeline::class);
        $ref = new ReflectionMethod($pipeline, 'cancelStaleRecommendations');
        $ref->invoke($pipeline, $profile, (int) $first->id);

        $this->assertSame(TradingRecommendation::STATUS_CANCELLED, $recA->fresh()->status);
        $this->assertSame(TradingRecommendation::STATUS_PENDING_REVIEW, $recB->fresh()->status);
    }

    public function test_strategy_scoped_holdings_do_not_count_for_other_strategy(): void
    {
        [$profile, $stock, $run, $first, $second] = $this->seedTwoStrategies();
        $this->mockMarket();
        app(CashManagementService::class)->deposit($profile, 500000, 'seed');

        Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'strategy_id' => $first->id,
            'quantity' => 10,
            'avg_buy_price' => 100,
            'invested_amount' => 1000,
            'updated_at' => now(),
        ]);

        $result = app(RecommendationEngine::class)->generate($profile, $run);
        $recs = collect($result['recommendations'])->where('security_id', $stock->id);

        $forFirst = $recs->firstWhere('strategy_version_id', $first->active_version_id);
        $forSecond = $recs->firstWhere('strategy_version_id', $second->active_version_id);

        $this->assertNotNull($forFirst);
        $this->assertNotNull($forSecond);
        $this->assertNotSame(TradingRecommendation::ACTION_OPEN_POSITION, $forFirst->recommendation_type);
        $this->assertSame(TradingRecommendation::ACTION_OPEN_POSITION, $forSecond->recommendation_type);
    }

    public function test_one_ws2_snapshot_is_consumed_per_generation_cycle(): void
    {
        [$profile, $stock, $run] = $this->seedSingleStrategy();
        $this->mockMarket();
        app(CashManagementService::class)->deposit($profile, 100000, 'seed');

        $counter = $this->app->make(SnapshotCountingAccounting::class);
        $this->app->instance(PortfolioCapitalAccountingService::class, $counter);

        app(RecommendationEngine::class)->generate($profile, $run);

        $this->assertSame(1, $counter->calls);
    }

    public function test_existing_single_strategy_portfolio_still_generates(): void
    {
        [$profile, $stock, $run] = $this->seedSingleStrategy();
        $this->mockMarket();
        app(CashManagementService::class)->deposit($profile, 100000, 'seed');

        $result = app(RecommendationEngine::class)->generate($profile, $run);
        $rec = collect($result['recommendations'])->firstWhere('security_id', $stock->id);

        $this->assertNotNull($rec);
        $this->assertSame(TradingRecommendation::ACTION_OPEN_POSITION, $rec->recommendation_type);
        $this->assertSame(TradingRecommendation::ALLOCATION_FUNDED, $rec->evidence['capital_allocation']['status'] ?? null);
        $this->assertCount(1, $result['strategies']);
    }

    public function test_od23_order_is_recorded_when_ranking_unavailable(): void
    {
        [$profile, $stockA, $run, $strategy] = $this->seedSingleStrategyWithSecondStock();
        $this->mockMarket();
        app(CashManagementService::class)->deposit($profile, 200000, 'seed');

        $result = app(RecommendationEngine::class)->generate($profile, $run);
        $buys = collect($result['recommendations'])
            ->filter(fn ($r) => $r->recommendation_type === TradingRecommendation::ACTION_OPEN_POSITION)
            ->values();

        $this->assertGreaterThanOrEqual(2, $buys->count());
        foreach ($buys as $rec) {
            $this->assertSame('od23', $rec->evidence['ranking']['order_source'] ?? null);
            $this->assertFalse($rec->evidence['ranking']['computable'] ?? true);
        }
    }

    public function test_return_quality_order_is_recorded_when_corpus_is_eligible(): void
    {
        [$profile, $stock, $run, $strategy] = $this->seedSingleStrategy();
        $this->mockMarket();
        app(CashManagementService::class)->deposit($profile, 200000, 'seed');
        $this->seedRankingCorpus((int) $strategy->active_version_id, (int) $strategy->id, (int) $profile->id, (int) $stock->id);

        $result = app(RecommendationEngine::class)->generate($profile, $run);
        $rec = collect($result['recommendations'])->firstWhere('security_id', $stock->id);

        $this->assertNotNull($rec);
        $this->assertSame('return_quality', $rec->evidence['ranking']['order_source'] ?? null);
        $this->assertTrue($rec->evidence['ranking']['computable'] ?? false);
    }

    /**
     * @return array{0: PortfolioProfile, 1: Stock, 2: EvaluationRun, 3: TradingStrategy, 4: TradingStrategy}
     */
    protected function seedTwoStrategies(): array
    {
        [$profile, $stock, $run, $first] = $this->seedSingleStrategy();
        $second = $this->makeStrategy($profile, 'Second Enabled');
        $second->activeVersion->forceFill([
            'config_json' => $first->activeVersion->config_json,
        ])->save();
        app(StrategyRegistrySupport::class)->activate($profile, $second);
        $first->forceFill(['allocation_pct' => 50])->save();
        $second->fresh()->forceFill(['allocation_pct' => 50])->save();

        return [$profile, $stock, $run, $first->fresh(['activeVersion']), $second->fresh(['activeVersion'])];
    }

    /**
     * @return array{0: PortfolioProfile, 1: Stock, 2: EvaluationRun, 3: TradingStrategy}
     */
    protected function seedSingleStrategy(): array
    {
        [$profile, $stock, $run] = $this->baseFixture();
        $strategy = TradingStrategy::query()
            ->where('profile_id', $profile->id)
            ->where('status', TradingStrategy::STATUS_ACTIVE)
            ->first();

        return [$profile, $stock, $run, $strategy];
    }

    /**
     * @return array{0: PortfolioProfile, 1: Stock, 2: EvaluationRun, 3: TradingStrategy}
     */
    protected function seedSingleStrategyWithSecondStock(): array
    {
        [$profile, $stockA, $run, $strategy] = $this->seedSingleStrategy();
        $stockB = $this->makePricedStock('ZZZ'.strtoupper(Str::random(2)));
        $discoveryId = EvaluationRun::query()->whereKey($run->id)->value('discovery_run_id');
        $candidate = Candidate::query()->create([
            'discovery_run_id' => $discoveryId,
            'security_id' => $stockB->id,
            'source' => 'test',
            'evidence' => [],
            'created_at' => now(),
        ]);
        $scores = [
            'relative_strength' => 95,
            'momentum_score' => 95,
            'trend_score' => 95,
            'breakout_score' => 95,
            'volume_score' => 95,
            'market_regime' => 95,
            'sector_strength' => 95,
            'risk_score' => 20,
        ];
        EvaluationResult::query()->create([
            'evaluation_run_id' => $run->id,
            'candidate_id' => $candidate->id,
            'score' => 95,
            'confidence' => 0.9,
            'rank' => 2,
            'evidence' => [
                'factor_scores' => $scores,
                'indicator_scores' => $scores,
                'indicators' => ['close' => 100, 'atr_pct' => 1.5],
            ],
            'passed_rules' => [],
            'failed_rules' => [],
            'created_at' => now(),
        ]);

        return [$profile, $stockA, $run, $strategy];
    }

    /**
     * @return array{0: PortfolioProfile, 1: Stock, 2: EvaluationRun}
     */
    protected function baseFixture(): array
    {
        $user = User::query()->create([
            'name' => 'V3 User',
            'email' => 'v3-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);
        $stock = $this->makePricedStock('AAA'.strtoupper(Str::random(2)));

        $anchor = $this->makePricedStock('ANC'.strtoupper(Str::random(2)), 1000);
        Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $anchor->id,
            'quantity' => 100,
            'avg_buy_price' => 1000,
            'invested_amount' => 100000,
            'updated_at' => now(),
        ]);

        $strategyVersion = app(StrategyConfigurationService::class)->ensureActive($profile);
        app(ProfileSettingsService::class)->set(
            $profile,
            \App\Services\Entry\MinimumActionableAmountResolver::SETTING_KEY,
            '1',
        );
        $config = $strategyVersion->config_json ?? FactoryMomentumStrategy::config();
        $config['eligibility_sources'] = [];
        $config['thresholds'] = array_merge($config['thresholds'] ?? [], [
            'open_position' => 70,
            'increase_position' => 75,
            'watch' => 50,
            'reduce_position' => 40,
            'exit_position' => 20,
        ]);
        $config['portfolio_rules'] = array_merge($config['portfolio_rules'] ?? [], [
            'default_position_size_pct' => 10.0,
            'max_position_size_pct' => 15.0,
            'first_entry_pct' => 50.0,
            'max_new_positions_per_cycle' => 5,
            'min_cash_reserve_pct' => 0,
            'max_cash_deployment_pct' => 100,
        ]);
        $config['exit_strategy'] = ['enabled' => false, 'mode' => 'any', 'rules' => []];
        $config[TradingOsConfig::STRATEGY_MARKET_GATES] = ['enabled' => false];
        $strategyVersion->forceFill(['config_json' => $config])->save();

        $discoveryRun = DiscoveryRun::query()->create([
            'profile_id' => $profile->id,
            'dataset_version' => 'test',
            'status' => 'completed',
            'started_at' => now(),
            'completed_at' => now(),
        ]);
        $candidate = Candidate::query()->create([
            'discovery_run_id' => $discoveryRun->id,
            'security_id' => $stock->id,
            'source' => 'test',
            'evidence' => [],
            'created_at' => now(),
        ]);
        $run = EvaluationRun::query()->create([
            'profile_id' => $profile->id,
            'discovery_run_id' => $discoveryRun->id,
            'status' => 'completed',
            'started_at' => now(),
            'completed_at' => now(),
        ]);
        $scores = [
            'relative_strength' => 95,
            'momentum_score' => 95,
            'trend_score' => 95,
            'breakout_score' => 95,
            'volume_score' => 95,
            'market_regime' => 95,
            'sector_strength' => 95,
            'risk_score' => 20,
        ];
        EvaluationResult::query()->create([
            'evaluation_run_id' => $run->id,
            'candidate_id' => $candidate->id,
            'score' => 95,
            'confidence' => 0.9,
            'rank' => 1,
            'evidence' => [
                'factor_scores' => $scores,
                'indicator_scores' => $scores,
                'indicators' => ['close' => 100, 'atr_pct' => 1.5],
            ],
            'passed_rules' => [],
            'failed_rules' => [],
            'created_at' => now(),
        ]);

        return [$profile, $stock, $run];
    }

    protected function seedRankingCorpus(int $versionId, int $strategyId, int $profileId, int $stockId): void
    {
        $run = BacktestRun::query()->create([
            'profile_id' => $profileId,
            'strategy_id' => $strategyId,
            'strategy_version_id' => $versionId,
            'name' => 'ranking corpus',
            'from_date' => '2020-01-01',
            'to_date' => '2024-01-01',
            'initial_capital' => 1000000,
            'status' => BacktestRun::STATUS_COMPLETED,
            'stage' => BacktestRun::STAGE_COMPLETED,
            'completed_at' => now(),
        ]);
        for ($i = 0; $i < 15; $i++) {
            BacktestTrade::query()->create([
                'backtest_run_id' => $run->id,
                'stock_id' => $stockId,
                'symbol' => 'T'.$i,
                'buy_date' => '2023-01-01',
                'sell_date' => '2023-04-01',
                'holding_days' => 90,
                'buy_price' => 100,
                'sell_price' => 110,
                'quantity' => 10,
                'profit_loss' => 100,
                'return_pct' => 10,
                'cagr' => 40,
                'exit_reason' => 'EXIT',
                'entry_score' => 95.0,
                'is_open' => false,
            ]);
        }
    }

    protected function staleRec($profile, int $versionId, int $stockId): TradingRecommendation
    {
        return TradingRecommendation::query()->create([
            'profile_id' => $profile->id,
            'evaluation_result_id' => null,
            'strategy_version_id' => $versionId,
            'security_id' => $stockId,
            'recommendation_type' => TradingRecommendation::ACTION_OPEN_POSITION,
            'market_opinion' => ['direction' => 'Bullish'],
            'execution_plan' => [],
            'priority' => 80,
            'strategy_score' => 80,
            'confidence' => 0.8,
            'risk_level' => TradingRecommendation::RISK_MEDIUM,
            'status' => TradingRecommendation::STATUS_PENDING_REVIEW,
            'evidence' => [],
            'failed_checks' => [],
            'reasoning' => 'stale',
            'reservation_status' => TradingRecommendation::RESERVATION_NONE,
            'reserved_amount' => 0,
            'version' => 4,
            'generated_at' => now()->subDays(3),
        ]);
    }

    protected function makeStrategy($profile, string $name): TradingStrategy
    {
        $strategy = TradingStrategy::query()->create([
            'profile_id' => $profile->id,
            'name' => $name,
            'slug' => Str::slug($name).'_'.Str::lower(Str::random(4)),
            'status' => TradingStrategy::STATUS_DRAFT,
            'allocation_pct' => 50,
            'is_factory' => false,
        ]);
        $version = TradingStrategyVersion::query()->create([
            'strategy_id' => $strategy->id,
            'version' => 1,
            'version_label' => '1.0',
            'config_json' => ['indicators' => []],
            'status' => TradingStrategyVersion::STATUS_DRAFT,
        ]);
        $strategy->forceFill(['active_version_id' => $version->id])->save();

        return $strategy->fresh(['activeVersion']);
    }

    protected function makePricedStock(string $symbol, float $close = 100): Stock
    {
        $stock = Stock::query()->create([
            'symbol' => $symbol,
            'exchange' => 'NSE',
            'name' => $symbol,
            'is_active' => true,
        ]);
        StockPrice::query()->create([
            'stock_id' => $stock->id,
            'price_date' => now()->toDateString(),
            'open_price' => $close,
            'high_price' => $close,
            'low_price' => $close,
            'close_price' => $close,
            'volume' => 10000,
            'data_source' => 'test',
        ]);

        return $stock;
    }

    protected function mockMarket(): void
    {
        $this->mock(MarketAnalyticsService::class, function ($mock): void {
            $mock->shouldReceive('latest')->andReturn([
                'available' => true,
                'market_phase' => 'Bull',
                'new_entry_allowed' => true,
                'allocation_multiplier' => 1.0,
                'sentiment' => ['score' => 70, 'label' => 'Bullish'],
                'risk' => ['label' => 'Medium', 'raw_risk' => 40],
            ]);
        });
    }
}

class SnapshotCountingAccounting extends PortfolioCapitalAccountingService
{
    public int $calls = 0;

    public function snapshot(PortfolioProfile $profile): array
    {
        $this->calls++;

        return parent::snapshot($profile);
    }
}
