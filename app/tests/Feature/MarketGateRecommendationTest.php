<?php

namespace Tests\Feature;

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
use App\Services\StrategyConfigurationService;
use App\Support\TradingOsConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MarketGateRecommendationTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_demoted_to_watch_when_gates_block_entry(): void
    {
        [$profile, $stock, $run] = $this->seedRecommendationFixture([
            'market_gates' => [
                'enabled' => true,
                'min_sentiment' => 80,
                'allowed_phases' => ['Bull'],
                'max_risk_raw' => 70,
            ],
        ]);

        $this->mockMarket([
            'sentiment' => ['score' => 55, 'label' => 'Neutral'],
            'market_phase' => 'Bull',
            'new_entry_allowed' => true,
            'allocation_multiplier' => 1.0,
            'risk' => ['label' => 'Medium', 'raw_risk' => 40],
        ]);

        $result = app(RecommendationEngine::class)->generate($profile, $run);
        $rec = collect($result['recommendations'])->firstWhere('security_id', $stock->id);

        $this->assertNotNull($rec);
        $this->assertSame(TradingRecommendation::ACTION_WATCH, $rec->recommendation_type);
        $this->assertTrue($rec->evidence['market_gate_demoted'] ?? false);
        $this->assertFalse($rec->evidence['market_analysis']['effective_new_entry_allowed'] ?? true);
        $this->assertContains('Sentiment below strategy minimum', $rec->evidence['market_gates']['block_reasons'] ?? []);
        $this->assertStringContainsString('Market gates demoted entry', (string) $rec->reasoning);
    }

    public function test_increase_demoted_to_hold_when_gates_block_entry(): void
    {
        [$profile, $stock, $run] = $this->seedRecommendationFixture([
            'market_gates' => [
                'enabled' => true,
                'min_sentiment' => 80,
            ],
        ]);

        Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'strategy_id' => TradingStrategy::query()
                ->where('profile_id', $profile->id)
                ->where('status', TradingStrategy::STATUS_ACTIVE)
                ->value('id'),
            'quantity' => 10,
            'avg_buy_price' => 100,
            'invested_amount' => 1000,
            'updated_at' => now(),
        ]);

        $this->mockMarket([
            'sentiment' => ['score' => 50, 'label' => 'Neutral'],
            'market_phase' => 'Bull',
            'new_entry_allowed' => true,
        ]);

        $rec = collect(app(RecommendationEngine::class)->generate($profile, $run)['recommendations'])
            ->firstWhere('security_id', $stock->id);

        $this->assertNotNull($rec);
        $this->assertSame(TradingRecommendation::ACTION_HOLD_POSITION, $rec->recommendation_type);
        $this->assertTrue($rec->evidence['market_gate_demoted'] ?? false);
    }

    public function test_reduce_and_exit_remain_allowed_when_gates_block_entry(): void
    {
        [$profile, $stock, $run] = $this->seedRecommendationFixture([
            'market_gates' => ['enabled' => true, 'min_sentiment' => 99],
            'evaluation_score' => 15,
            'factor_scores' => [
                'relative_strength' => 10,
                'momentum_score' => 10,
                'trend_score' => 10,
                'breakout_score' => 10,
                'volume_score' => 10,
                'market_regime' => 10,
                'sector_strength' => 10,
                'risk_score' => 10,
            ],
        ]);

        Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'strategy_id' => TradingStrategy::query()
                ->where('profile_id', $profile->id)
                ->where('status', TradingStrategy::STATUS_ACTIVE)
                ->value('id'),
            'quantity' => 10,
            'avg_buy_price' => 100,
            'invested_amount' => 1000,
            'updated_at' => now(),
        ]);

        $this->mockMarket([
            'sentiment' => ['score' => 20, 'label' => 'Bearish'],
            'market_phase' => 'Bear',
            'new_entry_allowed' => false,
        ]);

        $rec = collect(app(RecommendationEngine::class)->generate($profile, $run)['recommendations'])
            ->firstWhere('security_id', $stock->id);

        $this->assertNotNull($rec);
        $this->assertSame(TradingRecommendation::ACTION_EXIT_POSITION, $rec->recommendation_type);
        $this->assertFalse($rec->evidence['market_gate_demoted'] ?? true);
    }

    public function test_open_allowed_when_gates_pass(): void
    {
        [$profile, $stock, $run] = $this->seedRecommendationFixture([
            'market_gates' => [
                'enabled' => true,
                'min_sentiment' => 45,
                'allowed_phases' => ['Bull'],
                'max_risk_raw' => 70,
            ],
        ]);

        app(CashManagementService::class)->deposit($profile, 100000, 'test seed');

        $this->mockMarket([
            'sentiment' => ['score' => 70, 'label' => 'Bullish'],
            'market_phase' => 'Bull',
            'new_entry_allowed' => true,
            'allocation_multiplier' => 1.0,
            'risk' => ['label' => 'Medium', 'raw_risk' => 40],
        ]);

        $rec = collect(app(RecommendationEngine::class)->generate($profile, $run)['recommendations'])
            ->firstWhere('security_id', $stock->id);

        $this->assertNotNull($rec);
        $this->assertSame(TradingRecommendation::ACTION_OPEN_POSITION, $rec->recommendation_type);
        $this->assertFalse($rec->evidence['market_gate_demoted'] ?? true);
        $this->assertTrue($rec->evidence['market_analysis']['effective_new_entry_allowed'] ?? false);
    }

    public function test_allocation_multiplier_applied_when_gates_pass(): void
    {
        [$profile, $stock, $run] = $this->seedRecommendationFixture([
            'market_gates' => ['enabled' => false],
        ]);

        $this->mockMarket([
            'sentiment' => ['score' => 70, 'label' => 'Bullish'],
            'market_phase' => 'Bull',
            'new_entry_allowed' => true,
            'allocation_multiplier' => 0.5,
        ]);

        app(RecommendationEngine::class)->generate($profile, $run);

        $rec = TradingRecommendation::query()
            ->where('profile_id', $profile->id)
            ->where('security_id', $stock->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($rec);
        $this->assertEqualsWithDelta(0.5, $rec->evidence['market_analysis']['effective_allocation_multiplier'] ?? 0, 0.0001);
        $this->assertLessThan(6.0, (float) $rec->target_allocation_pct);
    }

    public function test_insufficient_cash_keeps_open_unfunded(): void
    {
        [$profile, $stock, $run] = $this->seedRecommendationFixture([
            'market_gates' => ['enabled' => false],
        ]);

        $this->mockMarket([
            'sentiment' => ['score' => 70, 'label' => 'Bullish'],
            'market_phase' => 'Bull',
            'new_entry_allowed' => true,
            'allocation_multiplier' => 1.0,
        ]);

        $rec = collect(app(RecommendationEngine::class)->generate($profile, $run)['recommendations'])
            ->firstWhere('security_id', $stock->id);

        $this->assertNotNull($rec);
        $this->assertSame(TradingRecommendation::ACTION_OPEN_POSITION, $rec->recommendation_type);
        $this->assertSame(TradingRecommendation::ALLOCATION_UNFUNDED, $rec->evidence['capital_allocation']['status'] ?? null);
        $this->assertEqualsWithDelta(
            (float) ($rec->evidence['capital_allocation']['target_amount'] ?? 0),
            (float) ($rec->execution_plan['target_investment_amount'] ?? 0),
            0.01
        );
        $this->assertGreaterThan(0, (float) ($rec->evidence['capital_allocation']['target_amount'] ?? 0));
        $this->assertFalse($rec->evidence['market_gate_demoted'] ?? true);
    }

    public function test_strategy_without_market_gates_unchanged(): void
    {
        [$profile, $stock, $run] = $this->seedRecommendationFixture([
            'market_gates' => ['enabled' => false],
        ]);

        app(CashManagementService::class)->deposit($profile, 100000, 'test seed');

        $this->mockMarket([
            'sentiment' => ['score' => 30, 'label' => 'Bearish'],
            'market_phase' => 'Bear',
            'new_entry_allowed' => true,
        ]);

        $rec = collect(app(RecommendationEngine::class)->generate($profile, $run)['recommendations'])
            ->firstWhere('security_id', $stock->id);

        $this->assertNotNull($rec);
        $this->assertSame(TradingRecommendation::ACTION_OPEN_POSITION, $rec->recommendation_type);
        $this->assertFalse($rec->evidence['market_gates']['enabled'] ?? true);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{0: PortfolioProfile, 1: Stock, 2: EvaluationRun}
     */
    protected function seedRecommendationFixture(array $options = []): array
    {
        $user = User::query()->create([
            'name' => 'Gate User',
            'email' => 'gate-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);

        $stock = Stock::query()->create([
            'symbol' => 'G'.strtoupper(Str::random(4)),
            'exchange' => 'NSE',
            'name' => 'Gate Test Stock',
            'is_active' => true,
        ]);
        StockPrice::query()->create([
            'stock_id' => $stock->id,
            'price_date' => now()->toDateString(),
            'open_price' => 100,
            'high_price' => 101,
            'low_price' => 99,
            'close_price' => 100,
            'volume' => 10000,
            'data_source' => 'test',
        ]);

        if ($options['with_anchor_holding'] ?? true) {
            $anchor = Stock::query()->create([
                'symbol' => 'A'.strtoupper(Str::random(4)),
                'exchange' => 'NSE',
                'name' => 'Anchor Stock',
                'is_active' => true,
            ]);
            StockPrice::query()->create([
                'stock_id' => $anchor->id,
                'price_date' => now()->toDateString(),
                'open_price' => 1000,
                'high_price' => 1001,
                'low_price' => 999,
                'close_price' => 1000,
                'volume' => 10000,
                'data_source' => 'test',
            ]);
            Holding::query()->create([
                'profile_id' => $profile->id,
                'stock_id' => $anchor->id,
                'quantity' => 100,
                'avg_buy_price' => 1000,
                'invested_amount' => 100000,
                'updated_at' => now(),
            ]);
        }

        $strategyVersion = app(StrategyConfigurationService::class)->ensureActive($profile);
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
            'default_position_size_pct' => 6.0,
            'max_position_size_pct' => 10.0,
            'max_new_positions_per_cycle' => 5,
            'min_cash_reserve_pct' => 0,
            'max_cash_deployment_pct' => 100,
            // Keep the gate tests above OD-12's ₹5,000 minimum actionable
            // amount so WATCH means a gate decision, not tranche suppression.
            'first_entry_pct' => 100.0,
        ]);
        $config['exit_strategy'] = ['enabled' => false, 'mode' => 'any', 'rules' => []];
        $config[TradingOsConfig::STRATEGY_MARKET_GATES] = $options['market_gates'] ?? ['enabled' => false];
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

        $factorScores = $options['factor_scores'] ?? [
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
            'score' => $options['evaluation_score'] ?? 95,
            'confidence' => 0.9,
            'rank' => 1,
            'evidence' => [
                'factor_scores' => $factorScores,
                'indicator_scores' => $factorScores,
                'indicators' => ['close' => 100, 'atr_pct' => 1.5],
            ],
            'passed_rules' => [],
            'failed_rules' => [],
            'created_at' => now(),
        ]);

        return [$profile, $stock, $run];
    }

    /**
     * @param  array<string, mixed>  $market
     */
    protected function mockMarket(array $market): void
    {
        $payload = array_replace_recursive([
            'available' => true,
            'market_phase' => 'Bull',
            'new_entry_allowed' => true,
            'allocation_multiplier' => 1.0,
            'sentiment' => ['score' => 65, 'label' => 'Bullish'],
            'risk' => ['label' => 'Medium', 'raw_risk' => 50],
        ], $market);

        $this->mock(MarketAnalyticsService::class, function ($mock) use ($payload): void {
            $mock->shouldReceive('latest')->andReturn($payload);
        });
    }
}
