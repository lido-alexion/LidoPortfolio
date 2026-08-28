<?php

namespace Tests\Feature;

use App\Models\CapitalRequest;
use App\Models\Holding;
use App\Models\HoldingAdoption;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\TradingRecommendation;
use App\Models\TradingStrategy;
use App\Models\TradingStrategyVersion;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Entry\StrategyPositionTargetService;
use App\Services\Ownership\HoldingAdoptionService;
use App\Services\Risk\OwnershipEpisodeService;
use App\Services\Strategy\StrategyRegistrySupport;
use App\Services\StrategyConfigurationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * V3 §10.4 unmanaged → strategy adoption (+ OD-15 continuity).
 */
class HoldingAdoptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_unmanaged_to_strategy_adoption(): void
    {
        [$user, $profile, $strategy, $stock, $holding] = $this->unmanagedPosition();
        $firstBuy = '2024-01-10';
        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 10,
            'price' => 100,
            'fees' => 0,
            'transaction_date' => $firstBuy,
            'source' => Transaction::SOURCE_MANUAL,
        ]);
        StockPrice::query()->create([
            'stock_id' => $stock->id,
            'price_date' => '2024-01-11',
            'open_price' => 100,
            'high_price' => 110,
            'low_price' => 99,
            'close_price' => 105,
            'adjusted_close_price' => 105,
            'volume' => 1000,
            'data_source' => 'test',
            'created_at' => now(),
        ]);
        StockPrice::query()->create([
            'stock_id' => $stock->id,
            'price_date' => '2024-06-01',
            'open_price' => 100,
            'high_price' => 120,
            'low_price' => 100,
            'close_price' => 120,
            'adjusted_close_price' => 120,
            'volume' => 1000,
            'data_source' => 'test',
            'created_at' => now(),
        ]);

        $beforeEntry = app(OwnershipEpisodeService::class)
            ->firstBuyDateForHolding($profile, $holding, $stock)
            ?->toDateString();
        $this->assertSame($firstBuy, $beforeEntry);

        $res = $this->actingAs($user)
            ->postJson('/api/holdings/'.$holding->id.'/adopt', ['strategy_id' => $strategy->id])
            ->assertOk();

        $res->assertJsonPath('data.strategy_id', $strategy->id)
            ->assertJsonPath('data.is_unmanaged', false)
            ->assertJsonPath('data.owner_key', Holding::ownerKeyFor((int) $strategy->id))
            ->assertJsonPath('adoption.idempotent', false);

        $adopted = Holding::query()->findOrFail($res->json('data.id'));
        $this->assertSame((int) $strategy->id, (int) $adopted->strategy_id);
        $this->assertEqualsWithDelta(1000.0, (float) $adopted->target_amount, 0.0001);
        $this->assertEqualsWithDelta(1000.0, (float) $adopted->filled_amount, 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $res->json('data.remaining_target_amount'), 0.0001);

        $afterEntry = app(OwnershipEpisodeService::class)
            ->firstBuyDateForHolding($profile, $adopted, $stock)
            ?->toDateString();
        $this->assertSame($firstBuy, $afterEntry);

        $peak = app(OwnershipEpisodeService::class)->peakRawCloseSinceEntry(
            $stock,
            \Carbon\Carbon::parse($firstBuy),
        );
        $this->assertEqualsWithDelta(120.0, (float) $peak, 0.0001);

        $this->assertSame(1, HoldingAdoption::query()->where('holding_id', $adopted->id)->count());
        $this->assertFalse(
            app(StrategyPositionTargetService::class)->isBuyCooldownActive(
                $profile,
                (int) $stock->id,
                (int) $strategy->id,
            )
        );

        $this->assertSame(
            0,
            TradingRecommendation::query()
                ->where('security_id', $stock->id)
                ->whereIn('recommendation_type', [
                    TradingRecommendation::ACTION_OPEN_POSITION,
                    TradingRecommendation::ACTION_INCREASE_POSITION,
                ])
                ->count()
        );
        $this->assertSame(
            1,
            TradingRecommendation::query()
                ->where('security_id', $stock->id)
                ->where('recommendation_type', TradingRecommendation::ACTION_HOLD_POSITION)
                ->count()
        );
    }

    public function test_same_stock_unmanaged_merges_into_one_strategy_position(): void
    {
        [$user, $profile, $strategy, $stock] = $this->basePortfolio();
        $destEntry = '2024-01-10';
        $unmanagedBuy = '2024-06-01';
        $destTarget = 50_000.0;

        $dest = $this->strategyOwnedHolding($profile, $strategy, $stock, 50, 1000, $destEntry, $destTarget);
        $unmanaged = $this->unmanagedHoldingWithBuy($profile, $stock, 100, 1200, $unmanagedBuy);

        $txCountBefore = Transaction::query()->where('profile_id', $profile->id)->count();
        $buyCountBefore = Transaction::query()->where('profile_id', $profile->id)->where('type', 'buy')->count();

        $beforeDestEntry = app(OwnershipEpisodeService::class)
            ->firstBuyDateForHolding($profile, $dest, $stock)
            ?->toDateString();
        $this->assertSame($destEntry, $beforeDestEntry);

        $res = $this->actingAs($user)
            ->postJson('/api/holdings/'.$unmanaged->id.'/adopt', ['strategy_id' => $strategy->id])
            ->assertOk()
            ->assertJsonPath('adoption.idempotent', false);

        $merged = Holding::query()->findOrFail($res->json('data.id'));
        $this->assertSame((int) $dest->id, (int) $merged->id);
        $this->assertSame((int) $strategy->id, (int) $merged->strategy_id);
        $this->assertFalse($merged->isUnmanaged());
        $this->assertEqualsWithDelta(150.0, (float) $merged->quantity, 0.0001);
        $this->assertEqualsWithDelta(170_000.0, (float) $merged->invested_amount, 0.0001);
        $this->assertEqualsWithDelta(1133.33, (float) $merged->avg_buy_price, 0.0001);
        $this->assertEqualsWithDelta($destTarget, (float) $merged->target_amount, 0.0001);

        $this->assertSame(
            1,
            Holding::query()
                ->where('profile_id', $profile->id)
                ->where('stock_id', $stock->id)
                ->where('quantity', '>', 0)
                ->count()
        );
        $this->assertNull(Holding::query()->find($unmanaged->id));

        $afterEntry = app(OwnershipEpisodeService::class)
            ->firstBuyDateForHolding($profile, $merged->fresh(), $stock)
            ?->toDateString();
        $this->assertSame($destEntry, $afterEntry);

        $this->assertSame($txCountBefore, Transaction::query()->where('profile_id', $profile->id)->count());
        $this->assertSame($buyCountBefore, Transaction::query()->where('profile_id', $profile->id)->where('type', 'buy')->count());
        $this->assertSame(
            0,
            TradingRecommendation::query()
                ->where('security_id', $stock->id)
                ->whereIn('recommendation_type', [
                    TradingRecommendation::ACTION_OPEN_POSITION,
                    TradingRecommendation::ACTION_INCREASE_POSITION,
                ])
                ->where('evidence->holding_adoption', true)
                ->count()
        );
        $this->assertFalse(
            app(StrategyPositionTargetService::class)->isBuyCooldownActive(
                $profile,
                (int) $stock->id,
                (int) $strategy->id,
            )
        );
        $this->assertSame(0, CapitalRequest::query()->where('profile_id', $profile->id)->count());
        $this->assertSame(1, HoldingAdoption::query()->where('holding_id', $merged->id)->count());
    }

    public function test_merge_average_does_not_round_inputs_before_dividing(): void
    {
        [$user, $profile, $strategy, $stock] = $this->basePortfolio();
        $this->strategyOwnedHolding($profile, $strategy, $stock, 1, 10.124, '2024-01-10', 100);
        $unmanaged = $this->unmanagedHoldingWithBuy($profile, $stock, 2, 10.125, '2024-02-01');

        $this->actingAs($user)
            ->postJson('/api/holdings/'.$unmanaged->id.'/adopt', ['strategy_id' => $strategy->id])
            ->assertOk();

        $merged = Holding::query()
            ->where('profile_id', $profile->id)
            ->where('stock_id', $stock->id)
            ->where('owner_key', Holding::ownerKeyFor((int) $strategy->id))
            ->firstOrFail();

        $this->assertEqualsWithDelta(3.0, (float) $merged->quantity, 0.0001);
        $this->assertEqualsWithDelta(30.374, (float) $merged->invested_amount, 0.0001);
        $this->assertEqualsWithDelta(10.12, (float) $merged->avg_buy_price, 0.0001);
        $this->assertNotEquals(10.13, (float) $merged->avg_buy_price);
    }

    public function test_merge_leaves_other_strategy_and_unrelated_holdings_untouched(): void
    {
        [$user, $profile, $strategy, $stock] = $this->basePortfolio();
        $other = $this->makeStrategy($profile, 'Other');
        app(StrategyRegistrySupport::class)->activate($profile, $other);
        $otherStock = $this->makeStock();

        $this->strategyOwnedHolding($profile, $strategy, $stock, 50, 1000, '2024-01-10', 50_000);
        $otherLot = $this->strategyOwnedHolding($profile, $other, $stock, 7, 900, '2024-01-11', 6_300);
        $unrelated = $this->unmanagedHoldingWithBuy($profile, $otherStock, 4, 25, '2024-01-12');
        $unmanaged = $this->unmanagedHoldingWithBuy($profile, $stock, 100, 1200, '2024-06-01');

        $this->actingAs($user)
            ->postJson('/api/holdings/'.$unmanaged->id.'/adopt', ['strategy_id' => $strategy->id])
            ->assertOk();

        $otherLot->refresh();
        $unrelated->refresh();
        $this->assertEqualsWithDelta(7.0, (float) $otherLot->quantity, 0.0001);
        $this->assertSame((int) $other->id, (int) $otherLot->strategy_id);
        $this->assertEqualsWithDelta(4.0, (float) $unrelated->quantity, 0.0001);
        $this->assertTrue($unrelated->isUnmanaged());
    }

    public function test_zero_quantity_unmanaged_is_rejected(): void
    {
        [$user, $profile, $strategy, $stock] = $this->basePortfolio();
        $holding = Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'quantity' => 0,
            'avg_buy_price' => 0,
            'invested_amount' => 0,
            'updated_at' => now(),
        ]);

        $this->actingAs($user)
            ->postJson('/api/holdings/'.$holding->id.'/adopt', ['strategy_id' => $strategy->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['holding_id']);
    }

    public function test_foreign_portfolio_holding_cannot_be_adopted(): void
    {
        [$user, $profile, $strategy, $stock, $holding] = $this->unmanagedPosition();
        $other = User::factory()->create();
        $this->defaultPortfolioFor($other);

        $this->actingAs($other)
            ->postJson('/api/holdings/'.$holding->id.'/adopt', ['strategy_id' => $strategy->id])
            ->assertNotFound();

        $this->assertTrue($holding->fresh()->isUnmanaged());
        $this->assertSame((int) $profile->id, (int) $holding->fresh()->profile_id);
    }

    public function test_invalid_strategy_and_already_owned(): void
    {
        [$user, $profile, $strategy, $stock, $holding] = $this->unmanagedPosition();
        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 2,
            'price' => 40,
            'fees' => 0,
            'transaction_date' => '2024-03-01',
            'source' => Transaction::SOURCE_MANUAL,
        ]);

        $this->actingAs($user)
            ->postJson('/api/holdings/'.$holding->id.'/adopt', ['strategy_id' => 999999])
            ->assertStatus(422);

        $this->actingAs($user)
            ->postJson('/api/holdings/'.$holding->id.'/adopt', ['strategy_id' => $strategy->id])
            ->assertOk();

        $adoptedId = Holding::query()
            ->where('profile_id', $profile->id)
            ->where('stock_id', $stock->id)
            ->where('owner_key', Holding::ownerKeyFor((int) $strategy->id))
            ->value('id');

        $other = $this->makeStrategy($profile, 'Other');
        app(StrategyRegistrySupport::class)->activate($profile, $other);

        $this->actingAs($user)
            ->postJson('/api/holdings/'.$adoptedId.'/adopt', ['strategy_id' => $other->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['holding_id']);
    }

    public function test_repeated_adoption_is_idempotent(): void
    {
        [$user, $profile, $strategy, $stock, $holding] = $this->unmanagedPosition();
        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 2,
            'price' => 40,
            'fees' => 0,
            'transaction_date' => '2024-03-01',
            'source' => Transaction::SOURCE_MANUAL,
        ]);

        $this->actingAs($user)
            ->postJson('/api/holdings/'.$holding->id.'/adopt', ['strategy_id' => $strategy->id])
            ->assertOk();

        $adopted = Holding::query()
            ->where('profile_id', $profile->id)
            ->where('stock_id', $stock->id)
            ->where('owner_key', Holding::ownerKeyFor((int) $strategy->id))
            ->firstOrFail();

        $this->actingAs($user)
            ->postJson('/api/holdings/'.$adopted->id.'/adopt', ['strategy_id' => $strategy->id])
            ->assertOk()
            ->assertJsonPath('adoption.idempotent', true);

        $this->assertSame(
            1,
            TradingRecommendation::query()
                ->where('security_id', $stock->id)
                ->where('recommendation_type', TradingRecommendation::ACTION_HOLD_POSITION)
                ->count()
        );
    }

    public function test_final_average_cost_rounds_half_up_to_two_decimals(): void
    {
        $svc = app(HoldingAdoptionService::class);
        $this->assertSame(1.13, $svc->roundAverageCost(1.125));
        $this->assertSame(1133.33, $svc->roundAverageCost(170000 / 150));
    }

    public function test_service_layer_merges_without_http(): void
    {
        [$user, $profile, $strategy, $stock] = $this->basePortfolio();
        $dest = $this->strategyOwnedHolding($profile, $strategy, $stock, 50, 1000, '2024-01-10', 50_000);
        $unmanaged = $this->unmanagedHoldingWithBuy($profile, $stock, 100, 1200, '2024-06-01');

        $result = app(HoldingAdoptionService::class)->adopt($profile, $unmanaged, (int) $strategy->id, $user);

        $this->assertFalse($result['idempotent']);
        $this->assertSame((int) $dest->id, (int) $result['holding']->id);
        $this->assertEqualsWithDelta(150.0, (float) $result['holding']->quantity, 0.0001);
        $this->assertEqualsWithDelta(1133.33, (float) $result['holding']->avg_buy_price, 0.0001);
        $this->assertEqualsWithDelta(50_000.0, (float) $result['holding']->target_amount, 0.0001);
    }

    protected function strategyOwnedHolding(
        \App\Models\PortfolioProfile $profile,
        TradingStrategy $strategy,
        Stock $stock,
        float $qty,
        float $price,
        string $date,
        float $targetAmount,
    ): Holding {
        $rec = TradingRecommendation::query()->create([
            'profile_id' => $profile->id,
            'security_id' => $stock->id,
            'strategy_version_id' => $strategy->active_version_id,
            'recommendation_type' => TradingRecommendation::ACTION_OPEN_POSITION,
            'status' => TradingRecommendation::STATUS_EXECUTED,
            'priority' => 1,
            'strategy_score' => 80,
            'confidence' => 0.8,
            'risk_level' => TradingRecommendation::RISK_MEDIUM,
            'generated_at' => $date.' 10:00:00',
            'executed_at' => $date.' 10:00:00',
        ]);
        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => $qty,
            'price' => $price,
            'fees' => 0,
            'transaction_date' => $date,
            'source' => Transaction::SOURCE_RECOMMENDATION,
            'recommendation_id' => $rec->id,
        ]);

        return Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'strategy_id' => $strategy->id,
            'owner_key' => Holding::ownerKeyFor((int) $strategy->id),
            'quantity' => $qty,
            'avg_buy_price' => $price,
            'invested_amount' => $qty * $price,
            'target_amount' => $targetAmount,
            'filled_amount' => $qty * $price,
            'updated_at' => now(),
        ]);
    }

    protected function unmanagedHoldingWithBuy(
        \App\Models\PortfolioProfile $profile,
        Stock $stock,
        float $qty,
        float $price,
        string $date,
    ): Holding {
        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => $qty,
            'price' => $price,
            'fees' => 0,
            'transaction_date' => $date,
            'source' => Transaction::SOURCE_MANUAL,
        ]);

        return Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'quantity' => $qty,
            'avg_buy_price' => $price,
            'invested_amount' => $qty * $price,
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array{0: User, 1: \App\Models\PortfolioProfile, 2: TradingStrategy, 3: Stock, 4: Holding}
     */
    protected function unmanagedPosition(): array
    {
        [$user, $profile, $strategy, $stock] = $this->basePortfolio();
        $holding = Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'quantity' => 10,
            'avg_buy_price' => 100,
            'invested_amount' => 1000,
            'updated_at' => now(),
        ]);

        return [$user, $profile, $strategy, $stock, $holding];
    }

    /**
     * @return array{0: User, 1: \App\Models\PortfolioProfile, 2: TradingStrategy, 3: Stock}
     */
    protected function basePortfolio(): array
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $version = app(StrategyConfigurationService::class)->ensureActive($profile);

        return [$user, $profile, $version->strategy, $this->makeStock()];
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
            'symbol' => 'AD'.strtoupper(Str::random(3)),
            'exchange' => 'NSE',
            'name' => 'Adopt Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);
    }
}
