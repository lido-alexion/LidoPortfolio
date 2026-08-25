<?php

namespace Tests\Feature\Entry;

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
use App\Models\TradingStrategyVersion;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Analytics\MarketAnalyticsService;
use App\Services\CashManagementService;
use App\Services\Entry\MinimumActionableAmountResolver;
use App\Services\Entry\StrategyPositionTargetService;
use App\Services\HoldingsCalculationService;
use App\Services\ProfileSettingsService;
use App\Services\Strategy\StrategyRegistrySupport;
use App\Services\StrategyConfigurationService;
use App\Support\TradingOsConfig;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * §34.4 Phase 1 — BUY cooldown, staggered entry, target/filled foundation.
 */
class StaggeredEntryPhase1FoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_buy_inside_one_calendar_day_is_suppressed(): void
    {
        [$profile, $stock, $run] = $this->seedOpenReady();
        app(CashManagementService::class)->deposit($profile, 500000, 'seed');
        $this->mockMarket();
        app(ProfileSettingsService::class)->set($profile, MinimumActionableAmountResolver::SETTING_KEY, '1');

        $day0 = Carbon::now()->startOfDay();
        Carbon::setTestNow($day0->copy()->setTime(10, 0));
        $first = app(RecommendationEngine::class)->generate($profile, $run);
        $open = collect($first['recommendations'])
            ->first(fn ($r) => (int) $r->security_id === (int) $stock->id
                && $r->recommendation_type === TradingRecommendation::ACTION_OPEN_POSITION);
        $this->assertNotNull($open, 'expected OPEN on Day 0; got: '.collect($first['recommendations'])->pluck('recommendation_type')->implode(','));

        Carbon::setTestNow($day0->copy()->addDay()->setTime(10, 0));
        $second = app(RecommendationEngine::class)->generate($profile, $run);
        $opens = collect($second['recommendations'])
            ->filter(fn ($r) => (int) $r->security_id === (int) $stock->id
                && $r->recommendation_type === TradingRecommendation::ACTION_OPEN_POSITION);
        $this->assertCount(0, $opens);
        $this->assertSame(
            TradingRecommendation::STATUS_PENDING_REVIEW,
            $open->fresh()->status,
            'OD-11: must not stale-replace BUY during cooldown'
        );
    }

    public function test_buy_after_cooldown_elapsed_is_eligible(): void
    {
        [$profile, $stock, $run] = $this->seedOpenReady();
        app(CashManagementService::class)->deposit($profile, 500000, 'seed');
        $this->mockMarket();
        app(ProfileSettingsService::class)->set($profile, MinimumActionableAmountResolver::SETTING_KEY, '1');

        $day0 = Carbon::now()->startOfDay();
        Carbon::setTestNow($day0->copy()->setTime(10, 0));
        app(RecommendationEngine::class)->generate($profile, $run);

        Carbon::setTestNow($day0->copy()->addDays(2)->setTime(10, 0));
        $again = app(RecommendationEngine::class)->generate($profile, $run);
        $open = collect($again['recommendations'])
            ->first(fn ($r) => (int) $r->security_id === (int) $stock->id
                && $r->recommendation_type === TradingRecommendation::ACTION_OPEN_POSITION);
        $this->assertNotNull($open);
    }

    public function test_cooldown_is_independent_per_strategy(): void
    {
        [$profile, $stock, $run, $first, $second] = $this->seedTwoStrategies();
        app(CashManagementService::class)->deposit($profile, 500000, 'seed');
        $this->mockMarket();
        app(ProfileSettingsService::class)->set($profile, MinimumActionableAmountResolver::SETTING_KEY, '1');

        $day0 = Carbon::now()->startOfDay();
        Carbon::setTestNow($day0->copy()->setTime(10, 0));
        app(RecommendationEngine::class)->generate($profile, $run);

        Carbon::setTestNow($day0->copy()->addDay()->setTime(10, 0));
        TradingRecommendation::query()
            ->where('profile_id', $profile->id)
            ->where('strategy_version_id', $second->active_version_id)
            ->whereIn('recommendation_type', [
                TradingRecommendation::ACTION_OPEN_POSITION,
                TradingRecommendation::ACTION_INCREASE_POSITION,
            ])
            ->delete();

        $result = app(RecommendationEngine::class)->generate($profile, $run);
        $forA = collect($result['recommendations'])
            ->first(fn ($r) => (int) $r->strategy_version_id === (int) $first->active_version_id
                && $r->recommendation_type === TradingRecommendation::ACTION_OPEN_POSITION);
        $forB = collect($result['recommendations'])
            ->first(fn ($r) => (int) $r->strategy_version_id === (int) $second->active_version_id
                && $r->recommendation_type === TradingRecommendation::ACTION_OPEN_POSITION);

        $this->assertNull($forA);
        $this->assertNotNull($forB);
    }

    public function test_first_entry_is_half_of_position_target(): void
    {
        [$profile, $stock, $run] = $this->seedOpenReady();
        app(CashManagementService::class)->deposit($profile, 500000, 'seed');
        $this->mockMarket();
        app(ProfileSettingsService::class)->set($profile, MinimumActionableAmountResolver::SETTING_KEY, '1');

        $result = app(RecommendationEngine::class)->generate($profile, $run);
        $open = collect($result['recommendations'])
            ->first(fn ($r) => (int) $r->security_id === (int) $stock->id
                && $r->recommendation_type === TradingRecommendation::ACTION_OPEN_POSITION);
        $this->assertNotNull($open);

        $plan = $open->execution_plan;
        $positionTarget = (float) ($plan['position_target_amount'] ?? 0);
        $thisCycle = (float) ($plan['this_cycle_amount'] ?? $plan['target_investment_amount'] ?? 0);
        $this->assertGreaterThan(0, $positionTarget);
        $this->assertEqualsWithDelta($positionTarget * 0.5, $thisCycle, 100.0);
        $this->assertTrue((bool) ($plan['is_first_entry'] ?? false));
        $this->assertLessThan($positionTarget, $thisCycle + 0.0001);

        $holding = Holding::query()
            ->where('profile_id', $profile->id)
            ->where('stock_id', $stock->id)
            ->whereNotNull('target_amount')
            ->first();
        $this->assertNotNull($holding);
        $this->assertEqualsWithDelta($positionTarget, (float) $holding->target_amount, 0.01);
    }

    public function test_target_persists_and_partial_fill_does_not_reduce_target(): void
    {
        [$profile, $stock, $run, $strategy] = $this->seedOpenReady();
        $targets = app(StrategyPositionTargetService::class);
        $holding = $targets->upsertTargetAmount($profile, $stock, $strategy, 20000.0);
        $this->assertEqualsWithDelta(20000.0, (float) $holding->target_amount, 0.0001);

        $rec = TradingRecommendation::query()->create([
            'profile_id' => $profile->id,
            'security_id' => $stock->id,
            'strategy_version_id' => $strategy->active_version_id,
            'recommendation_type' => TradingRecommendation::ACTION_OPEN_POSITION,
            'status' => TradingRecommendation::STATUS_EXECUTED,
            'priority' => 1,
            'strategy_score' => 90,
            'confidence' => 0.9,
            'risk_level' => 'medium',
            'execution_plan' => ['position_target_amount' => 20000],
            'evidence' => [],
            'generated_at' => now(),
        ]);

        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 190,
            'price' => 100,
            'fees' => 0,
            'transaction_date' => now()->toDateString(),
            'source' => Transaction::SOURCE_RECOMMENDATION,
            'recommendation_id' => $rec->id,
        ]);

        $holding->forceFill(['target_amount' => 20000])->save();
        $lots = app(HoldingsCalculationService::class)->recalculateOwnerLotsForProfileStock($profile, $stock);
        $lot = $lots->first(fn ($h) => $h->owner_key === Holding::ownerKeyFor((int) $strategy->id));
        $this->assertNotNull($lot);
        $this->assertEqualsWithDelta(20000.0, (float) $lot->target_amount, 0.0001);
        $this->assertEqualsWithDelta(19000.0, (float) $lot->filled_amount, 0.0001);
        $this->assertEqualsWithDelta(19000.0, (float) $lot->invested_amount, 0.0001);
    }

    public function test_subsequent_increase_uses_remaining_after_cooldown(): void
    {
        [$profile, $stock, $run, $strategy] = $this->seedOpenReady();
        app(CashManagementService::class)->deposit($profile, 500000, 'seed');
        $this->mockMarket();
        app(ProfileSettingsService::class)->set($profile, MinimumActionableAmountResolver::SETTING_KEY, '1');

        Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'strategy_id' => $strategy->id,
            'owner_key' => Holding::ownerKeyFor((int) $strategy->id),
            'quantity' => 50,
            'avg_buy_price' => 100,
            'invested_amount' => 5000,
            'filled_amount' => 5000,
            'target_amount' => 12000,
            'updated_at' => now(),
        ]);

        TradingRecommendation::query()->create([
            'profile_id' => $profile->id,
            'security_id' => $stock->id,
            'strategy_version_id' => $strategy->active_version_id,
            'recommendation_type' => TradingRecommendation::ACTION_OPEN_POSITION,
            'status' => TradingRecommendation::STATUS_CANCELLED,
            'priority' => 1,
            'strategy_score' => 90,
            'confidence' => 0.9,
            'risk_level' => 'medium',
            'generated_at' => Carbon::parse('2026-08-20 10:00:00'),
        ]);

        Carbon::setTestNow('2026-08-26 10:00:00');
        $result = app(RecommendationEngine::class)->generate($profile, $run);
        $inc = collect($result['recommendations'])
            ->first(fn ($r) => (int) $r->security_id === (int) $stock->id
                && $r->recommendation_type === TradingRecommendation::ACTION_INCREASE_POSITION);

        $this->assertNotNull($inc);
        $this->assertFalse((bool) ($inc->execution_plan['is_first_entry'] ?? true));
        $remaining = (float) ($inc->execution_plan['remaining_amount'] ?? 0);
        $thisCycle = (float) ($inc->execution_plan['this_cycle_amount'] ?? 0);
        $this->assertGreaterThan(0, $remaining);
        $this->assertEqualsWithDelta($remaining, $thisCycle, 100.0);
    }

    public function test_cooldown_does_not_suppress_exit(): void
    {
        [$profile, $stock, $run, $strategy] = $this->seedOpenReady();
        app(CashManagementService::class)->deposit($profile, 500000, 'seed');
        $this->mockMarket();
        app(ProfileSettingsService::class)->set($profile, MinimumActionableAmountResolver::SETTING_KEY, '1');

        Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'strategy_id' => $strategy->id,
            'owner_key' => Holding::ownerKeyFor((int) $strategy->id),
            'quantity' => 10,
            'avg_buy_price' => 100,
            'invested_amount' => 1000,
            'filled_amount' => 1000,
            'target_amount' => 10000,
            'updated_at' => now(),
        ]);

        $day0 = Carbon::now()->startOfDay();
        Carbon::setTestNow($day0->copy()->setTime(10, 0));
        TradingRecommendation::query()->create([
            'profile_id' => $profile->id,
            'security_id' => $stock->id,
            'strategy_version_id' => $strategy->active_version_id,
            'recommendation_type' => TradingRecommendation::ACTION_OPEN_POSITION,
            'status' => TradingRecommendation::STATUS_CANCELLED,
            'priority' => 1,
            'strategy_score' => 90,
            'confidence' => 0.9,
            'risk_level' => 'medium',
            'generated_at' => $day0->copy()->setTime(9, 0),
        ]);

        // Force EXIT via very low threshold on held name.
        $version = $strategy->activeVersion;
        $config = $version->config_json;
        $config['thresholds']['exit_position'] = 100;
        $config['thresholds']['open_position'] = 101;
        $config['thresholds']['increase_position'] = 101;
        $version->forceFill(['config_json' => $config])->save();

        Carbon::setTestNow($day0->copy()->addDay()->setTime(10, 0));
        $result = app(RecommendationEngine::class)->generate($profile, $run);
        $exit = collect($result['recommendations'])
            ->first(fn ($r) => (int) $r->security_id === (int) $stock->id
                && $r->recommendation_type === TradingRecommendation::ACTION_EXIT_POSITION);
        $this->assertNotNull($exit, 'OD-11 must not suppress EXIT during BUY cooldown');
    }

    public function test_target_reached_emits_no_increase_for_remaining_zero(): void
    {
        [$profile, $stock, $run, $strategy] = $this->seedOpenReady();
        app(CashManagementService::class)->deposit($profile, 500000, 'seed');
        $this->mockMarket();
        app(ProfileSettingsService::class)->set($profile, MinimumActionableAmountResolver::SETTING_KEY, '1');

        Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'strategy_id' => $strategy->id,
            'owner_key' => Holding::ownerKeyFor((int) $strategy->id),
            'quantity' => 200,
            'avg_buy_price' => 100,
            'invested_amount' => 20000,
            'filled_amount' => 20000,
            'target_amount' => 20000,
            'updated_at' => now(),
        ]);

        TradingRecommendation::query()->create([
            'profile_id' => $profile->id,
            'security_id' => $stock->id,
            'strategy_version_id' => $strategy->active_version_id,
            'recommendation_type' => TradingRecommendation::ACTION_OPEN_POSITION,
            'status' => TradingRecommendation::STATUS_CANCELLED,
            'priority' => 1,
            'strategy_score' => 90,
            'confidence' => 0.9,
            'risk_level' => 'medium',
            'generated_at' => now()->subDays(5),
        ]);

        $result = app(RecommendationEngine::class)->generate($profile, $run);
        $buys = collect($result['recommendations'])
            ->filter(fn ($r) => (int) $r->security_id === (int) $stock->id
                && in_array($r->recommendation_type, [
                    TradingRecommendation::ACTION_OPEN_POSITION,
                    TradingRecommendation::ACTION_INCREASE_POSITION,
                ], true));
        $this->assertCount(0, $buys);
    }

    public function test_unfunded_allocation_does_not_mark_target_as_filled(): void
    {
        [$profile, $stock, $run] = $this->seedOpenReady();
        // Tiny cash → this-cycle may be UNFUNDED; target must still persist on holding.
        app(CashManagementService::class)->deposit($profile, 50, 'seed');
        $this->mockMarket();
        app(ProfileSettingsService::class)->set($profile, MinimumActionableAmountResolver::SETTING_KEY, '1');

        $result = app(RecommendationEngine::class)->generate($profile, $run);
        $open = collect($result['recommendations'])
            ->first(fn ($r) => (int) $r->security_id === (int) $stock->id
                && $r->recommendation_type === TradingRecommendation::ACTION_OPEN_POSITION);
        $this->assertNotNull($open);
        $status = $open->evidence['capital_allocation']['status'] ?? null;
        $this->assertContains($status, [
            TradingRecommendation::ALLOCATION_UNFUNDED,
            TradingRecommendation::ALLOCATION_PARTIALLY_FUNDED,
            TradingRecommendation::ALLOCATION_FUNDED,
        ]);
        $positionTarget = (float) ($open->execution_plan['position_target_amount'] ?? 0);
        $this->assertGreaterThan(0, $positionTarget);

        $holding = Holding::query()
            ->where('profile_id', $profile->id)
            ->where('stock_id', $stock->id)
            ->whereNotNull('target_amount')
            ->first();
        $this->assertNotNull($holding);
        $this->assertEqualsWithDelta($positionTarget, (float) $holding->target_amount, 0.01);
        // No fill yet — filled stays 0; invested not equal to target.
        $this->assertEqualsWithDelta(0.0, (float) ($holding->filled_amount ?? 0), 0.0001);
    }

    public function test_holdings_api_exposes_target_filled_remaining(): void
    {
        [$profile, $stock, $run, $strategy] = $this->seedOpenReady();
        $user = User::query()->findOrFail($profile->user_id);
        app(StrategyPositionTargetService::class)->upsertTargetAmount($profile, $stock, $strategy, 20000);
        Holding::query()
            ->where('profile_id', $profile->id)
            ->where('stock_id', $stock->id)
            ->update([
                'quantity' => 80,
                'avg_buy_price' => 100,
                'invested_amount' => 8000,
                'filled_amount' => 8000,
            ]);

        $this->actingAs($user)
            ->withHeader('X-Profile-Id', (string) $profile->id)
            ->getJson('/api/holdings')
            ->assertOk()
            ->assertJsonFragment([
                'target_amount' => 20000,
                'filled_amount' => 8000,
                'remaining_target_amount' => 12000,
            ]);
    }

    public function test_recommendation_api_exposes_position_target_fields(): void
    {
        [$profile, $stock, $run] = $this->seedOpenReady();
        $user = User::query()->findOrFail($profile->user_id);
        app(CashManagementService::class)->deposit($profile, 500000, 'seed');
        $this->mockMarket();
        app(ProfileSettingsService::class)->set($profile, MinimumActionableAmountResolver::SETTING_KEY, '1');

        $result = app(RecommendationEngine::class)->generate($profile, $run);
        $open = collect($result['recommendations'])
            ->first(fn ($r) => (int) $r->security_id === (int) $stock->id
                && $r->recommendation_type === TradingRecommendation::ACTION_OPEN_POSITION);
        $this->assertNotNull($open);

        $this->actingAs($user)
            ->withHeader('X-Profile-Id', (string) $profile->id)
            ->getJson('/api/v1/recommendations/'.$open->id)
            ->assertOk()
            ->assertJsonPath('data.is_first_entry', true)
            ->assertJson(fn ($json) => $json
                ->whereType('data.position_target_amount', ['double', 'integer'])
                ->whereType('data.this_cycle_amount', ['double', 'integer'])
                ->etc());
    }

    /**
     * @return array{0: PortfolioProfile, 1: Stock, 2: EvaluationRun, 3: TradingStrategy}
     */
    protected function seedOpenReady(): array
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $stock = $this->makePricedStock('STG'.strtoupper(Str::random(3)));

        $anchor = $this->makePricedStock('ANC'.strtoupper(Str::random(2)), 1000);
        Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $anchor->id,
            'quantity' => 100,
            'avg_buy_price' => 1000,
            'invested_amount' => 100000,
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
            'first_entry_pct' => 50.0,
            'max_new_positions_per_cycle' => 10,
            'min_cash_reserve_pct' => 0,
            'max_cash_deployment_pct' => 100,
        ]);
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

        $strategy = TradingStrategy::query()->findOrFail($version->strategy_id);

        return [$profile, $stock, $run, $strategy];
    }

    /**
     * @return array{0: PortfolioProfile, 1: Stock, 2: EvaluationRun, 3: TradingStrategy, 4: TradingStrategy}
     */
    protected function seedTwoStrategies(): array
    {
        [$profile, $stock, $run, $first] = $this->seedOpenReady();
        $second = TradingStrategy::query()->create([
            'profile_id' => $profile->id,
            'name' => 'Second Enabled',
            'slug' => 'second_'.Str::lower(Str::random(4)),
            'status' => TradingStrategy::STATUS_DRAFT,
            'allocation_pct' => 50,
            'is_factory' => false,
        ]);
        $v2 = TradingStrategyVersion::query()->create([
            'strategy_id' => $second->id,
            'version' => 1,
            'version_label' => '1.0',
            'config_json' => $first->activeVersion->config_json,
            'status' => TradingStrategyVersion::STATUS_DRAFT,
        ]);
        $second->forceFill(['active_version_id' => $v2->id])->save();
        app(StrategyRegistrySupport::class)->activate($profile, $second);
        $first->forceFill(['allocation_pct' => 50])->save();
        $second->fresh()->forceFill(['allocation_pct' => 50])->save();

        return [$profile, $stock, $run, $first->fresh(['activeVersion']), $second->fresh(['activeVersion'])];
    }

    protected function makePricedStock(string $symbol, float $close = 100): Stock
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

    protected function mockMarket(): void
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
