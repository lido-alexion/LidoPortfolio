<?php

namespace Tests\Feature\V8;

use App\Engines\Recommendation\RecommendationEngine;
use App\Engines\Strategy\FactoryMomentumStrategy;
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
use App\Models\User;
use App\Services\Analytics\MarketAnalyticsService;
use App\Services\CashManagementService;
use App\Services\Entry\MinimumActionableAmountResolver;
use App\Services\ProfileSettingsService;
use App\Services\StrategyConfigurationService;
use App\Support\TradingOsConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MinimumActionableOpportunityTest extends TestCase
{
    use RefreshDatabase;

    public function test_inherited_default_allows_an_exact_whole_share_first_entry(): void
    {
        [$profile, $stock, $run] = $this->seedCandidatePortfolio(100);
        app(CashManagementService::class)->deposit($profile, 500000, 'test-capital');
        $this->mockMarket();

        $minimum = app(MinimumActionableAmountResolver::class);
        $this->assertSame(5000.0, $minimum->effectiveMinimum($profile));

        $result = app(RecommendationEngine::class)->generate($profile, $run);
        $open = collect($result['recommendations'])->first(
            fn (TradingRecommendation $recommendation) => (int) $recommendation->security_id === (int) $stock->id
                && $recommendation->recommendation_type === TradingRecommendation::ACTION_OPEN_POSITION,
        );

        $this->assertNotNull($open, 'The inherited ₹5,000 minimum must allow an exactly ₹5,000 first-entry opportunity.');
        $this->assertEqualsWithDelta(10000.0, (float) $open->execution_plan['position_target_amount'], 0.01);
        $this->assertEqualsWithDelta(5000.0, (float) $open->execution_plan['this_cycle_amount'], 0.01);
        $this->assertSame(50, (int) $open->execution_plan['suggested_quantity']);

        $holding = Holding::query()
            ->where('profile_id', $profile->id)
            ->where('stock_id', $stock->id)
            ->where('owner_key', Holding::ownerKeyFor((int) $open->strategyVersion->strategy_id))
            ->first();
        $this->assertNotNull($holding);
        $this->assertEqualsWithDelta(10000.0, (float) $holding->target_amount, 0.01);
        $this->assertSame(0.0, (float) $holding->filled_amount);
    }

    public function test_first_entry_below_default_after_whole_share_floor_emits_no_open(): void
    {
        [$profile, $stock, $run] = $this->seedCandidatePortfolio(100, 49.9999);
        app(CashManagementService::class)->deposit($profile, 500000, 'test-capital');
        $this->mockMarket();

        $this->assertSame(5000.0, app(MinimumActionableAmountResolver::class)->effectiveMinimum($profile));
        $result = app(RecommendationEngine::class)->generate($profile, $run);
        $recommendations = collect($result['recommendations'])->filter(
            fn (TradingRecommendation $recommendation) => (int) $recommendation->security_id === (int) $stock->id,
        );

        $this->assertSame(0, $recommendations->whereIn('recommendation_type', [
            TradingRecommendation::ACTION_OPEN_POSITION,
            TradingRecommendation::ACTION_INCREASE_POSITION,
        ])->count());
        $this->assertDatabaseMissing('portfolio_holdings', [
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'target_amount' => 10000,
        ]);
    }

    public function test_below_minimum_remaining_opportunity_preserves_the_strategy_target(): void
    {
        [$profile, $stock, $run, $strategy] = $this->seedCandidatePortfolio(92);
        app(CashManagementService::class)->deposit($profile, 500000, 'test-capital');
        $this->mockMarket();
        $holding = $this->seedOwnedHolding($profile, $stock, $strategy, 10000, 8000);

        $this->assertSame(5000.0, app(MinimumActionableAmountResolver::class)->effectiveMinimum($profile));
        $result = app(RecommendationEngine::class)->generate($profile, $run);
        $recommendations = collect($result['recommendations'])->filter(
            fn (TradingRecommendation $recommendation) => (int) $recommendation->security_id === (int) $stock->id,
        );

        $this->assertSame(0, $recommendations->where('recommendation_type', TradingRecommendation::ACTION_INCREASE_POSITION)->count());
        $this->assertEqualsWithDelta(10000.0, (float) $holding->fresh()->target_amount, 0.01);
        $this->assertEqualsWithDelta(8000.0, (float) $holding->fresh()->filled_amount, 0.01);
        $this->assertSame(80.0, (float) $holding->fresh()->quantity);
    }

    public function test_portfolio_override_suppresses_six_thousand_then_blank_inherits_default(): void
    {
        [$profile, $stock, $run, $strategy] = $this->seedCandidatePortfolio(132);
        app(CashManagementService::class)->deposit($profile, 500000, 'test-capital');
        $this->mockMarket();
        $holding = $this->seedOwnedHolding($profile, $stock, $strategy, 14000, 8000);
        $settings = app(ProfileSettingsService::class);
        $minimum = app(MinimumActionableAmountResolver::class);

        $settings->set($profile, MinimumActionableAmountResolver::SETTING_KEY, '7500');
        $this->assertSame(7500.0, $minimum->effectiveMinimum($profile));
        $overridden = app(RecommendationEngine::class)->generate($profile, $run);
        $this->assertSame(0, collect($overridden['recommendations'])->filter(
            fn (TradingRecommendation $recommendation) => (int) $recommendation->security_id === (int) $stock->id
                && $recommendation->recommendation_type === TradingRecommendation::ACTION_INCREASE_POSITION,
        )->count());
        $this->assertEqualsWithDelta(14000.0, (float) $holding->fresh()->target_amount, 0.01);
        $this->assertEqualsWithDelta(8000.0, (float) $holding->fresh()->filled_amount, 0.01);

        $settings->set($profile, MinimumActionableAmountResolver::SETTING_KEY, null);
        $this->assertSame(5000.0, $minimum->effectiveMinimum($profile));
        $inherited = app(RecommendationEngine::class)->generate($profile, $run);
        $increase = collect($inherited['recommendations'])->first(
            fn (TradingRecommendation $recommendation) => (int) $recommendation->security_id === (int) $stock->id
                && $recommendation->recommendation_type === TradingRecommendation::ACTION_INCREASE_POSITION,
        );

        $this->assertNotNull($increase, 'After clearing the override, the inherited ₹5,000 minimum allows the ₹6,000 remainder.');
        $this->assertEqualsWithDelta(14000.0, (float) $increase->execution_plan['position_target_amount'], 0.01);
        $this->assertEqualsWithDelta(6000.0, (float) $increase->execution_plan['remaining_amount'], 0.01);
        $this->assertEqualsWithDelta(6000.0, (float) $increase->execution_plan['this_cycle_amount'], 0.01);
        $this->assertSame(60, (int) $increase->execution_plan['suggested_quantity']);
        $this->assertEqualsWithDelta(14000.0, (float) $holding->fresh()->target_amount, 0.01);
    }

    /** @return array{PortfolioProfile, Stock, EvaluationRun, TradingStrategy} */
    private function seedCandidatePortfolio(int $anchorQuantity, float $firstEntryPct = 50.0): array
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        // Clear any profile-scoped cached value and exercise inheritance unless a test overrides it.
        app(ProfileSettingsService::class)->set($profile, MinimumActionableAmountResolver::SETTING_KEY, null);
        $stock = $this->makePricedStock('MIN'.strtoupper(Str::random(5)), 100.0);
        $anchor = $this->makePricedStock('ANC'.strtoupper(Str::random(5)), 1000.0);
        Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $anchor->id,
            'quantity' => $anchorQuantity,
            'avg_buy_price' => 1000,
            'invested_amount' => $anchorQuantity * 1000,
            'updated_at' => now(),
        ]);

        $version = app(StrategyConfigurationService::class)->ensureActive($profile);
        $config = $version->config_json ?? FactoryMomentumStrategy::config();
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
            'first_entry_pct' => $firstEntryPct,
            'max_new_positions_per_cycle' => 10,
            'min_cash_reserve_pct' => 0,
            'max_cash_deployment_pct' => 100,
        ]);
        $config['capital_allocation']['score_bands'] = [
            ['min' => 95, 'max' => 100, 'allocation_pct' => 10],
            ['min' => 0, 'max' => 95, 'allocation_pct' => 0],
        ];
        $config['exit_strategy'] = ['enabled' => false, 'mode' => 'any', 'rules' => []];
        $config[TradingOsConfig::STRATEGY_MARKET_GATES] = ['enabled' => false];
        $version->forceFill(['config_json' => $config])->save();

        $discovery = DiscoveryRun::query()->create([
            'profile_id' => $profile->id,
            'dataset_version' => 'test',
            'status' => 'completed',
            'started_at' => now(),
            'completed_at' => now(),
        ]);
        $candidate = Candidate::query()->create([
            'discovery_run_id' => $discovery->id,
            'security_id' => $stock->id,
            'source' => 'test',
            'evidence' => [],
            'created_at' => now(),
        ]);
        $run = EvaluationRun::query()->create([
            'profile_id' => $profile->id,
            'discovery_run_id' => $discovery->id,
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

        return [$profile, $stock, $run, TradingStrategy::query()->findOrFail($version->strategy_id)];
    }

    private function seedOwnedHolding(
        PortfolioProfile $profile,
        Stock $stock,
        TradingStrategy $strategy,
        float $target,
        float $filled,
    ): Holding {
        return Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'strategy_id' => $strategy->id,
            'owner_key' => Holding::ownerKeyFor((int) $strategy->id),
            'quantity' => $filled / 100,
            'avg_buy_price' => 100,
            'invested_amount' => $filled,
            'filled_amount' => $filled,
            'target_amount' => $target,
            'updated_at' => now(),
        ]);
    }

    private function makePricedStock(string $symbol, float $close): Stock
    {
        $stock = Stock::query()->create([
            'symbol' => $symbol,
            'exchange' => 'NSE',
            'name' => $symbol,
            'is_active' => true,
            'is_benchmark' => false,
        ]);
        StockPrice::query()->create([
            'stock_id' => $stock->id,
            'price_date' => now()->toDateString(),
            'open_price' => $close,
            'high_price' => $close,
            'low_price' => $close,
            'close_price' => $close,
            'volume' => 1000,
            'data_source' => 'test',
            'created_at' => now(),
        ]);

        return $stock;
    }

    private function mockMarket(): void
    {
        $this->mock(MarketAnalyticsService::class, function ($mock) {
            $mock->shouldReceive('analyze')->andReturn([
                'market_phase' => 'bull',
                'sentiment' => ['score' => 70, 'label' => 'bullish'],
                'risk' => ['label' => 'moderate', 'raw_risk' => 0.3],
            ]);
        });
    }
}
