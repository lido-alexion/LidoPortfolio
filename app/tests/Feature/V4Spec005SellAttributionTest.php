<?php

namespace Tests\Feature;

use App\Engines\Execution\ExecutionEngine;
use App\Models\Holding;
use App\Models\Stock;
use App\Models\TradingOrder;
use App\Models\TradingRecommendation;
use App\Models\TradingStrategy;
use App\Models\TradingStrategyVersion;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CashManagementService;
use App\Services\HoldingsCalculationService;
use App\Services\TransactionWriteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * V4-SPEC-005 — explicit cross-owner SELL attribution.
 */
class V4Spec005SellAttributionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([\App\Jobs\BackfillHistoricalDataJob::class]);
    }

    public function test_single_owner_sell_is_unambiguous_and_stamps_owner_key(): void
    {
        [$user, $profile, $stock] = $this->seedUserStock();
        $this->buy($profile, $stock, $user, 10, 100);

        $this->actingAs($user)->withProfileHeader($user, $profile);
        $res = $this->postJson('/api/transactions', $this->sellPayload($stock, 4, 110))
            ->assertCreated();

        $tx = Transaction::query()->findOrFail($res->json('data.id'));
        $this->assertSame(Holding::OWNER_UNMANAGED, $tx->owner_key);
        $holding = $this->holding($profile, $stock, Holding::OWNER_UNMANAGED);
        $this->assertEqualsWithDelta(6.0, (float) $holding->quantity, 0.0001);
    }

    public function test_multi_owner_sell_without_owner_is_rejected(): void
    {
        [$user, $profile, $stock, $strategyA] = $this->twoOwnerLots();

        $this->actingAs($user)->withProfileHeader($user, $profile);
        $this->postJson('/api/transactions', $this->sellPayload($stock, 5, 110))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['owner_key']);

        $this->assertSame(0, Transaction::query()->where('type', 'sell')->count());
        $this->assertEqualsWithDelta(10.0, (float) $this->holding($profile, $stock, Holding::OWNER_UNMANAGED)->quantity, 0.0001);
        $this->assertEqualsWithDelta(
            10.0,
            (float) $this->holding($profile, $stock, Holding::ownerKeyFor((int) $strategyA->id))->quantity,
            0.0001,
        );
    }

    public function test_explicit_valid_owner_sells_only_that_lot(): void
    {
        [$user, $profile, $stock, $strategyA] = $this->twoOwnerLots();
        $ownerA = Holding::ownerKeyFor((int) $strategyA->id);
        $cashBefore = app(CashManagementService::class)->balance($profile);

        $this->actingAs($user)->withProfileHeader($user, $profile);
        $res = $this->postJson('/api/transactions', $this->sellPayload($stock, 4, 110, [
            'owner_key' => $ownerA,
        ]))->assertCreated();

        $tx = Transaction::query()->findOrFail($res->json('data.id'));
        $this->assertSame($ownerA, $tx->owner_key);
        $this->assertEqualsWithDelta(
            6.0,
            (float) $this->holding($profile, $stock, $ownerA)->quantity,
            0.0001,
        );
        $this->assertEqualsWithDelta(
            10.0,
            (float) $this->holding($profile, $stock, Holding::OWNER_UNMANAGED)->quantity,
            0.0001,
        );
        $this->assertEqualsWithDelta(
            $cashBefore + 440.0,
            app(CashManagementService::class)->balance($profile),
            0.001,
        );
        $this->assertSame(1, Transaction::query()->where('type', 'sell')->count());
        $this->assertSame(2, Transaction::query()->where('type', 'buy')->count());
    }

    public function test_unmanaged_owner_can_be_selected_explicitly(): void
    {
        [$user, $profile, $stock, $strategyA] = $this->twoOwnerLots();

        $this->actingAs($user)->withProfileHeader($user, $profile);
        $this->postJson('/api/transactions', $this->sellPayload($stock, 3, 110, [
            'owner_key' => Holding::OWNER_UNMANAGED,
        ]))->assertCreated();

        $this->assertEqualsWithDelta(
            7.0,
            (float) $this->holding($profile, $stock, Holding::OWNER_UNMANAGED)->quantity,
            0.0001,
        );
        $this->assertEqualsWithDelta(
            10.0,
            (float) $this->holding($profile, $stock, Holding::ownerKeyFor((int) $strategyA->id))->quantity,
            0.0001,
        );
    }

    public function test_invalid_owner_is_rejected(): void
    {
        [$user, $profile, $stock] = $this->twoOwnerLots();

        $this->actingAs($user)->withProfileHeader($user, $profile);
        $this->postJson('/api/transactions', $this->sellPayload($stock, 2, 110, [
            'owner_key' => 'not-an-owner',
        ]))->assertStatus(422)->assertJsonValidationErrors(['owner_key']);

        $this->postJson('/api/transactions', $this->sellPayload($stock, 2, 110, [
            'strategy_id' => 999999,
        ]))->assertStatus(422)->assertJsonValidationErrors(['strategy_id']);
    }

    public function test_insufficient_attributable_quantity_does_not_take_from_another_owner(): void
    {
        [$user, $profile, $stock, $strategyA] = $this->twoOwnerLots();
        $ownerA = Holding::ownerKeyFor((int) $strategyA->id);

        $this->actingAs($user)->withProfileHeader($user, $profile);
        $this->postJson('/api/transactions', $this->sellPayload($stock, 12, 110, [
            'owner_key' => $ownerA,
        ]))->assertStatus(422)->assertJsonValidationErrors(['quantity']);

        $this->assertSame(0, Transaction::query()->where('type', 'sell')->count());
        $this->assertEqualsWithDelta(10.0, (float) $this->holding($profile, $stock, $ownerA)->quantity, 0.0001);
        $this->assertEqualsWithDelta(
            10.0,
            (float) $this->holding($profile, $stock, Holding::OWNER_UNMANAGED)->quantity,
            0.0001,
        );
    }

    public function test_recommendation_linked_sell_uses_strategy_owner_and_marks_executed(): void
    {
        [$user, $profile, $stock, $strategyA] = $this->twoOwnerLots();
        $exit = $this->pendingExit($profile, $stock, $strategyA);

        $this->actingAs($user)->withProfileHeader($user, $profile);
        $this->postJson('/api/transactions', $this->sellPayload($stock, 5, 110, [
            'recommendation_id' => $exit->id,
        ]))->assertCreated()->assertJsonPath('tos.recommendation_status', 'executed');

        $ownerA = Holding::ownerKeyFor((int) $strategyA->id);
        $tx = Transaction::query()->where('recommendation_id', $exit->id)->first();
        $this->assertSame($ownerA, $tx->owner_key);
        $this->assertSame(TradingRecommendation::STATUS_EXECUTED, $exit->fresh()->status);
        $this->assertEqualsWithDelta(5.0, (float) $this->holding($profile, $stock, $ownerA)->quantity, 0.0001);
        $this->assertEqualsWithDelta(
            10.0,
            (float) $this->holding($profile, $stock, Holding::OWNER_UNMANAGED)->quantity,
            0.0001,
        );
    }

    public function test_recommendation_sell_does_not_consume_unmanaged_when_strategy_has_no_shares(): void
    {
        [$user, $profile, $stock] = $this->seedUserStock();
        $this->buy($profile, $stock, $user, 10, 100);
        $strategy = $this->makeStrategy($profile, 'Empty Owner');
        $exit = $this->pendingExit($profile, $stock, $strategy);

        $this->actingAs($user)->withProfileHeader($user, $profile);
        $this->postJson('/api/transactions', $this->sellPayload($stock, 5, 110, [
            'recommendation_id' => $exit->id,
        ]))->assertStatus(422)->assertJsonValidationErrors(['owner_key']);

        $this->assertSame(TradingRecommendation::STATUS_PENDING_EXECUTION, $exit->fresh()->status);
        $this->assertEqualsWithDelta(
            10.0,
            (float) $this->holding($profile, $stock, Holding::OWNER_UNMANAGED)->quantity,
            0.0001,
        );
        $this->assertSame(0, Transaction::query()->where('type', 'sell')->count());
    }

    public function test_broker_fill_stamps_recommendation_owner(): void
    {
        [$user, $profile, $stock, $strategyA] = $this->twoOwnerLots();
        $exit = $this->pendingExit($profile, $stock, $strategyA);
        $order = TradingOrder::query()->create([
            'profile_id' => $profile->id,
            'recommendation_id' => $exit->id,
            'security_id' => $stock->id,
            'side' => 'sell',
            'quantity' => 4,
            'order_type' => 'market',
            'status' => TradingOrder::STATUS_PENDING,
        ]);

        $result = app(ExecutionEngine::class)->applyBrokerFill($profile, $order, 4, 108);
        $this->assertNotNull($result['transaction']);
        $this->assertSame(Holding::ownerKeyFor((int) $strategyA->id), $result['transaction']->owner_key);
        $this->assertSame(TradingRecommendation::STATUS_EXECUTED, $exit->fresh()->status);
        $this->assertEqualsWithDelta(
            6.0,
            (float) $this->holding($profile, $stock, Holding::ownerKeyFor((int) $strategyA->id))->quantity,
            0.0001,
        );
        $this->assertEqualsWithDelta(
            10.0,
            (float) $this->holding($profile, $stock, Holding::OWNER_UNMANAGED)->quantity,
            0.0001,
        );
    }

    public function test_live_fill_without_rec_uses_explicit_protection_owner(): void
    {
        [$user, $profile, $stock, $strategyA] = $this->twoOwnerLots();
        $ownerA = Holding::ownerKeyFor((int) $strategyA->id);
        $order = TradingOrder::query()->create([
            'profile_id' => $profile->id,
            'security_id' => $stock->id,
            'side' => 'sell',
            'quantity' => 3,
            'order_type' => 'gtt_protection',
            'status' => TradingOrder::STATUS_PENDING,
        ]);

        $result = app(ExecutionEngine::class)->applyBrokerFill(
            $profile,
            $order,
            3,
            107,
            completeRecommendation: false,
            ownerKey: $ownerA,
        );

        $this->assertSame($ownerA, $result['transaction']->owner_key);
        $this->assertEqualsWithDelta(7.0, (float) $this->holding($profile, $stock, $ownerA)->quantity, 0.0001);
        $this->assertEqualsWithDelta(
            10.0,
            (float) $this->holding($profile, $stock, Holding::OWNER_UNMANAGED)->quantity,
            0.0001,
        );
    }

    public function test_foreign_profile_strategy_cannot_be_selected(): void
    {
        [$user, $profile, $stock] = $this->twoOwnerLots();
        $other = User::query()->create([
            'name' => 'Other',
            'email' => 'other-'.Str::random(6).'@example.com',
            'password' => 'password123',
        ]);
        $otherProfile = $this->defaultPortfolioFor($other);
        $foreign = $this->makeStrategy($otherProfile, 'Foreign');

        $this->actingAs($user)->withProfileHeader($user, $profile);
        $this->postJson('/api/transactions', $this->sellPayload($stock, 2, 110, [
            'owner_key' => Holding::ownerKeyFor((int) $foreign->id),
        ]))->assertStatus(422)->assertJsonValidationErrors(['strategy_id']);
    }

    public function test_sell_does_not_transfer_ownership(): void
    {
        [$user, $profile, $stock, $strategyA] = $this->twoOwnerLots();
        $ownerA = Holding::ownerKeyFor((int) $strategyA->id);

        app(TransactionWriteService::class)->create($profile, $stock, [
            'type' => 'sell',
            'quantity' => 10,
            'price' => 110,
            'fees' => 0,
            'transaction_date' => now()->toDateString(),
            'owner_key' => $ownerA,
        ], user: $user);

        $this->assertNull($this->holding($profile, $stock, $ownerA));
        $unmanaged = $this->holding($profile, $stock, Holding::OWNER_UNMANAGED);
        $this->assertNotNull($unmanaged);
        $this->assertTrue($unmanaged->isUnmanaged());
        $this->assertEqualsWithDelta(10.0, (float) $unmanaged->quantity, 0.0001);
    }

    public function test_legacy_ambiguous_sell_without_owner_key_still_blends_on_recalc(): void
    {
        [$user, $profile, $stock, $strategyA] = $this->twoOwnerLots();

        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'sell',
            'quantity' => 5,
            'price' => 110,
            'fees' => 0,
            'transaction_date' => now()->toDateString(),
            'source' => Transaction::SOURCE_MANUAL,
        ]);

        $lots = app(HoldingsCalculationService::class)->recalculateOwnerLotsForProfileStock($profile, $stock);
        $this->assertGreaterThan(0, $lots->count());
        $this->assertTrue(
            $lots->contains(fn (Holding $h) => $h->owner_key === Holding::OWNER_UNMANAGED)
            || $lots->count() === 1,
            'Historical ambiguous sell remains on the blended fallback path.',
        );
        $this->assertNull(Transaction::query()->where('type', 'sell')->value('owner_key'));
    }

    public function test_strategy_id_alias_selects_owner(): void
    {
        [$user, $profile, $stock, $strategyA] = $this->twoOwnerLots();

        $this->actingAs($user)->withProfileHeader($user, $profile);
        $this->postJson('/api/transactions', $this->sellPayload($stock, 2, 110, [
            'strategy_id' => $strategyA->id,
        ]))->assertCreated()->assertJsonPath('data.owner_key', Holding::ownerKeyFor((int) $strategyA->id));
    }

    public function test_mismatch_between_owner_and_recommendation_is_rejected(): void
    {
        [$user, $profile, $stock, $strategyA] = $this->twoOwnerLots();
        $exit = $this->pendingExit($profile, $stock, $strategyA);

        $this->actingAs($user)->withProfileHeader($user, $profile);
        $this->postJson('/api/transactions', $this->sellPayload($stock, 2, 110, [
            'recommendation_id' => $exit->id,
            'owner_key' => Holding::OWNER_UNMANAGED,
        ]))->assertStatus(422)->assertJsonValidationErrors(['owner_key']);
    }

    /**
     * @return array{0: User, 1: \App\Models\PortfolioProfile, 2: Stock}
     */
    protected function seedUserStock(float $cash = 100_000): array
    {
        $user = User::query()->create([
            'name' => 'Spec005',
            'email' => 'spec005-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);
        app(CashManagementService::class)->deposit($profile, $cash, 'seed', $user);
        $stock = Stock::query()->create([
            'symbol' => 'S5'.strtoupper(Str::random(3)),
            'exchange' => 'NSE',
            'name' => 'Spec005 Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        return [$user, $profile, $stock];
    }

    /**
     * @return array{0: User, 1: \App\Models\PortfolioProfile, 2: Stock, 3: TradingStrategy}
     */
    protected function twoOwnerLots(): array
    {
        [$user, $profile, $stock] = $this->seedUserStock();
        $this->buy($profile, $stock, $user, 10, 100);
        $strategyA = $this->makeStrategy($profile, 'Momentum');
        $open = $this->pendingOpen($profile, $stock, $strategyA);
        $this->actingAs($user)->withProfileHeader($user, $profile);
        $this->postJson('/api/transactions', [
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 10,
            'price' => 100,
            'fees' => 0,
            'transaction_date' => now()->toDateString(),
            'recommendation_id' => $open->id,
        ])->assertCreated();

        return [$user, $profile, $stock, $strategyA];
    }

    protected function buy($profile, Stock $stock, User $user, float $qty, float $price): Transaction
    {
        return app(TransactionWriteService::class)->create($profile, $stock, [
            'type' => 'buy',
            'quantity' => $qty,
            'price' => $price,
            'fees' => 0,
            'transaction_date' => now()->toDateString(),
        ], user: $user);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function sellPayload(Stock $stock, float $qty, float $price, array $extra = []): array
    {
        return array_merge([
            'stock_id' => $stock->id,
            'type' => 'sell',
            'quantity' => $qty,
            'price' => $price,
            'fees' => 0,
            'transaction_date' => now()->toDateString(),
        ], $extra);
    }

    protected function holding($profile, Stock $stock, string $ownerKey): ?Holding
    {
        return Holding::query()
            ->where('profile_id', $profile->id)
            ->where('stock_id', $stock->id)
            ->where('owner_key', $ownerKey)
            ->where('quantity', '>', 0)
            ->first();
    }

    protected function makeStrategy($profile, string $name): TradingStrategy
    {
        $strategy = TradingStrategy::query()->create([
            'profile_id' => $profile->id,
            'name' => $name,
            'slug' => Str::slug($name).'_'.Str::lower(Str::random(4)),
            'status' => TradingStrategy::STATUS_ACTIVE,
            'allocation_pct' => 0,
            'is_factory' => false,
        ]);
        $version = TradingStrategyVersion::query()->create([
            'strategy_id' => $strategy->id,
            'version' => 1,
            'version_label' => '1.0',
            'config_json' => [],
            'status' => TradingStrategyVersion::STATUS_ACTIVE,
            'activated_at' => now(),
        ]);
        $strategy->forceFill(['active_version_id' => $version->id])->save();

        return $strategy->fresh(['activeVersion']);
    }

    protected function pendingOpen($profile, Stock $stock, TradingStrategy $strategy): TradingRecommendation
    {
        return TradingRecommendation::query()->create([
            'profile_id' => $profile->id,
            'security_id' => $stock->id,
            'strategy_version_id' => $strategy->active_version_id,
            'recommendation_type' => TradingRecommendation::ACTION_OPEN_POSITION,
            'status' => TradingRecommendation::STATUS_PENDING_EXECUTION,
            'priority' => 1,
            'strategy_score' => 80,
            'confidence' => 0.8,
            'risk_level' => 'medium',
            'generated_at' => now(),
        ]);
    }

    protected function pendingExit($profile, Stock $stock, TradingStrategy $strategy): TradingRecommendation
    {
        return TradingRecommendation::query()->create([
            'profile_id' => $profile->id,
            'security_id' => $stock->id,
            'strategy_version_id' => $strategy->active_version_id,
            'recommendation_type' => TradingRecommendation::ACTION_EXIT_POSITION,
            'status' => TradingRecommendation::STATUS_PENDING_EXECUTION,
            'priority' => 1,
            'strategy_score' => 20,
            'confidence' => 0.8,
            'risk_level' => 'medium',
            'generated_at' => now(),
        ]);
    }
}
