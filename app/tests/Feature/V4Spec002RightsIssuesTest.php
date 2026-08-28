<?php

namespace Tests\Feature;

use App\Models\CorporateAction;
use App\Models\Holding;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\TradingRecommendation;
use App\Models\TradingStrategy;
use App\Models\TradingStrategyVersion;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CashManagementService;
use App\Services\CorporateActionService;
use App\Services\HoldingsCalculationService;
use App\Services\TransactionWriteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * V4-SPEC-002 — rights issues are not a corporate-action type.
 * Existing holdings are not auto-changed. Exercised shares are a normal purchase.
 */
class V4Spec002RightsIssuesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([\App\Jobs\BackfillHistoricalDataJob::class]);
    }

    public function test_preview_and_apply_reject_rights_without_mutating_holdings_or_ledger(): void
    {
        [$user, $profile, $stock] = $this->seedUserStock();
        $this->buy($profile, $stock, $user, 100, 100);
        $this->seedPrice($stock, '2026-03-01', 110);

        $holdingBefore = $this->holding($profile, $stock);
        $qty = (float) $holdingBefore->quantity;
        $invested = (float) $holdingBefore->invested_amount;
        $avg = (float) $holdingBefore->avg_buy_price;
        $cashBefore = app(CashManagementService::class)->balance($profile);
        $txCount = Transaction::query()->where('profile_id', $profile->id)->count();
        $caCount = CorporateAction::query()->count();
        $ohlcv = (float) StockPrice::query()->where('stock_id', $stock->id)->value('close_price');

        $this->actingAs($user)->withProfileHeader($user, $profile);

        foreach (['rights', 'RIGHTS', 'rights_issue', 'rights-issue'] as $actionType) {
            $this->postJson('/api/corporate-actions/preview', $this->rightsPayload($stock, $actionType))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['action_type'])
                ->assertJsonFragment(['action_type' => [CorporateActionService::RIGHTS_NOT_SUPPORTED_MESSAGE]]);

            $this->postJson('/api/corporate-actions', $this->rightsPayload($stock, $actionType))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['action_type'])
                ->assertJsonFragment(['action_type' => [CorporateActionService::RIGHTS_NOT_SUPPORTED_MESSAGE]]);
        }

        $holding = $this->holding($profile, $stock);
        $this->assertEqualsWithDelta($qty, (float) $holding->quantity, 0.0001);
        $this->assertEqualsWithDelta($invested, (float) $holding->invested_amount, 0.0001);
        $this->assertEqualsWithDelta($avg, (float) $holding->avg_buy_price, 0.0001);
        $this->assertEqualsWithDelta($cashBefore, app(CashManagementService::class)->balance($profile), 0.0001);
        $this->assertSame($txCount, Transaction::query()->where('profile_id', $profile->id)->count());
        $this->assertSame($caCount, CorporateAction::query()->count());
        $this->assertEqualsWithDelta($ohlcv, (float) StockPrice::query()->where('stock_id', $stock->id)->value('close_price'), 0.0001);
        $this->assertSame(0, CorporateAction::query()->where('action_type', 'rights')->count());
    }

    public function test_service_apply_rejects_rights_without_creating_entitlements(): void
    {
        [$user, $profile, $stock] = $this->seedUserStock();
        $this->buy($profile, $stock, $user, 40, 50);

        $threw = false;
        try {
            app(CorporateActionService::class)->apply($profile, $stock, [
                'action_type' => 'rights',
                'ratio_from' => 1,
                'ratio_to' => 1,
                'ex_date' => now()->toDateString(),
            ]);
        } catch (InvalidArgumentException $e) {
            $threw = true;
            $this->assertSame(CorporateActionService::RIGHTS_NOT_SUPPORTED_MESSAGE, $e->getMessage());
        }

        $this->assertTrue($threw);
        $this->assertSame(0, CorporateAction::query()->count());
        $this->assertEqualsWithDelta(40.0, (float) $this->holding($profile, $stock)->quantity, 0.0001);
    }

    public function test_exercised_rights_are_a_normal_purchase_at_subscription_price(): void
    {
        [$user, $profile, $stock] = $this->seedUserStock();
        $this->buy($profile, $stock, $user, 100, 100);
        $cashBefore = app(CashManagementService::class)->balance($profile);

        $this->actingAs($user)->withProfileHeader($user, $profile);
        $res = $this->postJson('/api/transactions', [
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 50,
            'price' => 80,
            'fees' => 0,
            'transaction_date' => now()->toDateString(),
        ])->assertCreated();

        $tx = Transaction::query()->findOrFail($res->json('data.id'));
        $this->assertSame('buy', $tx->type);
        $this->assertSame(Transaction::SOURCE_MANUAL, $tx->source);
        $this->assertNull($tx->corporate_action_id);
        $this->assertEqualsWithDelta(50.0, (float) $tx->quantity, 0.0001);
        $this->assertEqualsWithDelta(80.0, (float) $tx->price, 0.0001);
        $this->assertNotSame(Transaction::SOURCE_BONUS, $tx->source);
        $this->assertNotSame(Transaction::SOURCE_SPLIT, $tx->source);

        $holding = $this->holding($profile, $stock);
        $this->assertEqualsWithDelta(150.0, (float) $holding->quantity, 0.0001);
        $this->assertEqualsWithDelta(14_000.0, (float) $holding->invested_amount, 0.0001);
        $this->assertEqualsWithDelta(14_000.0 / 150.0, (float) $holding->avg_buy_price, 0.0001);
        $this->assertEqualsWithDelta($cashBefore - 4000.0, app(CashManagementService::class)->balance($profile), 0.0001);
        $this->assertSame(0, CorporateAction::query()->count());
    }

    public function test_rights_source_without_recommendation_is_a_paid_purchase_not_zero_cost_ca(): void
    {
        [$user, $profile, $stock] = $this->seedUserStock();
        $this->buy($profile, $stock, $user, 10, 100);

        $tx = app(TransactionWriteService::class)->create($profile, $stock, [
            'type' => 'buy',
            'quantity' => 5,
            'price' => 40,
            'fees' => 0,
            'transaction_date' => now()->toDateString(),
            'source' => Transaction::SOURCE_RIGHTS,
        ], user: $user);

        $this->assertSame(Transaction::SOURCE_RIGHTS, $tx->source);
        $this->assertNull($tx->corporate_action_id);
        $this->assertEqualsWithDelta(40.0, (float) $tx->price, 0.0001);

        $holding = $this->holding($profile, $stock);
        $this->assertEqualsWithDelta(15.0, (float) $holding->quantity, 0.0001);
        $this->assertEqualsWithDelta(1200.0, (float) $holding->invested_amount, 0.0001);
    }

    public function test_rights_source_with_recommendation_becomes_a_normal_recommendation_buy(): void
    {
        [$user, $profile, $stock] = $this->seedUserStock();
        $strategy = $this->makeStrategy($profile, 'Momentum');
        $open = $this->pendingOpen($profile, $stock, $strategy);

        $tx = app(TransactionWriteService::class)->create($profile, $stock, [
            'type' => 'buy',
            'quantity' => 8,
            'price' => 55,
            'fees' => 0,
            'transaction_date' => now()->toDateString(),
            'source' => Transaction::SOURCE_RIGHTS,
            'recommendation_id' => $open->id,
        ], user: $user);

        $this->assertSame(Transaction::SOURCE_RECOMMENDATION, $tx->source);
        $this->assertSame((int) $open->id, (int) $tx->recommendation_id);
        $this->assertEqualsWithDelta(55.0, (float) $tx->price, 0.0001);
        $this->assertNull($tx->corporate_action_id);
    }

    public function test_rights_source_cannot_use_zero_price_like_bonus(): void
    {
        [$user, $profile, $stock] = $this->seedUserStock();

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(TransactionWriteService::class)->create($profile, $stock, [
            'type' => 'buy',
            'quantity' => 5,
            'price' => 0,
            'fees' => 0,
            'transaction_date' => now()->toDateString(),
            'source' => Transaction::SOURCE_RIGHTS,
        ], user: $user);
    }

    public function test_split_apply_still_works_after_rights_rejection(): void
    {
        [$user, $profile, $stock] = $this->seedUserStock();
        $this->buy($profile, $stock, $user, 4, 200);

        $this->actingAs($user)->withProfileHeader($user, $profile);
        $this->postJson('/api/corporate-actions', $this->rightsPayload($stock, 'rights'))
            ->assertStatus(422);

        $apply = $this->postJson('/api/corporate-actions', [
            'stock_id' => $stock->id,
            'action_type' => 'split',
            'ratio_from' => 1,
            'ratio_to' => 2,
            'ex_date' => now()->toDateString(),
        ]);

        $apply->assertCreated();
        $this->assertEqualsWithDelta(8.0, (float) $this->holding($profile, $stock)->quantity, 0.0001);
        $this->assertEqualsWithDelta(800.0, (float) $this->holding($profile, $stock)->invested_amount, 0.0001);
        $this->assertDatabaseHas('portfolio_corporate_actions', [
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'action_type' => 'split',
        ]);
    }

    /**
     * @return array{0: User, 1: \App\Models\PortfolioProfile, 2: Stock}
     */
    protected function seedUserStock(float $cash = 100_000): array
    {
        $user = User::query()->create([
            'name' => 'Spec002',
            'email' => 'spec002-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);
        app(CashManagementService::class)->deposit($profile, $cash, 'seed', $user);
        $stock = Stock::query()->create([
            'symbol' => 'R2'.strtoupper(Str::random(3)),
            'exchange' => 'NSE',
            'name' => 'Spec002 Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        return [$user, $profile, $stock];
    }

    protected function buy($profile, Stock $stock, User $user, float $qty, float $price): Transaction
    {
        return app(TransactionWriteService::class)->create($profile, $stock, [
            'type' => 'buy',
            'quantity' => $qty,
            'price' => $price,
            'fees' => 0,
            'transaction_date' => '2026-01-15',
        ], user: $user);
    }

    /**
     * @return array<string, mixed>
     */
    protected function rightsPayload(Stock $stock, string $actionType): array
    {
        return [
            'stock_id' => $stock->id,
            'action_type' => $actionType,
            'ratio_from' => 1,
            'ratio_to' => 1,
            'ex_date' => now()->toDateString(),
        ];
    }

    protected function holding($profile, Stock $stock): Holding
    {
        app(HoldingsCalculationService::class)->recalculateForProfileStock($profile, $stock);

        return Holding::query()
            ->where('profile_id', $profile->id)
            ->where('stock_id', $stock->id)
            ->where('quantity', '>', 0)
            ->firstOrFail();
    }

    protected function seedPrice(Stock $stock, string $date, float $close): void
    {
        StockPrice::query()->create([
            'stock_id' => $stock->id,
            'price_date' => $date,
            'open_price' => $close,
            'high_price' => $close,
            'low_price' => $close,
            'close_price' => $close,
            'adjusted_close_price' => $close,
            'volume' => 1000,
            'provider_source' => 'test',
            'data_source' => 'test',
            'created_at' => now(),
        ]);
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
}
