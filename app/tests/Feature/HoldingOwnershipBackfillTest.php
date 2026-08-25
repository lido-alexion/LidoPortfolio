<?php

namespace Tests\Feature;

use App\Models\Holding;
use App\Models\Stock;
use App\Models\TradingRecommendation;
use App\Models\TradingStrategy;
use App\Models\TradingStrategyVersion;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Strategy\HoldingOwnershipBackfill;
use App\Services\Strategy\StrategyRegistrySupport;
use App\Services\StrategyConfigurationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * V3 §10.5 — conservative lot-level ownership backfill (not one-strategy heuristic).
 */
class HoldingOwnershipBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_one_strategy_unmanaged_without_recommendation_stays_unmanaged(): void
    {
        [$profile, $strategy, $stock] = $this->oneStrategyPortfolio();
        $holding = $this->unmanagedHolding($profile->id, $stock->id);
        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 10,
            'price' => 100,
            'fees' => 0,
            'transaction_date' => '2026-01-10',
            'source' => Transaction::SOURCE_MANUAL,
        ]);

        $updated = app(HoldingOwnershipBackfill::class)->inferForProfileId((int) $profile->id);

        $this->assertSame(0, $updated);
        $holding->refresh();
        $this->assertTrue($holding->isUnmanaged());
        $this->assertNull($holding->strategy_id);
        $this->assertSame(Holding::OWNER_UNMANAGED, $holding->owner_key);
        $this->assertSame((int) $strategy->id, (int) TradingStrategy::query()->where('profile_id', $profile->id)->value('id'));
    }

    public function test_b_one_strategy_recommendation_linked_holding_is_assigned(): void
    {
        [$profile, $strategy, $stock] = $this->oneStrategyPortfolio();
        $holding = $this->unmanagedHolding($profile->id, $stock->id);
        $this->recommendationBuy($profile->id, $stock->id, (int) $strategy->active_version_id, 10, '2026-01-10');

        $updated = app(HoldingOwnershipBackfill::class)->inferForProfileId((int) $profile->id);

        $this->assertSame(1, $updated);
        $holding->refresh();
        $this->assertFalse($holding->isUnmanaged());
        $this->assertSame((int) $strategy->id, (int) $holding->strategy_id);
        $this->assertSame(Holding::ownerKeyFor((int) $strategy->id), $holding->owner_key);
        $this->assertEqualsWithDelta(10.0, (float) $holding->quantity, 0.0001);
    }

    public function test_c_multiple_strategies_unmanaged_holding_stays_unmanaged(): void
    {
        [$profile, $first, $stock] = $this->oneStrategyPortfolio();
        $second = $this->makeStrategy($profile, 'Second');
        app(StrategyRegistrySupport::class)->activate($profile, $second);
        $holding = $this->unmanagedHolding($profile->id, $stock->id);
        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 8,
            'price' => 50,
            'fees' => 0,
            'transaction_date' => '2026-01-11',
            'source' => Transaction::SOURCE_MANUAL,
        ]);

        $updated = app(HoldingOwnershipBackfill::class)->inferForProfileId((int) $profile->id);

        $this->assertSame(0, $updated);
        $holding->refresh();
        $this->assertTrue($holding->isUnmanaged());
        $this->assertGreaterThanOrEqual(2, TradingStrategy::query()->where('profile_id', $profile->id)->count());
        $this->assertNotNull($first->id);
    }

    public function test_d_mixed_strategy_owned_and_unmanaged_lots_are_not_falsely_assigned(): void
    {
        [$profile, $strategy, $stock] = $this->oneStrategyPortfolio();
        $owned = Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'strategy_id' => $strategy->id,
            'owner_key' => Holding::ownerKeyFor((int) $strategy->id),
            'quantity' => 5,
            'avg_buy_price' => 100,
            'invested_amount' => 500,
            'updated_at' => now(),
        ]);
        $unmanaged = $this->unmanagedHolding($profile->id, $stock->id, 3, 90, 270);
        $this->recommendationBuy($profile->id, $stock->id, (int) $strategy->active_version_id, 5, '2026-01-10');
        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 3,
            'price' => 90,
            'fees' => 0,
            'transaction_date' => '2026-01-12',
            'source' => Transaction::SOURCE_MANUAL,
        ]);

        $updated = app(HoldingOwnershipBackfill::class)->inferForProfileId((int) $profile->id);

        $this->assertSame(0, $updated);
        $owned->refresh();
        $unmanaged->refresh();
        $this->assertSame((int) $strategy->id, (int) $owned->strategy_id);
        $this->assertTrue($unmanaged->isUnmanaged());
        $this->assertEqualsWithDelta(5.0, (float) $owned->quantity, 0.0001);
        $this->assertEqualsWithDelta(3.0, (float) $unmanaged->quantity, 0.0001);
    }

    public function test_e_re_running_backfill_does_not_change_resolved_ownership(): void
    {
        [$profile, $strategy, $stock] = $this->oneStrategyPortfolio();
        $holding = $this->unmanagedHolding($profile->id, $stock->id);
        $this->recommendationBuy($profile->id, $stock->id, (int) $strategy->active_version_id, 10, '2026-01-10');
        $svc = app(HoldingOwnershipBackfill::class);

        $this->assertSame(1, $svc->inferForProfileId((int) $profile->id));
        $holding->refresh();
        $ownerKey = $holding->owner_key;
        $strategyId = (int) $holding->strategy_id;

        $this->assertSame(0, $svc->inferForProfileId((int) $profile->id));
        $holding->refresh();
        $this->assertSame($ownerKey, $holding->owner_key);
        $this->assertSame($strategyId, (int) $holding->strategy_id);
        $this->assertEqualsWithDelta(10.0, (float) $holding->quantity, 0.0001);
    }

    public function test_f_existing_explicit_owner_key_is_preserved(): void
    {
        [$profile, $strategy, $stock] = $this->oneStrategyPortfolio();
        $holding = Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'strategy_id' => $strategy->id,
            'owner_key' => Holding::ownerKeyFor((int) $strategy->id),
            'quantity' => 4,
            'avg_buy_price' => 25,
            'invested_amount' => 100,
            'updated_at' => now(),
        ]);
        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 4,
            'price' => 25,
            'fees' => 0,
            'transaction_date' => '2026-01-10',
            'source' => Transaction::SOURCE_MANUAL,
        ]);

        $updated = app(HoldingOwnershipBackfill::class)->inferForProfileId((int) $profile->id);

        $this->assertSame(0, $updated);
        $holding->refresh();
        $this->assertSame((int) $strategy->id, (int) $holding->strategy_id);
        $this->assertSame(Holding::ownerKeyFor((int) $strategy->id), $holding->owner_key);
        $this->assertFalse($holding->isUnmanaged());
    }

    public function test_mixed_contributing_buys_on_one_row_stay_unmanaged(): void
    {
        [$profile, $strategy, $stock] = $this->oneStrategyPortfolio();
        $holding = $this->unmanagedHolding($profile->id, $stock->id, 15, 100, 1500);
        $this->recommendationBuy($profile->id, $stock->id, (int) $strategy->active_version_id, 10, '2026-01-10');
        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 5,
            'price' => 100,
            'fees' => 0,
            'transaction_date' => '2026-01-11',
            'source' => Transaction::SOURCE_MANUAL,
        ]);

        $updated = app(HoldingOwnershipBackfill::class)->inferForProfileId((int) $profile->id);

        $this->assertSame(0, $updated);
        $holding->refresh();
        $this->assertTrue($holding->isUnmanaged());
    }

    public function test_revert_unattested_heuristic_then_infer_from_lots(): void
    {
        [$profile, $strategy, $stock] = $this->oneStrategyPortfolio();
        $heuristic = Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'strategy_id' => $strategy->id,
            'owner_key' => Holding::ownerKeyFor((int) $strategy->id),
            'quantity' => 2,
            'avg_buy_price' => 40,
            'invested_amount' => 80,
            'updated_at' => now(),
        ]);
        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 2,
            'price' => 40,
            'fees' => 0,
            'transaction_date' => '2026-01-10',
            'source' => Transaction::SOURCE_MANUAL,
        ]);
        $svc = app(HoldingOwnershipBackfill::class);

        $this->assertSame(1, $svc->revertUnattestedStrategyOwnershipForProfile((int) $profile->id));
        $heuristic->refresh();
        $this->assertTrue($heuristic->isUnmanaged());

        $this->assertSame(0, $svc->inferForProfileId((int) $profile->id));
        $heuristic->refresh();
        $this->assertTrue($heuristic->isUnmanaged());
    }

    /**
     * @return array{0: \App\Models\PortfolioProfile, 1: TradingStrategy, 2: Stock}
     */
    protected function oneStrategyPortfolio(): array
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $version = app(StrategyConfigurationService::class)->ensureActive($profile);

        return [$profile, $version->strategy, $this->makeStock()];
    }

    protected function unmanagedHolding(
        int $profileId,
        int $stockId,
        float $qty = 10,
        float $avg = 100,
        float $invested = 1000,
    ): Holding {
        return Holding::query()->create([
            'profile_id' => $profileId,
            'stock_id' => $stockId,
            'quantity' => $qty,
            'avg_buy_price' => $avg,
            'invested_amount' => $invested,
            'updated_at' => now(),
        ]);
    }

    protected function recommendationBuy(
        int $profileId,
        int $stockId,
        int $strategyVersionId,
        float $qty,
        string $date,
    ): Transaction {
        $rec = TradingRecommendation::query()->create([
            'profile_id' => $profileId,
            'security_id' => $stockId,
            'strategy_version_id' => $strategyVersionId,
            'recommendation_type' => TradingRecommendation::ACTION_OPEN_POSITION,
            'status' => TradingRecommendation::STATUS_EXECUTED,
            'priority' => 1,
            'strategy_score' => 80,
            'confidence' => 0.8,
            'risk_level' => 'medium',
            'generated_at' => $date.' 10:00:00',
        ]);

        return Transaction::query()->create([
            'profile_id' => $profileId,
            'stock_id' => $stockId,
            'type' => 'buy',
            'quantity' => $qty,
            'price' => 100,
            'fees' => 0,
            'transaction_date' => $date,
            'source' => Transaction::SOURCE_RECOMMENDATION,
            'recommendation_id' => $rec->id,
        ]);
    }

    protected function makeStrategy(\App\Models\PortfolioProfile $profile, string $name): TradingStrategy
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

    protected function makeStock(): Stock
    {
        return Stock::query()->create([
            'symbol' => 'BF'.strtoupper(Str::random(3)),
            'exchange' => 'NSE',
            'name' => 'Backfill Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);
    }
}
