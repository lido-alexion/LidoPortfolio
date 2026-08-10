<?php

namespace Tests\Feature;

use App\Engines\Execution\ExecutionEngine;
use App\Engines\Recommendation\RecommendationLifecycleService;
use App\Models\Holding;
use App\Models\Stock;
use App\Models\TradingRecommendation;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CashManagementService;
use App\Services\TransactionWriteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * V2.1 WS-B financial integrity hardening (WSB-D1/D2/D4/D5).
 */
class FinancialIntegrityHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([\App\Jobs\BackfillHistoricalDataJob::class]);
    }

    /**
     * @return array{0: User, 1: \App\Models\PortfolioProfile, 2: Stock}
     */
    protected function seedUserStock(float $cash = 100_000): array
    {
        $user = User::query()->create([
            'name' => 'Fin User',
            'email' => 'fin-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);
        app(CashManagementService::class)->deposit($profile, $cash, 'seed', $user);

        $stock = Stock::query()->create([
            'symbol' => 'FIN'.strtoupper(Str::random(3)),
            'exchange' => 'NSE',
            'name' => 'Fin Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        return [$user, $profile, $stock];
    }

    protected function createBuy(
        \App\Models\PortfolioProfile $profile,
        Stock $stock,
        User $user,
        float $qty,
        float $price,
        float $fees = 0,
    ): Transaction {
        return app(TransactionWriteService::class)->create(
            $profile,
            $stock,
            [
                'type' => 'buy',
                'quantity' => $qty,
                'price' => $price,
                'fees' => $fees,
                'transaction_date' => now()->toDateString(),
            ],
            softFailSnapshots: true,
            user: $user,
            applyCash: true,
        );
    }

    public function test_wsb_d1_buy_update_syncs_cash_and_holdings(): void
    {
        [$user, $profile, $stock] = $this->seedUserStock(10_000);
        $tx = $this->createBuy($profile, $stock, $user, 10, 100); // cost 1000
        $cash = app(CashManagementService::class);
        $this->assertEqualsWithDelta(9_000.0, $cash->balance($profile), 0.001);

        $this->actingAs($user)->withProfileHeader($user, $profile);

        $response = $this->putJson('/api/transactions/'.$tx->id, [
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 10,
            'price' => 120,
            'fees' => 0,
            'transaction_date' => now()->toDateString(),
        ]);

        $response->assertOk();
        $this->assertEqualsWithDelta(8_800.0, $cash->balance($profile), 0.001); // 10000 - 1200
        $holding = Holding::query()->where('profile_id', $profile->id)->where('stock_id', $stock->id)->first();
        $this->assertNotNull($holding);
        $this->assertEqualsWithDelta(10.0, (float) $holding->quantity, 0.001);
        $this->assertEqualsWithDelta(120.0, (float) $holding->avg_buy_price, 0.001);
    }

    public function test_wsb_d1_sell_update_syncs_cash(): void
    {
        [$user, $profile, $stock] = $this->seedUserStock(10_000);
        $this->createBuy($profile, $stock, $user, 10, 100);
        $sell = app(TransactionWriteService::class)->create(
            $profile,
            $stock,
            [
                'type' => 'sell',
                'quantity' => 4,
                'price' => 150,
                'fees' => 0,
                'transaction_date' => now()->toDateString(),
            ],
            user: $user,
            applyCash: true,
        );
        // 10000 - 1000 + 600 = 9600
        $cash = app(CashManagementService::class);
        $this->assertEqualsWithDelta(9_600.0, $cash->balance($profile), 0.001);

        $this->actingAs($user)->withProfileHeader($user, $profile);
        $this->putJson('/api/transactions/'.$sell->id, [
            'stock_id' => $stock->id,
            'type' => 'sell',
            'quantity' => 4,
            'price' => 200,
            'fees' => 0,
            'transaction_date' => now()->toDateString(),
        ])->assertOk();

        // reverse +800 sell, apply +800 → net +200 vs prior sell: 9600 - 600 + 800 = 9800
        $this->assertEqualsWithDelta(9_800.0, $cash->balance($profile), 0.001);
    }

    public function test_wsb_d1_buy_to_sell_type_change_keeps_economics_consistent(): void
    {
        [$user, $profile, $stock] = $this->seedUserStock(10_000);
        $this->createBuy($profile, $stock, $user, 10, 100);
        $secondBuy = $this->createBuy($profile, $stock, $user, 5, 100);
        // balance 8500, qty 15

        $this->actingAs($user)->withProfileHeader($user, $profile);
        $this->putJson('/api/transactions/'.$secondBuy->id, [
            'stock_id' => $stock->id,
            'type' => 'sell',
            'quantity' => 5,
            'price' => 110,
            'fees' => 0,
            'transaction_date' => now()->toDateString(),
        ])->assertOk();

        $cash = app(CashManagementService::class);
        // reverse second buy (+500) then sell (+550) → 8500 + 500 + 550 = 9550
        // holdings: buy 10 + sell 5 → qty 5
        $this->assertEqualsWithDelta(9_550.0, $cash->balance($profile), 0.001);
        $holding = Holding::query()->where('profile_id', $profile->id)->where('stock_id', $stock->id)->first();
        $this->assertEqualsWithDelta(5.0, (float) $holding->quantity, 0.001);
    }

    public function test_wsb_d1_insufficient_cash_on_update_rolls_back(): void
    {
        [$user, $profile, $stock] = $this->seedUserStock(1_000);
        $tx = $this->createBuy($profile, $stock, $user, 5, 100); // cost 500, balance 500
        $cash = app(CashManagementService::class);
        $this->assertEqualsWithDelta(500.0, $cash->balance($profile), 0.001);

        $this->actingAs($user)->withProfileHeader($user, $profile);
        $response = $this->putJson('/api/transactions/'.$tx->id, [
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 20,
            'price' => 100,
            'fees' => 0,
            'transaction_date' => now()->toDateString(),
        ]);
        $response->assertStatus(422);

        $this->assertEqualsWithDelta(500.0, $cash->balance($profile), 0.001);
        $this->assertDatabaseHas('portfolio_transactions', [
            'id' => $tx->id,
            'quantity' => 5,
            'price' => 100,
        ]);
    }

    public function test_wsb_d1_update_is_profile_scoped(): void
    {
        [$user, $profile, $stock] = $this->seedUserStock(5_000);
        $tx = $this->createBuy($profile, $stock, $user, 2, 100);

        $other = User::query()->create([
            'name' => 'Other',
            'email' => 'other-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $this->defaultPortfolioFor($other);

        $this->actingAs($other)->withProfileHeader($other);
        $this->putJson('/api/transactions/'.$tx->id, [
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 2,
            'price' => 200,
            'fees' => 0,
            'transaction_date' => now()->toDateString(),
        ])->assertNotFound();
    }

    public function test_wsb_d2_delete_reverses_cash_and_holdings(): void
    {
        [$user, $profile, $stock] = $this->seedUserStock(5_000);
        $tx = $this->createBuy($profile, $stock, $user, 3, 100);
        $cash = app(CashManagementService::class);
        $this->assertEqualsWithDelta(4_700.0, $cash->balance($profile), 0.001);

        $this->actingAs($user)->withProfileHeader($user, $profile);
        $this->deleteJson('/api/transactions/'.$tx->id)->assertOk();

        $this->assertEqualsWithDelta(5_000.0, $cash->balance($profile), 0.001);
        $this->assertDatabaseMissing('portfolio_transactions', ['id' => $tx->id]);
        $holding = Holding::query()->where('profile_id', $profile->id)->where('stock_id', $stock->id)->first();
        $this->assertTrue($holding === null || (float) $holding->quantity <= 0.00001);
    }

    public function test_wsb_d2_delete_rolls_back_when_intermediate_step_fails(): void
    {
        [$user, $profile, $stock] = $this->seedUserStock(5_000);
        $tx = $this->createBuy($profile, $stock, $user, 2, 100);
        $cash = app(CashManagementService::class);
        $balanceBefore = $cash->balance($profile);

        $this->partialMock(ExecutionEngine::class, function ($mock) {
            $mock->shouldReceive('revertLinkedFillBeforeTransactionDelete')
                ->once()
                ->andThrow(new \RuntimeException('injected intermediate failure'));
        });

        $this->actingAs($user)->withProfileHeader($user, $profile);
        $response = $this->deleteJson('/api/transactions/'.$tx->id);
        $response->assertStatus(500);

        $this->assertEqualsWithDelta($balanceBefore, $cash->balance($profile), 0.001);
        $this->assertDatabaseHas('portfolio_transactions', ['id' => $tx->id]);
    }

    public function test_wsb_d5_cannot_overwrite_execution_with_different_transaction(): void
    {
        [$user, $profile, $stock] = $this->seedUserStock(50_000);
        $rec = $this->makePendingBuyRecommendation($profile, $stock, 500);

        $fill = $this->createBuy($profile, $stock, $user, 2, 100);
        $fill->forceFill(['recommendation_id' => $rec->id, 'source' => Transaction::SOURCE_RECOMMENDATION])->save();

        $engine = app(ExecutionEngine::class);
        $completed = $engine->completeRecommendationFromTransaction($profile, $fill, $user);
        $this->assertSame(TradingRecommendation::STATUS_EXECUTED, $completed->status);
        $this->assertSame((int) $fill->id, (int) $completed->executed_transaction_id);

        $other = $this->createBuy($profile, $stock, $user, 1, 100);
        $other->forceFill(['recommendation_id' => $rec->id])->save();

        try {
            $engine->completeRecommendationFromTransaction($profile, $other, $user);
            $this->fail('Expected overwrite rejection');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('recommendation_id', $e->errors());
        }

        $rec->refresh();
        $this->assertSame((int) $fill->id, (int) $rec->executed_transaction_id);
        $this->assertSame(TradingRecommendation::STATUS_EXECUTED, $rec->status);
    }

    public function test_wsb_d5_same_transaction_completion_is_idempotent(): void
    {
        [$user, $profile, $stock] = $this->seedUserStock(50_000);
        $rec = $this->makePendingBuyRecommendation($profile, $stock, 500);

        $fill = $this->createBuy($profile, $stock, $user, 2, 100);
        $fill->forceFill(['recommendation_id' => $rec->id, 'source' => Transaction::SOURCE_RECOMMENDATION])->save();

        $engine = app(ExecutionEngine::class);
        $first = $engine->completeRecommendationFromTransaction($profile, $fill, $user);
        $second = $engine->completeRecommendationFromTransaction($profile, $fill, $user);

        $this->assertSame((int) $first->id, (int) $second->id);
        $this->assertSame((int) $fill->id, (int) $second->executed_transaction_id);
    }

    public function test_wsb_d5_cross_profile_completion_denied(): void
    {
        [$user, $profile, $stock] = $this->seedUserStock(50_000);
        $rec = $this->makePendingBuyRecommendation($profile, $stock, 500);

        $otherUser = User::query()->create([
            'name' => 'Other Fin',
            'email' => 'fin-o-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $otherProfile = $this->defaultPortfolioFor($otherUser);
        app(CashManagementService::class)->deposit($otherProfile, 50_000, 'seed', $otherUser);

        $foreignTx = $this->createBuy($otherProfile, $stock, $otherUser, 1, 100);
        $foreignTx->forceFill(['recommendation_id' => $rec->id])->save();

        try {
            app(ExecutionEngine::class)->completeRecommendationFromTransaction($otherProfile, $foreignTx, $otherUser);
            $this->fail('Expected cross-profile denial');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('recommendation_id', $e->errors());
        }
    }

    public function test_wsb_d4_soft_reservation_allows_manual_buy_against_balance(): void
    {
        [$user, $profile, $stock] = $this->seedUserStock(1_000);
        $rec = $this->makePendingBuyRecommendation($profile, $stock, 800);
        app(RecommendationLifecycleService::class)->reserveForApproval($rec->fresh());

        $cash = app(CashManagementService::class);
        $this->assertEqualsWithDelta(800.0, $cash->reservedCash($profile), 0.001);
        $this->assertEqualsWithDelta(1_000.0, $cash->balance($profile), 0.001);

        // Soft reservation: manual buy may consume balance even while reserved > remaining available.
        $buy = $this->createBuy($profile, $stock, $user, 5, 100); // 500
        $this->assertNotNull($buy->id);
        $this->assertEqualsWithDelta(500.0, $cash->balance($profile), 0.001);
        $this->assertEqualsWithDelta(800.0, $cash->reservedCash($profile), 0.001);

        // Withdraw still respects available (= balance - reserved) under soft model.
        $this->actingAs($user)->withProfileHeader($user, $profile);
        $this->postJson('/api/cash/withdraw', [
            'amount' => 100,
            'reason' => 'should fail',
        ])->assertStatus(422);
    }

    public function test_wsb_d4_soft_reservation_allows_bulk_buy_against_balance(): void
    {
        [$user, $profile, $stock] = $this->seedUserStock(2_000);
        $rec = $this->makePendingBuyRecommendation($profile, $stock, 1_500);
        app(RecommendationLifecycleService::class)->reserveForApproval($rec->fresh());

        $this->mock(\App\Services\StockValidationService::class, function ($mock) use ($stock) {
            $mock->shouldReceive('validateAndPersist')
                ->andReturn(\App\Support\StockValidationResult::valid($stock, 'test'));
            $mock->shouldReceive('validate')
                ->andReturn(\App\Support\StockValidationResult::valid($stock, 'test'));
        });

        $this->actingAs($user)->withProfileHeader($user, $profile);
        $batchId = (string) Str::uuid();
        $response = $this->postJson('/api/transactions/bulk', [
            'batch_id' => $batchId,
            'rows' => [[
                'row_id' => (string) Str::uuid(),
                'symbol' => $stock->symbol,
                'exchange' => 'NSE',
                'type' => 'buy',
                'quantity' => 10,
                'price' => 100,
                'fees' => 0,
                'transaction_date' => now()->toDateString(),
            ]],
        ]);
        $response->assertCreated()->assertJsonPath('status', 'committed');

        $cash = app(CashManagementService::class);
        $this->assertEqualsWithDelta(1_000.0, $cash->balance($profile), 0.001);
        $this->assertEqualsWithDelta(1_500.0, $cash->reservedCash($profile), 0.001);
    }

    public function test_wsb_d4_cancel_releases_reservation(): void
    {
        [$user, $profile, $stock] = $this->seedUserStock(5_000);
        $rec = $this->makePendingBuyRecommendation($profile, $stock, 1_000);
        app(RecommendationLifecycleService::class)->reserveForApproval($rec->fresh());
        $this->assertEqualsWithDelta(1_000.0, app(CashManagementService::class)->reservedCash($profile), 0.001);

        $this->actingAs($user)->withProfileHeader($user, $profile);
        $this->postJson('/api/v1/recommendations/'.$rec->id.'/cancel-execution', [
            'reason' => 'other',
            'notes' => 'test cancel',
        ])->assertOk();

        $this->assertEqualsWithDelta(0.0, app(CashManagementService::class)->reservedCash($profile), 0.001);
    }

    protected function makePendingBuyRecommendation(
        \App\Models\PortfolioProfile $profile,
        Stock $stock,
        float $amount,
    ): TradingRecommendation {
        return TradingRecommendation::query()->create([
            'profile_id' => $profile->id,
            'security_id' => $stock->id,
            'recommendation_type' => 'OPEN_POSITION',
            'status' => TradingRecommendation::STATUS_PENDING_EXECUTION,
            'priority' => 1,
            'strategy_score' => 80,
            'confidence' => 0.8,
            'risk_level' => 'medium',
            'suggested_allocation_amount' => $amount,
            'reference_price' => 100,
            'execution_plan' => [
                'suggested_quantity' => max(1, (int) ($amount / 100)),
                'suggested_investment_amount' => $amount,
                'side' => 'buy',
            ],
            'approved_at' => now(),
            'generated_at' => now(),
            'reservation_status' => TradingRecommendation::RESERVATION_NONE,
            'reserved_amount' => 0,
        ]);
    }
}
