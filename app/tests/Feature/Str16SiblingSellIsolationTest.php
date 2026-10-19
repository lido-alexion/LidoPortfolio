<?php

namespace Tests\Feature;

use App\Models\Holding;
use App\Models\PortfolioProfile;
use App\Models\Stock;
use App\Models\TradingOrder;
use App\Models\TradingRecommendation;
use App\Models\TradingStrategy;
use App\Models\TradingStrategyVersion;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CashManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * STR-16 / V4-SPEC-005: a recommendation-linked SELL affects only its owner.
 */
class Str16SiblingSellIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_recommendation_sell_exits_only_its_strategy_when_a_sibling_owns_the_same_stock(): void
    {
        [$user, $profile, $stock, $strategyA, $strategyB] = $this->twoStrategyPortfolio();
        app(CashManagementService::class)->deposit($profile, 10_000, 'str16-sibling-sell-seed', $user);

        $this->actingAs($user)->withProfileHeader($user, $profile);

        // Seed prerequisites through the normal review and recommendation-linked transaction APIs.
        $buyA = $this->approvedBuy($user, $profile, $stock, $strategyA, 4, 100);
        $buyB = $this->approvedBuy($user, $profile, $stock, $strategyB, 9, 200);
        $buyATransaction = Transaction::query()->where('recommendation_id', $buyA->id)->sole();
        $buyBTransaction = Transaction::query()->where('recommendation_id', $buyB->id)->sole();
        $ownerA = Holding::ownerKeyFor((int) $strategyA->id);
        $ownerB = Holding::ownerKeyFor((int) $strategyB->id);

        $this->assertSame((int) $buyA->executed_transaction_id, (int) $buyATransaction->id);
        $this->assertSame((int) $buyB->executed_transaction_id, (int) $buyBTransaction->id);
        $this->assertSame($ownerA, $buyATransaction->owner_key);
        $this->assertSame($ownerB, $buyBTransaction->owner_key);
        $this->assertSame((int) $buyA->id, (int) $buyATransaction->recommendation_id);
        $this->assertSame((int) $buyB->id, (int) $buyBTransaction->recommendation_id);
        $this->assertSame(Transaction::SOURCE_RECOMMENDATION, $buyBTransaction->source);
        $this->assertSame('buy', $buyBTransaction->type);
        $this->assertEqualsWithDelta(9.0, (float) $buyBTransaction->quantity, 0.0001);
        $this->assertEqualsWithDelta(200.0, (float) $buyBTransaction->price, 0.0001);

        $holdingA = $this->ownerHolding($profile, $stock, $ownerA);
        $holdingB = $this->ownerHolding($profile, $stock, $ownerB);
        $this->assertNotNull($holdingA);
        $this->assertNotNull($holdingB);
        $this->assertSame((int) $strategyA->id, (int) $holdingA->strategy_id);
        $this->assertSame((int) $strategyB->id, (int) $holdingB->strategy_id);
        $this->assertEqualsWithDelta(4.0, (float) $holdingA->quantity, 0.0001);
        $this->assertEqualsWithDelta(100.0, (float) $holdingA->avg_buy_price, 0.0001);
        $this->assertEqualsWithDelta(400.0, (float) $holdingA->invested_amount, 0.0001);
        $this->assertEqualsWithDelta(9.0, (float) $holdingB->quantity, 0.0001);
        $this->assertEqualsWithDelta(200.0, (float) $holdingB->avg_buy_price, 0.0001);
        $this->assertEqualsWithDelta(1_800.0, (float) $holdingB->invested_amount, 0.0001);

        $exitA = $this->pendingExit($profile, $stock, $strategyA);
        $review = $this->postJson('/api/v1/recommendations/'.$exitA->id.'/review', [
            'decision' => 'approved',
            'notes' => 'STR-16 Strategy A exit review',
        ]);
        $review->assertOk()->assertJsonPath('data.status', TradingRecommendation::STATUS_PENDING_EXECUTION);
        $exitA->refresh();
        $this->assertSame(TradingRecommendation::STATUS_PENDING_EXECUTION, $exitA->status);
        $this->assertSame((int) $stock->id, (int) $exitA->security_id);
        $this->assertSame((int) $strategyA->active_version_id, (int) $exitA->strategy_version_id);

        $cashBeforeSell = app(CashManagementService::class)->balance($profile);
        $transactionCountBeforeSell = Transaction::query()->where('profile_id', $profile->id)->count();
        $ordersBeforeSell = TradingOrder::query()->where('profile_id', $profile->id)->count();

        // Five shares are below aggregate A+B (13) but exceed Strategy A's four shares.
        $overOwnerQuantity = $this->postJson('/api/transactions', $this->sellPayload($stock, 5, 150, $exitA));
        $overOwnerQuantity->assertStatus(422)->assertJsonValidationErrors(['quantity']);

        $this->assertSame($transactionCountBeforeSell, Transaction::query()->where('profile_id', $profile->id)->count());
        $this->assertSame(0, Transaction::query()->where('profile_id', $profile->id)->where('type', 'sell')->count());
        $this->assertSame($ordersBeforeSell, TradingOrder::query()->where('profile_id', $profile->id)->count());
        $this->assertEqualsWithDelta($cashBeforeSell, app(CashManagementService::class)->balance($profile), 0.0001);
        $this->assertSame(TradingRecommendation::STATUS_PENDING_EXECUTION, $exitA->fresh()->status);
        $this->assertNull($exitA->fresh()->executed_transaction_id);
        $this->assertSame(TradingRecommendation::STATUS_EXECUTED, $buyA->fresh()->status);
        $this->assertSame(TradingRecommendation::STATUS_EXECUTED, $buyB->fresh()->status);
        $this->assertSame((int) $strategyB->active_version_id, (int) $buyB->fresh()->strategy_version_id);
        $this->assertSame((int) $buyBTransaction->id, (int) $buyB->fresh()->executed_transaction_id);
        $this->assertSame($ownerB, $buyBTransaction->fresh()->owner_key);
        $this->assertSame((int) $buyB->id, (int) $buyBTransaction->fresh()->recommendation_id);
        $this->assertEqualsWithDelta(9.0, (float) $buyBTransaction->fresh()->quantity, 0.0001);
        $this->assertEqualsWithDelta(200.0, (float) $buyBTransaction->fresh()->price, 0.0001);
        $this->assertHoldingState($this->ownerHolding($profile, $stock, $ownerA), $strategyA, 4, 100, 400);
        $this->assertHoldingState($this->ownerHolding($profile, $stock, $ownerB), $strategyB, 9, 200, 1_800);

        // The same reviewed exit can be completed with its own full quantity only.
        $sell = $this->postJson('/api/transactions', $this->sellPayload($stock, 4, 150, $exitA));
        $sell->assertCreated()
            ->assertJsonPath('tos.recommendation_id', $exitA->id)
            ->assertJsonPath('tos.recommendation_status', TradingRecommendation::STATUS_EXECUTED);

        $sellId = (int) $sell->json('data.id');
        $sellTransaction = Transaction::query()->findOrFail($sellId);
        $this->assertSame('sell', $sellTransaction->type);
        $this->assertEqualsWithDelta(4.0, (float) $sellTransaction->quantity, 0.0001);
        $this->assertEqualsWithDelta(150.0, (float) $sellTransaction->price, 0.0001);
        $this->assertSame((int) $exitA->id, (int) $sellTransaction->recommendation_id);
        $this->assertSame($ownerA, $sellTransaction->owner_key);
        $this->assertSame('recommendation', $sellTransaction->source);

        $executedExit = $exitA->fresh();
        $this->assertSame(TradingRecommendation::STATUS_EXECUTED, $executedExit->status);
        $this->assertSame((int) $stock->id, (int) $executedExit->security_id);
        $this->assertSame((int) $strategyA->active_version_id, (int) $executedExit->strategy_version_id);
        $this->assertSame($sellId, (int) $executedExit->executed_transaction_id);
        $this->assertSame(TradingRecommendation::STATUS_EXECUTED, $buyA->fresh()->status);
        $this->assertSame(TradingRecommendation::STATUS_EXECUTED, $buyB->fresh()->status);
        $this->assertSame((int) $strategyB->active_version_id, (int) $buyB->fresh()->strategy_version_id);
        $this->assertSame((int) $buyB->id, (int) $buyB->fresh()->executed_transaction_id);
        $this->assertSame($ownerB, $buyBTransaction->fresh()->owner_key);
        $this->assertSame((int) $buyB->id, (int) $buyBTransaction->fresh()->recommendation_id);
        $this->assertEqualsWithDelta(9.0, (float) $buyBTransaction->fresh()->quantity, 0.0001);
        $this->assertEqualsWithDelta(200.0, (float) $buyBTransaction->fresh()->price, 0.0001);

        $closedA = Holding::query()
            ->where('profile_id', $profile->id)
            ->where('stock_id', $stock->id)
            ->where('owner_key', $ownerA)
            ->first();
        $this->assertTrue($closedA === null || abs((float) $closedA->quantity) < 0.00001);
        $this->assertSame(1, Holding::query()
            ->where('profile_id', $profile->id)
            ->where('stock_id', $stock->id)
            ->where('quantity', '>', 0)
            ->count());
        $this->assertHoldingState($this->ownerHolding($profile, $stock, $ownerB), $strategyB, 9, 200, 1_800);

        $this->assertSame($transactionCountBeforeSell + 1, Transaction::query()->where('profile_id', $profile->id)->count());
        $this->assertSame(1, Transaction::query()->where('profile_id', $profile->id)->where('type', 'sell')->count());
        $this->assertSame($ordersBeforeSell, TradingOrder::query()->where('profile_id', $profile->id)->count());
        $this->assertEqualsWithDelta($cashBeforeSell + 600, app(CashManagementService::class)->balance($profile), 0.0001);
    }

    /** @return array{0: User, 1: PortfolioProfile, 2: Stock, 3: TradingStrategy, 4: TradingStrategy} */
    private function twoStrategyPortfolio(): array
    {
        $user = User::query()->create([
            'name' => 'STR-16 sibling sell',
            'email' => 'str16-sibling-sell-'.Str::lower(Str::random(8)).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);
        $stock = Stock::query()->create([
            'symbol' => 'S16'.strtoupper(Str::random(3)),
            'exchange' => 'NSE',
            'name' => 'STR-16 same security',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        return [
            $user,
            $profile,
            $stock,
            $this->makeStrategy($profile, 'STR-16 Momentum'),
            $this->makeStrategy($profile, 'STR-16 Value'),
        ];
    }

    private function approvedBuy(
        User $user,
        PortfolioProfile $profile,
        Stock $stock,
        TradingStrategy $strategy,
        int $quantity,
        int $price,
    ): TradingRecommendation {
        $this->actingAs($user)->withProfileHeader($user, $profile);
        $amount = $quantity * $price;
        $recommendation = TradingRecommendation::query()->create([
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
            'reference_price' => $price,
            'execution_plan' => [
                'side' => 'buy',
                'suggested_quantity' => $quantity,
                'suggested_investment_amount' => $amount,
                'capital_allocation' => ['status' => TradingRecommendation::ALLOCATION_FUNDED],
            ],
            'evidence' => ['capital_allocation' => ['status' => TradingRecommendation::ALLOCATION_FUNDED]],
            'generated_at' => now(),
        ]);

        $this->postJson('/api/v1/recommendations/'.$recommendation->id.'/review', [
            'decision' => 'approved',
            'notes' => 'STR-16 owned-lot prerequisite',
        ])->assertOk()->assertJsonPath('data.status', TradingRecommendation::STATUS_PENDING_EXECUTION);

        $fill = $this->postJson('/api/transactions', [
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => $quantity,
            'price' => $price,
            'fees' => 0,
            'transaction_date' => now()->toDateString(),
            'recommendation_id' => $recommendation->id,
        ]);
        $fill->assertCreated()->assertJsonPath('tos.recommendation_status', TradingRecommendation::STATUS_EXECUTED);

        return $recommendation->fresh();
    }

    private function pendingExit(
        PortfolioProfile $profile,
        Stock $stock,
        TradingStrategy $strategy,
    ): TradingRecommendation {
        return TradingRecommendation::query()->create([
            'profile_id' => $profile->id,
            'security_id' => $stock->id,
            'strategy_version_id' => $strategy->active_version_id,
            'recommendation_type' => TradingRecommendation::ACTION_EXIT_POSITION,
            'status' => TradingRecommendation::STATUS_PENDING_REVIEW,
            'priority' => 1,
            'strategy_score' => 20,
            'confidence' => 0.8,
            'risk_level' => 'medium',
            'generated_at' => now(),
        ]);
    }

    private function sellPayload(Stock $stock, int $quantity, int $price, TradingRecommendation $recommendation): array
    {
        return [
            'stock_id' => $stock->id,
            'type' => 'sell',
            'quantity' => $quantity,
            'price' => $price,
            'fees' => 0,
            'transaction_date' => now()->toDateString(),
            'recommendation_id' => $recommendation->id,
        ];
    }

    private function makeStrategy(PortfolioProfile $profile, string $name): TradingStrategy
    {
        $strategy = TradingStrategy::query()->create([
            'profile_id' => $profile->id,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'status' => TradingStrategy::STATUS_ACTIVE,
            'allocation_pct' => 0,
            'is_factory' => false,
        ]);
        $version = $this->createTestStrategyVersion([
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

    private function ownerHolding(PortfolioProfile $profile, Stock $stock, string $ownerKey): ?Holding
    {
        return Holding::query()
            ->where('profile_id', $profile->id)
            ->where('stock_id', $stock->id)
            ->where('owner_key', $ownerKey)
            ->first();
    }

    private function assertHoldingState(
        ?Holding $holding,
        TradingStrategy $strategy,
        float $quantity,
        float $averagePrice,
        float $investedAmount,
    ): void {
        $this->assertNotNull($holding);
        $this->assertSame((int) $strategy->id, (int) $holding->strategy_id);
        $this->assertSame(Holding::ownerKeyFor((int) $strategy->id), $holding->owner_key);
        $this->assertEqualsWithDelta($quantity, (float) $holding->quantity, 0.0001);
        $this->assertEqualsWithDelta($averagePrice, (float) $holding->avg_buy_price, 0.0001);
        $this->assertEqualsWithDelta($investedAmount, (float) $holding->invested_amount, 0.0001);
    }
}
