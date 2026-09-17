<?php

namespace Tests\Feature;

use App\Engines\Execution\ExecutionEngine;
use App\Engines\Recommendation\RecommendationLifecycleService;
use App\Models\Holding;
use App\Models\HoldingAdoption;
use App\Models\PortfolioProfile;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\TradingRecommendation;
use App\Models\TradingStrategy;
use App\Models\TradingStrategyVersion;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CashManagementService;
use App\Services\Strategy\PortfolioCapitalAccountingService;
use App\Services\Strategy\StrategyRegistrySupport;
use App\Services\StrategyConfigurationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * MS-001: executable assurance for strategy provenance, reservations, execution,
 * ownership, adoption, and cross-profile isolation.
 */
class MultiStrategyLifecycleAssuranceTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_strategy_lifecycle_preserves_provenance_and_reconciles_capital(): void
    {
        [$user, $profile, $strategyA, $strategyB] = $this->twoStrategyPortfolio();
        $stock = $this->stock('LIFE');
        app(CashManagementService::class)->deposit($profile, 100_000, 'ms-001-seed', $user);

        $initial = app(PortfolioCapitalAccountingService::class)->snapshot($profile);
        $this->assertSame(1, $initial['physical_cash']['cash_account_count']);
        $this->assertSame(0, $initial['physical_cash']['strategy_physical_cash_accounts']);
        $this->assertEqualsWithDelta(60.0, $this->strategySnapshot($initial, $strategyA)['allocation_pct'], 0.0001);
        $this->assertEqualsWithDelta(40.0, $this->strategySnapshot($initial, $strategyB)['allocation_pct'], 0.0001);
        $this->assertEqualsWithDelta(0.0, $initial['physical_cash']['pending_execution_reservations'], 0.0001);

        $recommendation = $this->buyRecommendation($profile, $strategyA, $stock, 6_000);
        $this->assertSame(TradingRecommendation::STATUS_PENDING_REVIEW, $recommendation->status);
        $this->assertSame($strategyA->active_version_id, $recommendation->strategy_version_id);

        $review = app(RecommendationLifecycleService::class)->recordReview(
            $profile,
            $user,
            $recommendation,
            TradingRecommendation::DECISION_APPROVED,
            'MS-001 approval',
        );
        $review->refresh();

        $this->assertSame(TradingRecommendation::STATUS_PENDING_EXECUTION, $review->status);
        $this->assertEqualsWithDelta(6_000.0, (float) $review->reserved_amount, 0.0001, json_encode($review->toArray()));
        $this->assertSame(TradingRecommendation::RESERVATION_RESERVED, $review->reservation_status);
        $this->assertSame(0, Transaction::query()->where('recommendation_id', $review->id)->count());
        $this->assertSame(0, Holding::query()->where('profile_id', $profile->id)->where('stock_id', $stock->id)->count());

        $reserved = app(PortfolioCapitalAccountingService::class)->snapshot($profile);
        $this->assertEqualsWithDelta(6_000.0, $reserved['physical_cash']['pending_execution_reservations'], 0.0001);
        $this->assertEqualsWithDelta(6_000.0, $this->strategySnapshot($reserved, $strategyA)['pending_execution_reserved'], 0.0001);
        $this->assertEqualsWithDelta(0.0, $this->strategySnapshot($reserved, $strategyB)['pending_execution_reserved'], 0.0001);

        $execution = app(ExecutionEngine::class)->recordOrder($profile, $stock, [
            'side' => 'buy',
            'quantity' => 60,
            'price' => 100,
            'recommendation_id' => $review->id,
            'execute_now' => true,
        ]);

        $transaction = $execution['transaction']->fresh();
        $holding = Holding::query()
            ->where('profile_id', $profile->id)
            ->where('stock_id', $stock->id)
            ->where('owner_key', Holding::ownerKeyFor((int) $strategyA->id))
            ->sole();

        $this->assertSame($review->id, $transaction->recommendation_id);
        $this->assertSame(Holding::ownerKeyFor((int) $strategyA->id), $transaction->owner_key);
        $this->assertSame(
            $review->id,
            Transaction::query()
                ->where('profile_id', $profile->id)
                ->where('stock_id', $stock->id)
                ->where('owner_key', Holding::ownerKeyFor((int) $strategyA->id))
                ->latest('id')
                ->value('recommendation_id')
        );
        $this->assertEqualsWithDelta(60.0, (float) $holding->quantity, 0.0001);
        $this->assertSame(TradingRecommendation::STATUS_EXECUTED, $review->fresh()->status);
        $this->assertSame(TradingRecommendation::RESERVATION_CONVERTED, $review->fresh()->reservation_status);
        $this->assertEqualsWithDelta(0.0, (float) $review->fresh()->reserved_amount, 0.0001);

        $final = app(PortfolioCapitalAccountingService::class)->snapshot($profile);
        $this->assertEqualsWithDelta(6_000.0, $this->strategySnapshot($final, $strategyA)['strategy_owned_market_value'], 0.0001);
        $this->assertEqualsWithDelta(0.0, $this->strategySnapshot($final, $strategyB)['strategy_owned_market_value'], 0.0001);
        $this->assertEqualsWithDelta(0.0, $final['physical_cash']['pending_execution_reservations'], 0.0001);
        $this->assertEqualsWithDelta(94_000.0, $final['physical_cash']['total_cash'], 0.0001);
    }

    public function test_same_stock_execution_keeps_sibling_strategy_ownership_episodes_separate(): void
    {
        [$user, $profile, $strategyA, $strategyB] = $this->twoStrategyPortfolio();
        $stock = $this->stock('SAME');
        app(CashManagementService::class)->deposit($profile, 100_000, 'ms-001-same-stock', $user);

        $recA = $this->approve($profile, $user, $this->buyRecommendation($profile, $strategyA, $stock, 1_000));
        $recB = $this->approve($profile, $user, $this->buyRecommendation($profile, $strategyB, $stock, 2_000));
        app(ExecutionEngine::class)->recordOrder($profile, $stock, [
            'side' => 'buy', 'quantity' => 10, 'price' => 100,
            'recommendation_id' => $recA->id, 'execute_now' => true,
        ]);
        app(ExecutionEngine::class)->recordOrder($profile, $stock, [
            'side' => 'buy', 'quantity' => 20, 'price' => 100,
            'recommendation_id' => $recB->id, 'execute_now' => true,
        ]);

        $holdings = Holding::query()->where('profile_id', $profile->id)->where('stock_id', $stock->id)->get();
        $this->assertCount(2, $holdings);
        $this->assertEqualsWithDelta(10.0, (float) $holdings->firstWhere('strategy_id', $strategyA->id)->quantity, 0.0001);
        $this->assertEqualsWithDelta(20.0, (float) $holdings->firstWhere('strategy_id', $strategyB->id)->quantity, 0.0001);
        $this->assertSame($strategyA->active_version_id, $recA->fresh()->strategy_version_id);
        $this->assertSame($strategyB->active_version_id, $recB->fresh()->strategy_version_id);
    }

    public function test_unmanaged_adoption_merges_only_into_selected_strategy_and_preserves_history(): void
    {
        [$user, $profile, $strategyA, $strategyB] = $this->twoStrategyPortfolio();
        $stock = $this->stock('ADOPT');
        $destination = $this->ownedHolding($profile, $strategyA, $stock, 10, 100, 1_000);
        $sibling = $this->ownedHolding($profile, $strategyB, $stock, 5, 120, 600);
        $unmanaged = Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'quantity' => 4,
            'avg_buy_price' => 110,
            'invested_amount' => 440,
            'updated_at' => now(),
        ]);
        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 4,
            'price' => 110,
            'fees' => 0,
            'transaction_date' => '2024-01-02',
            'source' => Transaction::SOURCE_MANUAL,
        ]);

        $response = $this->actingAs($user)
            ->withProfileHeader($user, $profile)
            ->postJson('/api/holdings/'.$unmanaged->id.'/adopt', ['strategy_id' => $strategyA->id])
            ->assertOk();

        $merged = Holding::query()->findOrFail($response->json('data.id'));
        $this->assertSame((int) $destination->id, (int) $merged->id);
        $this->assertSame((int) $strategyA->id, (int) $merged->strategy_id);
        $this->assertEqualsWithDelta(14.0, (float) $merged->quantity, 0.0001);
        $this->assertEqualsWithDelta(1_440.0, (float) $merged->invested_amount, 0.0001);
        $this->assertEqualsWithDelta(102.86, (float) $merged->avg_buy_price, 0.001);
        $this->assertEqualsWithDelta(5.0, (float) $sibling->fresh()->quantity, 0.0001);
        $this->assertSame(1, HoldingAdoption::query()->where('holding_id', $merged->id)->count());
        $this->assertSame(1, TradingRecommendation::query()->where('recommendation_type', TradingRecommendation::ACTION_HOLD_POSITION)->count());
    }

    public function test_cancelled_approved_buy_releases_reservation_without_execution(): void
    {
        [$user, $profile, $strategyA] = $this->twoStrategyPortfolio();
        $stock = $this->stock('RELEASE');
        app(CashManagementService::class)->deposit($profile, 100_000, 'ms-001-release', $user);
        $recommendation = $this->approve($profile, $user, $this->buyRecommendation($profile, $strategyA, $stock, 2_500));

        app(RecommendationLifecycleService::class)->cancelExecution(
            $profile,
            $user,
            $recommendation,
            'funds_unavailable',
            'MS-001 release',
        );

        $cancelled = $recommendation->fresh();
        $this->assertSame(TradingRecommendation::STATUS_CANCELLED, $cancelled->status);
        $this->assertSame(TradingRecommendation::RESERVATION_RELEASED, $cancelled->reservation_status);
        $this->assertEqualsWithDelta(0.0, (float) $cancelled->reserved_amount, 0.0001);
        $this->assertSame(0, Transaction::query()->where('recommendation_id', $recommendation->id)->count());
        $this->assertSame(0, Holding::query()->where('profile_id', $profile->id)->where('stock_id', $stock->id)->count());
        $this->assertEqualsWithDelta(0.0, app(PortfolioCapitalAccountingService::class)->snapshot($profile)['physical_cash']['pending_execution_reservations'], 0.0001);
    }

    public function test_cross_profile_adoption_cannot_mutate_foreign_holding_or_strategy(): void
    {
        [$owner, $profile, $strategyA] = $this->twoStrategyPortfolio();
        $stock = $this->stock('AUTH');
        $foreign = User::factory()->create();
        $foreignProfile = $this->defaultPortfolioFor($foreign);
        $foreignStrategy = app(StrategyConfigurationService::class)->ensureActive($foreignProfile)->strategy;
        $holding = Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'quantity' => 2,
            'avg_buy_price' => 100,
            'invested_amount' => 200,
            'updated_at' => now(),
        ]);
        $recommendation = $this->buyRecommendation($profile, $strategyA, $stock, 500);

        $this->actingAs($foreign)
            ->withProfileHeader($foreign, $foreignProfile)
            ->postJson('/api/holdings/'.$holding->id.'/adopt', ['strategy_id' => $foreignStrategy->id])
            ->assertNotFound();

        $this->assertTrue(Holding::query()->whereKey($holding->id)->whereNull('strategy_id')->exists());
        $this->assertSame((int) $profile->id, (int) $holding->fresh()->profile_id);
        $this->assertSame((int) $strategyA->profile_id, (int) $profile->id);

        $this->actingAs($foreign)
            ->withProfileHeader($foreign, $foreignProfile)
            ->postJson('/api/v1/recommendations/'.$recommendation->id.'/review', ['decision' => 'approved'])
            ->assertNotFound();

        $this->assertSame(
            TradingRecommendation::STATUS_PENDING_REVIEW,
            $recommendation->fresh()->status,
        );
    }

    /** @return array{0: User, 1: PortfolioProfile, 2: TradingStrategy, 3: TradingStrategy} */
    private function twoStrategyPortfolio(): array
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $first = app(StrategyConfigurationService::class)->ensureActive($profile)->strategy;
        $second = TradingStrategy::query()->create([
            'profile_id' => $profile->id,
            'name' => 'Strategy B',
            'slug' => 'strategy_b_'.Str::lower(Str::random(4)),
            'status' => TradingStrategy::STATUS_DRAFT,
            'allocation_pct' => 40,
            'is_factory' => false,
        ]);
        $version = TradingStrategyVersion::query()->create([
            'strategy_id' => $second->id,
            'version' => 1,
            'version_label' => '1.0',
            'config_json' => $first->activeVersion?->config_json ?? ['indicators' => []],
            'status' => TradingStrategyVersion::STATUS_DRAFT,
        ]);
        $second->forceFill(['active_version_id' => $version->id])->save();
        $second = app(StrategyRegistrySupport::class)->activate($profile, $second);
        $first->forceFill(['allocation_pct' => 60])->save();
        app(PortfolioCapitalAccountingService::class)->updateEnabledAllocations($profile, [
            ['strategy_id' => $first->id, 'allocation_pct' => 60],
            ['strategy_id' => $second->id, 'allocation_pct' => 40],
        ]);

        return [$user, $profile, $first->fresh(['activeVersion']), $second->fresh(['activeVersion'])];
    }

    private function stock(string $prefix): Stock
    {
        $stock = Stock::query()->create([
            'symbol' => $prefix.strtoupper(Str::random(3)),
            'exchange' => 'NSE',
            'name' => 'MS-001 Test Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        StockPrice::query()->create([
            'stock_id' => $stock->id,
            'price_date' => now()->toDateString(),
            'open_price' => 100,
            'high_price' => 100,
            'low_price' => 100,
            'close_price' => 100,
            'volume' => 1_000,
            'data_source' => 'test',
            'provider_source' => 'test',
        ]);

        return $stock;
    }

    private function buyRecommendation(PortfolioProfile $profile, TradingStrategy $strategy, Stock $stock, float $amount): TradingRecommendation
    {
        return TradingRecommendation::query()->create([
            'profile_id' => $profile->id,
            'security_id' => $stock->id,
            'strategy_version_id' => $strategy->active_version_id,
            'recommendation_type' => TradingRecommendation::ACTION_OPEN_POSITION,
            'status' => TradingRecommendation::STATUS_PENDING_REVIEW,
            'priority' => 1,
            'strategy_score' => 80,
            'confidence' => 0.8,
            'risk_level' => 'medium',
            'suggested_allocation_amount' => $amount,
            'reference_price' => 100,
            'execution_plan' => [
                'side' => 'buy',
                'suggested_quantity' => $amount / 100,
                'suggested_investment_amount' => $amount,
                'capital_allocation' => ['status' => TradingRecommendation::ALLOCATION_FUNDED],
            ],
            'evidence' => ['capital_allocation' => ['status' => TradingRecommendation::ALLOCATION_FUNDED]],
            'generated_at' => now(),
        ]);
    }

    private function approve(PortfolioProfile $profile, User $user, TradingRecommendation $recommendation): TradingRecommendation
    {
        return app(RecommendationLifecycleService::class)->recordReview(
            $profile,
            $user,
            $recommendation,
            TradingRecommendation::DECISION_APPROVED,
        );
    }

    private function ownedHolding(PortfolioProfile $profile, TradingStrategy $strategy, Stock $stock, float $quantity, float $price, float $invested): Holding
    {
        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => $quantity,
            'price' => $price,
            'fees' => 0,
            'transaction_date' => '2024-01-01',
            'source' => Transaction::SOURCE_MANUAL,
            'owner_key' => Holding::ownerKeyFor((int) $strategy->id),
        ]);

        return Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'strategy_id' => $strategy->id,
            'owner_key' => Holding::ownerKeyFor((int) $strategy->id),
            'quantity' => $quantity,
            'avg_buy_price' => $price,
            'invested_amount' => $invested,
            'updated_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $snapshot */
    private function strategySnapshot(array $snapshot, TradingStrategy $strategy): array
    {
        return collect($snapshot['strategies'] ?? [])->firstWhere('strategy_id', $strategy->id) ?? [];
    }
}
