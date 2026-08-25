<?php

namespace Tests\Feature;

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

    public function test_same_stock_destination_is_blocked(): void
    {
        [$user, $profile, $strategy, $stock] = $this->basePortfolio();
        Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'strategy_id' => $strategy->id,
            'owner_key' => Holding::ownerKeyFor((int) $strategy->id),
            'quantity' => 5,
            'avg_buy_price' => 50,
            'invested_amount' => 250,
            'updated_at' => now(),
        ]);
        $unmanaged = Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'quantity' => 3,
            'avg_buy_price' => 60,
            'invested_amount' => 180,
            'updated_at' => now(),
        ]);
        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 3,
            'price' => 60,
            'fees' => 0,
            'transaction_date' => '2024-02-01',
            'source' => Transaction::SOURCE_MANUAL,
        ]);

        $this->actingAs($user)
            ->postJson('/api/holdings/'.$unmanaged->id.'/adopt', ['strategy_id' => $strategy->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['strategy_id']);

        $unmanaged->refresh();
        $this->assertTrue($unmanaged->isUnmanaged());
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

    public function test_service_layer_blocks_merge_without_http(): void
    {
        [$user, $profile, $strategy, $stock] = $this->basePortfolio();
        Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'strategy_id' => $strategy->id,
            'owner_key' => Holding::ownerKeyFor((int) $strategy->id),
            'quantity' => 1,
            'avg_buy_price' => 10,
            'invested_amount' => 10,
            'updated_at' => now(),
        ]);
        $unmanaged = Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'quantity' => 1,
            'avg_buy_price' => 20,
            'invested_amount' => 20,
            'updated_at' => now(),
        ]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(HoldingAdoptionService::class)->adopt($profile, $unmanaged, (int) $strategy->id, $user);
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
