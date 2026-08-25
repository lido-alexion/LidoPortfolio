<?php

namespace Tests\Feature\Risk;

use App\Engines\Recommendation\RecommendationEngine;
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
use App\Services\ProfileSettingsService;
use App\Services\Risk\ExitAttribution;
use App\Services\StrategyConfigurationService;
use App\Services\TransactionWriteService;
use App\Support\TradingOsConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * V3 Phase 2 — live EXIT generation persists §13.2 primary attribution.
 */
class ExitPrecedencePipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_generated_exit_persists_primary_attribution_from_portfolio_sl(): void
    {
        [$profile, $strategy, $stock, $run] = $this->seedHeldStrategy();
        $this->disableStrategyExits($strategy);
        app(ProfileSettingsService::class)->set($profile, 'default_stoploss_percent', '10');
        app(ProfileSettingsService::class)->set($profile, 'portfolio_trailing_percent', '50');
        app(CashManagementService::class)->deposit($profile, 500000, 'seed');
        $this->mockMarket();

        StockPrice::query()->create([
            'stock_id' => $stock->id,
            'price_date' => now()->toDateString(),
            'open_price' => 85,
            'high_price' => 85,
            'low_price' => 85,
            'close_price' => 85, // SL at 90
            'adjusted_close_price' => 200,
            'volume' => 1,
            'data_source' => 'test',
            'created_at' => now(),
        ]);

        $result = app(RecommendationEngine::class)->generate($profile, $run);
        $exit = collect($result['recommendations'])
            ->first(fn ($r) => $r->recommendation_type === TradingRecommendation::ACTION_EXIT_POSITION
                && (int) $r->security_id === (int) $stock->id);

        $this->assertNotNull($exit);
        $this->assertSame(ExitAttribution::STOP_LOSS, $exit->primaryExitReason());
        $this->assertSame(
            ExitAttribution::STOP_LOSS,
            $exit->evidence['exit_attribution']['primary_reason'] ?? null
        );
        $this->assertSame(
            ExitAttribution::STOP_LOSS,
            $exit->execution_plan['primary_exit_reason'] ?? null
        );
    }

    public function test_sell_transaction_copies_primary_exit_reason_from_recommendation(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $strategy = app(StrategyConfigurationService::class)->ensureActive($profile)->strategy;
        $stock = Stock::query()->create([
            'symbol' => 'CPY'.strtoupper(Str::random(3)),
            'exchange' => 'NSE',
            'name' => 'Copy Attr',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'strategy_id' => $strategy->id,
            'owner_key' => Holding::ownerKeyFor((int) $strategy->id),
            'quantity' => 5,
            'avg_buy_price' => 100,
            'invested_amount' => 500,
            'updated_at' => now(),
        ]);

        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 5,
            'price' => 100,
            'fees' => 0,
            'transaction_date' => now()->subDay()->toDateString(),
            'source' => Transaction::SOURCE_MANUAL,
        ]);

        $rec = TradingRecommendation::query()->create([
            'profile_id' => $profile->id,
            'security_id' => $stock->id,
            'strategy_version_id' => $strategy->active_version_id,
            'recommendation_type' => TradingRecommendation::ACTION_EXIT_POSITION,
            'status' => TradingRecommendation::STATUS_PENDING_EXECUTION,
            'priority' => 1,
            'strategy_score' => 10,
            'confidence' => 0.8,
            'risk_level' => 'medium',
            'execution_plan' => [
                'primary_exit_reason' => ExitAttribution::TRAILING_STOP,
                'exit_attribution' => [
                    'primary_reason' => ExitAttribution::TRAILING_STOP,
                    'also_true' => [],
                ],
            ],
            'evidence' => [
                'exit_attribution' => [
                    'primary_reason' => ExitAttribution::TRAILING_STOP,
                    'also_true' => [],
                ],
            ],
            'generated_at' => now(),
        ]);

        $tx = app(TransactionWriteService::class)->createFinancialUnit($profile, $stock, [
            'type' => 'sell',
            'quantity' => 5,
            'price' => 110,
            'fees' => 0,
            'transaction_date' => now()->toDateString(),
            'recommendation_id' => $rec->id,
        ], $user);

        $this->assertSame(ExitAttribution::TRAILING_STOP, $tx->exit_reason);
    }

    /**
     * @return array{0: PortfolioProfile, 1: TradingStrategy, 2: Stock, 3: EvaluationRun}
     */
    protected function seedHeldStrategy(): array
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $strategy = app(StrategyConfigurationService::class)->ensureActive($profile)->strategy;
        $stock = Stock::query()->create([
            'symbol' => 'SLX'.strtoupper(Str::random(3)),
            'exchange' => 'NSE',
            'name' => 'SL Exit',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        $version = TradingStrategyVersion::query()->findOrFail($strategy->active_version_id);
        $rec = TradingRecommendation::query()->create([
            'profile_id' => $profile->id,
            'security_id' => $stock->id,
            'strategy_version_id' => $version->id,
            'recommendation_type' => TradingRecommendation::ACTION_OPEN_POSITION,
            'status' => TradingRecommendation::STATUS_EXECUTED,
            'priority' => 1,
            'strategy_score' => 80,
            'confidence' => 0.8,
            'risk_level' => 'medium',
            'generated_at' => now(),
        ]);
        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 10,
            'price' => 100,
            'fees' => 0,
            'transaction_date' => now()->subDays(30)->toDateString(),
            'source' => Transaction::SOURCE_RECOMMENDATION,
            'recommendation_id' => $rec->id,
        ]);
        StockPrice::query()->create([
            'stock_id' => $stock->id,
            'price_date' => now()->subDays(30)->toDateString(),
            'open_price' => 100,
            'high_price' => 100,
            'low_price' => 100,
            'close_price' => 100,
            'volume' => 1,
            'data_source' => 'test',
            'created_at' => now(),
        ]);
        Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'strategy_id' => $strategy->id,
            'owner_key' => Holding::ownerKeyFor((int) $strategy->id),
            'quantity' => 10,
            'avg_buy_price' => 100,
            'invested_amount' => 1000,
            'updated_at' => now(),
        ]);

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
            'relative_strength' => 80,
            'momentum_score' => 80,
            'trend_score' => 80,
            'breakout_score' => 80,
            'volume_score' => 80,
            'market_regime' => 80,
            'sector_strength' => 80,
            'risk_score' => 20,
        ];
        EvaluationResult::query()->create([
            'evaluation_run_id' => $run->id,
            'candidate_id' => $candidate->id,
            'rank' => 1,
            'score' => 80,
            'confidence' => 0.8,
            'passed_rules' => [],
            'failed_rules' => [],
            'evidence' => [
                'indicator_scores' => $scores,
                'factor_scores' => $scores,
                'indicators' => ['close' => 85, 'atr_pct' => 2],
            ],
            'created_at' => now(),
        ]);

        return [$profile, $strategy, $stock, $run];
    }

    protected function disableStrategyExits(TradingStrategy $strategy): void
    {
        $version = TradingStrategyVersion::query()->findOrFail($strategy->active_version_id);
        $config = is_array($version->config_json) ? $version->config_json : [];
        $config['exit_strategy'] = ['enabled' => false, 'mode' => 'any', 'rules' => []];
        $config['eligibility_sources'] = [];
        $config[TradingOsConfig::STRATEGY_MARKET_GATES] = ['enabled' => false];
        $config[TradingOsConfig::STRATEGY_THRESHOLDS] = array_merge(
            is_array($config[TradingOsConfig::STRATEGY_THRESHOLDS] ?? null)
                ? $config[TradingOsConfig::STRATEGY_THRESHOLDS]
                : [],
            [
                TradingOsConfig::THRESHOLD_EXIT_POSITION => 20,
                TradingOsConfig::THRESHOLD_REDUCE_POSITION => 40,
                TradingOsConfig::THRESHOLD_OPEN_POSITION => 70,
                TradingOsConfig::THRESHOLD_WATCH => 50,
            ]
        );
        $version->forceFill(['config_json' => $config])->save();
    }

    protected function mockMarket(): void
    {
        $this->mock(MarketAnalyticsService::class, function ($mock) {
            $mock->shouldReceive('latest')->andReturn([
                'market_phase' => 'bull',
                'sentiment' => ['score' => 70, 'label' => 'positive'],
                'risk' => ['label' => 'low', 'raw_risk' => 20],
                'new_entry_allowed' => true,
                'allocation_multiplier' => 1.0,
            ]);
        });
    }
}
