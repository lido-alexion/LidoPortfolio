<?php

namespace Tests\Feature;

use App\Engines\Recommendation\RecommendationEngine;
use App\Engines\Recommendation\RecommendationGenerationPipeline;
use App\Engines\Strategy\FactoryMomentumStrategy;
use App\Models\Candidate;
use App\Models\DiscoveryRun;
use App\Models\EvaluationResult;
use App\Models\EvaluationRun;
use App\Models\Holding;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\TradingRecommendation;
use App\Models\TradingStrategy;
use App\Models\User;
use App\Services\Analytics\MarketAnalyticsService;
use App\Services\StrategyConfigurationService;
use App\Support\TradingOsConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * F137 — Recommendation Preview API contract + shared decision core.
 */
class F137RecommendationPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_strategy_id_required_returns_422(): void
    {
        [$user, $profile, $stock, $run, $strategy] = $this->seedFixture();

        $this->actingAs($user)
            ->withProfileHeader($user, $profile)
            ->getJson("/api/v1/analytics/stocks/{$stock->id}/recommendation-preview")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'STRATEGY_ID_REQUIRED');
    }

    public function test_foreign_strategy_returns_404(): void
    {
        [$user, $profile, $stock, $run, $strategy] = $this->seedFixture();
        $other = User::query()->create([
            'name' => 'Other',
            'email' => 'other-'.Str::random(6).'@example.com',
            'password' => 'password123',
        ]);
        $otherProfile = $this->defaultPortfolioFor($other);
        $otherStrategy = app(StrategyConfigurationService::class)->ensureActive($otherProfile)->strategy;

        $this->actingAs($user)
            ->withProfileHeader($user, $profile)
            ->getJson("/api/v1/analytics/stocks/{$stock->id}/recommendation-preview?strategy_id={$otherStrategy->id}")
            ->assertStatus(404);
    }

    public function test_unknown_stock_returns_404(): void
    {
        [$user, $profile, $stock, $run, $strategy] = $this->seedFixture();

        $this->actingAs($user)
            ->withProfileHeader($user, $profile)
            ->getJson("/api/v1/analytics/stocks/999999/recommendation-preview?strategy_id={$strategy->id}")
            ->assertStatus(404);
    }

    public function test_unauthenticated_returns_401(): void
    {
        [$user, $profile, $stock, $run, $strategy] = $this->seedFixture();

        $this->getJson("/api/v1/analytics/stocks/{$stock->id}/recommendation-preview?strategy_id={$strategy->id}")
            ->assertStatus(401);
    }

    public function test_calculated_preview_matches_generation_canonical_action(): void
    {
        [$user, $profile, $stock, $run, $strategy] = $this->seedFixture();
        $this->mockMarket();

        $version = $strategy->activeVersion;
        $decision = app(RecommendationGenerationPipeline::class)->decideForSecurity(
            $profile,
            (int) $stock->id,
            $run,
            $version,
        );
        $this->assertTrue($decision['available']);
        $expectedCanonical = TradingRecommendation::toF137Canonical($decision['final_action']);

        $beforeCount = TradingRecommendation::query()->count();

        $res = $this->actingAs($user)
            ->withProfileHeader($user, $profile)
            ->getJson("/api/v1/analytics/stocks/{$stock->id}/recommendation-preview?strategy_id={$strategy->id}")
            ->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.execution.recommendation', $expectedCanonical)
            ->assertJsonPath('data.execution.source', 'calculated')
            ->assertJsonPath('data.execution.evaluation_cycle_id', $run->id);

        $this->assertSame($beforeCount, TradingRecommendation::query()->count());
        $this->assertNull($res->json('data.research.recommendation_id'));
        $this->assertContains($res->json('data.execution.recommendation'), ['BUY', 'SELL', 'HOLD_POSITION', 'WATCH']);
        $this->assertIsNumeric($res->json('data.execution.recommendation_score'));
        $this->assertSame('0_1', $res->json('data.research.confidence_unit'));

        $generated = app(RecommendationEngine::class)->generate($profile, $run);
        $rec = collect($generated['recommendations'])->firstWhere('security_id', $stock->id);
        $this->assertNotNull($rec);
        $this->assertSame(
            TradingRecommendation::toF137Canonical($rec->recommendation_type),
            $res->json('data.execution.recommendation'),
        );
    }

    public function test_preview_does_not_cancel_existing_open_recommendations(): void
    {
        [$user, $profile, $stock, $run, $strategy] = $this->seedFixture();
        $this->mockMarket();

        $stale = TradingRecommendation::query()->create([
            'profile_id' => $profile->id,
            'evaluation_result_id' => null,
            'strategy_version_id' => $strategy->active_version_id,
            'security_id' => $stock->id,
            'recommendation_type' => TradingRecommendation::ACTION_WATCH,
            'status' => TradingRecommendation::STATUS_PENDING_REVIEW,
            'strategy_score' => 50,
            'confidence' => 0.5,
            'priority' => 50,
            'evidence' => [],
            'generated_at' => now()->subDay(),
        ]);

        $this->actingAs($user)
            ->withProfileHeader($user, $profile)
            ->getJson("/api/v1/analytics/stocks/{$stock->id}/recommendation-preview?strategy_id={$strategy->id}")
            ->assertOk();

        $stale->refresh();
        $this->assertSame(TradingRecommendation::STATUS_PENDING_REVIEW, $stale->status);
    }

    public function test_stale_persisted_recommendation_is_ignored(): void
    {
        [$user, $profile, $stock, $run, $strategy] = $this->seedFixture();
        $this->mockMarket();

        $evalResultId = EvaluationResult::query()
            ->where('evaluation_run_id', $run->id)
            ->whereHas('candidate', fn ($q) => $q->where('security_id', $stock->id))
            ->value('id');

        TradingRecommendation::query()->create([
            'profile_id' => $profile->id,
            'evaluation_result_id' => $evalResultId,
            'strategy_version_id' => $strategy->active_version_id,
            'security_id' => $stock->id,
            'recommendation_type' => TradingRecommendation::ACTION_HOLD_POSITION,
            'status' => TradingRecommendation::STATUS_PUBLISHED,
            'strategy_score' => 40,
            'confidence' => 0.4,
            'priority' => 40,
            'evidence' => [],
            'reasoning' => 'Stale hold from prior cycle',
            'generated_at' => now()->subDay(),
        ]);

        // Newer completed evaluation cycle supersedes the persisted recommendation.
        $newer = EvaluationRun::query()->create([
            'profile_id' => $profile->id,
            'discovery_run_id' => $run->discovery_run_id,
            'status' => 'completed',
            'started_at' => now(),
            'completed_at' => now(),
        ]);
        $candidateId = Candidate::query()
            ->where('security_id', $stock->id)
            ->value('id');
        EvaluationResult::query()->create([
            'evaluation_run_id' => $newer->id,
            'candidate_id' => $candidateId,
            'score' => 95,
            'confidence' => 0.9,
            'rank' => 1,
            'evidence' => [
                'factor_scores' => [
                    'relative_strength' => 95,
                    'momentum_score' => 95,
                    'trend_score' => 95,
                    'breakout_score' => 95,
                    'volume_score' => 95,
                    'market_regime' => 95,
                    'sector_strength' => 95,
                    'risk_score' => 20,
                ],
                'indicator_scores' => [
                    'relative_strength' => 95,
                    'momentum_score' => 95,
                    'trend_score' => 95,
                    'breakout_score' => 95,
                    'volume_score' => 95,
                    'market_regime' => 95,
                    'sector_strength' => 95,
                    'risk_score' => 20,
                ],
                'indicators' => ['close' => 100, 'atr_pct' => 1.5],
            ],
            'passed_rules' => [],
            'failed_rules' => [],
            'created_at' => now(),
        ]);

        $this->actingAs($user)
            ->withProfileHeader($user, $profile)
            ->getJson("/api/v1/analytics/stocks/{$stock->id}/recommendation-preview?strategy_id={$strategy->id}")
            ->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.execution.source', 'calculated')
            ->assertJsonPath('data.execution.evaluation_cycle_id', $newer->id)
            ->assertJsonMissing(['reason_summary' => 'Stale hold from prior cycle']);
    }

    public function test_current_persisted_recommendation_is_used(): void
    {
        [$user, $profile, $stock, $run, $strategy] = $this->seedFixture();
        $this->mockMarket();

        $evalResultId = EvaluationResult::query()
            ->where('evaluation_run_id', $run->id)
            ->whereHas('candidate', fn ($q) => $q->where('security_id', $stock->id))
            ->value('id');

        $rec = TradingRecommendation::query()->create([
            'profile_id' => $profile->id,
            'evaluation_result_id' => $evalResultId,
            'strategy_version_id' => $strategy->active_version_id,
            'security_id' => $stock->id,
            'recommendation_type' => TradingRecommendation::ACTION_OPEN_POSITION,
            'status' => TradingRecommendation::STATUS_PENDING_REVIEW,
            'strategy_score' => 91.5,
            'confidence' => 0.88,
            'suggested_allocation_pct' => 6.5,
            'priority' => 91,
            'evidence' => [
                'eligibility' => ['mode' => 'unrestricted', 'screeners' => []],
            ],
            'reasoning' => 'Persisted open',
            'generated_at' => now(),
        ]);

        $this->actingAs($user)
            ->withProfileHeader($user, $profile)
            ->getJson("/api/v1/analytics/stocks/{$stock->id}/recommendation-preview?strategy_id={$strategy->id}")
            ->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.execution.source', 'persisted')
            ->assertJsonPath('data.execution.recommendation', 'BUY')
            ->assertJsonPath('data.research.recommendation_id', $rec->id)
            ->assertJsonPath('data.execution.recommendation_score', 91.5);
    }

    public function test_no_evaluation_cycle_returns_unavailable(): void
    {
        [$user, $profile, $stock, $run, $strategy] = $this->seedFixture();
        EvaluationResult::query()->where('evaluation_run_id', $run->id)->delete();
        EvaluationRun::query()->where('profile_id', $profile->id)->delete();

        $this->actingAs($user)
            ->withProfileHeader($user, $profile)
            ->getJson("/api/v1/analytics/stocks/{$stock->id}/recommendation-preview?strategy_id={$strategy->id}")
            ->assertOk()
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.execution.recommendation', null)
            ->assertJsonFragment(['code' => 'NO_EVALUATION_CYCLE']);
    }

    public function test_other_profile_persisted_recommendation_not_leaked(): void
    {
        [$user, $profile, $stock, $run, $strategy] = $this->seedFixture();
        $this->mockMarket();

        $other = User::query()->create([
            'name' => 'Other2',
            'email' => 'other2-'.Str::random(6).'@example.com',
            'password' => 'password123',
        ]);
        $otherProfile = $this->defaultPortfolioFor($other);

        $evalResultId = EvaluationResult::query()
            ->where('evaluation_run_id', $run->id)
            ->value('id');

        TradingRecommendation::query()->create([
            'profile_id' => $otherProfile->id,
            'evaluation_result_id' => $evalResultId,
            'strategy_version_id' => $strategy->active_version_id,
            'security_id' => $stock->id,
            'recommendation_type' => TradingRecommendation::ACTION_EXIT_POSITION,
            'status' => TradingRecommendation::STATUS_PENDING_REVIEW,
            'strategy_score' => 10,
            'confidence' => 0.2,
            'priority' => 10,
            'evidence' => [],
            'reasoning' => 'Other profile secret',
            'generated_at' => now(),
        ]);

        $res = $this->actingAs($user)
            ->withProfileHeader($user, $profile)
            ->getJson("/api/v1/analytics/stocks/{$stock->id}/recommendation-preview?strategy_id={$strategy->id}")
            ->assertOk();

        $this->assertNotSame('SELL', $res->json('data.execution.recommendation'));
        $this->assertNotEquals('Other profile secret', $res->json('data.research.reason_summary'));
    }

    public function test_f137_canonical_mapping(): void
    {
        $this->assertSame('BUY', TradingRecommendation::toF137Canonical(TradingRecommendation::ACTION_OPEN_POSITION));
        $this->assertSame('BUY', TradingRecommendation::toF137Canonical(TradingRecommendation::ACTION_INCREASE_POSITION));
        $this->assertSame('SELL', TradingRecommendation::toF137Canonical(TradingRecommendation::ACTION_EXIT_POSITION));
        $this->assertSame('SELL', TradingRecommendation::toF137Canonical(TradingRecommendation::ACTION_REDUCE_POSITION));
        $this->assertSame('HOLD_POSITION', TradingRecommendation::toF137Canonical(TradingRecommendation::ACTION_HOLD_POSITION));
        $this->assertSame('WATCH', TradingRecommendation::toF137Canonical(TradingRecommendation::ACTION_WATCH));
        $this->assertSame('BUY', TradingRecommendation::toF137Canonical('BUY'));
        $this->assertSame('SELL', TradingRecommendation::toF137Canonical('SELL'));
    }

    /**
     * @return array{0: User, 1: \App\Models\PortfolioProfile, 2: Stock, 3: EvaluationRun, 4: TradingStrategy}
     */
    protected function seedFixture(array $options = []): array
    {
        $user = User::query()->create([
            'name' => 'F137 User',
            'email' => 'f137-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);

        $stock = Stock::query()->create([
            'symbol' => 'F'.strtoupper(Str::random(4)),
            'exchange' => 'NSE',
            'name' => 'F137 Test Stock',
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

        $anchor = Stock::query()->create([
            'symbol' => 'A'.strtoupper(Str::random(4)),
            'exchange' => 'NSE',
            'name' => 'Anchor',
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
        ]);
        $config['exit_strategy'] = ['enabled' => false, 'mode' => 'any', 'rules' => []];
        $config[TradingOsConfig::STRATEGY_MARKET_GATES] = $options['market_gates'] ?? ['enabled' => false];
        $strategyVersion->forceFill(['config_json' => $config])->save();
        $strategy = $strategyVersion->strategy;

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

        $factorScores = [
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
                'factor_scores' => $factorScores,
                'indicator_scores' => $factorScores,
                'indicators' => ['close' => 100, 'atr_pct' => 1.5],
            ],
            'passed_rules' => [],
            'failed_rules' => [],
            'created_at' => now(),
        ]);

        return [$user, $profile, $stock, $run, $strategy->fresh(['activeVersion'])];
    }

    protected function mockMarket(array $market = []): void
    {
        $payload = array_replace_recursive([
            'available' => true,
            'market_phase' => 'Bull',
            'new_entry_allowed' => true,
            'allocation_multiplier' => 1.0,
            'sentiment' => ['score' => 70, 'label' => 'Bullish'],
            'risk' => ['label' => 'Medium', 'raw_risk' => 40],
        ], $market);

        $this->mock(MarketAnalyticsService::class, function ($mock) use ($payload) {
            $mock->shouldReceive('latest')->andReturn($payload);
            $mock->shouldReceive('summary')->andReturn($payload);
        });
    }
}
