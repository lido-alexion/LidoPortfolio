<?php

namespace Tests\Feature;

use App\Engines\Execution\ExecutionEngine;
use App\Engines\Recommendation\RecommendationEngine;
use App\Models\Stock;
use App\Models\TradingRecommendation;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CashManagementService;
use App\Services\TransactionWriteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

/**
 * V4-FEAT-024 — RecommendationEngine owns markExecuted; ExecutionEngine orchestrates fill only.
 */
class RecommendationMarkExecutedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([\App\Jobs\BackfillHistoricalDataJob::class]);
    }

    public function test_lifecycle_mark_executed_writes_executed_status_and_converts_reservation(): void
    {
        [$user, $profile, $stock] = $this->seedUserStock();
        $rec = $this->makePendingBuyRecommendation($profile, $stock, 500);
        $rec->forceFill([
            'reserved_amount' => 500,
            'reservation_status' => TradingRecommendation::RESERVATION_RESERVED,
            'reserved_at' => now(),
        ])->save();

        $fill = $this->createBuy($profile, $stock, $user, 2, 100, 1);
        $fill->forceFill(['recommendation_id' => $rec->id])->save();

        $result = app(RecommendationEngine::class)->markExecuted($rec->fresh(), $fill);

        $this->assertSame(TradingRecommendation::STATUS_EXECUTED, $result->status);
        $this->assertSame((int) $fill->id, (int) $result->executed_transaction_id);
        $this->assertNotNull($result->executed_at);
        $this->assertSame(TradingRecommendation::RESERVATION_CONVERTED, $result->reservation_status);
        $this->assertEqualsWithDelta(201.0, (float) $result->executed_amount, 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $result->reserved_amount, 0.0001);
    }

    public function test_execution_engine_delegates_mark_executed_to_recommendation_engine(): void
    {
        [$user, $profile, $stock] = $this->seedUserStock();
        $rec = $this->makePendingBuyRecommendation($profile, $stock, 500);
        $fill = $this->createBuy($profile, $stock, $user, 2, 100);
        $fill->forceFill(['recommendation_id' => $rec->id, 'source' => Transaction::SOURCE_RECOMMENDATION])->save();

        $real = app(RecommendationEngine::class);
        $mock = Mockery::mock(RecommendationEngine::class);
        $mock->shouldReceive('markExecuted')
            ->once()
            ->andReturnUsing(fn ($r, $tx) => $real->markExecuted($r, $tx));
        $this->app->forgetInstance(ExecutionEngine::class);
        $this->app->instance(RecommendationEngine::class, $mock);

        $completed = app(ExecutionEngine::class)->completeRecommendationFromTransaction($profile, $fill, $user);

        $this->assertSame(TradingRecommendation::STATUS_EXECUTED, $completed->status);
        $this->assertSame((int) $fill->id, (int) $completed->executed_transaction_id);
    }

    public function test_execution_engine_source_does_not_write_recommendation_executed_fields(): void
    {
        $src = file_get_contents(app_path('Engines/Execution/ExecutionEngine.php'));
        $this->assertIsString($src);
        $this->assertStringContainsString('markExecuted', $src);
        $this->assertStringNotContainsString("'status' => TradingRecommendation::STATUS_EXECUTED", $src);
        $this->assertStringNotContainsString('convertReservation', $src);

        $lifecycle = file_get_contents(app_path('Engines/Recommendation/RecommendationLifecycleService.php'));
        $this->assertIsString($lifecycle);
        $this->assertStringContainsString("'status' => TradingRecommendation::STATUS_EXECUTED", $lifecycle);
        $this->assertStringContainsString('function markExecuted', $lifecycle);
    }

    public function test_complete_from_transaction_is_idempotent_for_the_same_fill(): void
    {
        [$user, $profile, $stock] = $this->seedUserStock();
        $rec = $this->makePendingBuyRecommendation($profile, $stock, 500);
        $fill = $this->createBuy($profile, $stock, $user, 2, 100);
        $fill->forceFill(['recommendation_id' => $rec->id, 'source' => Transaction::SOURCE_RECOMMENDATION])->save();

        $engine = app(ExecutionEngine::class);
        $first = $engine->completeRecommendationFromTransaction($profile, $fill, $user);
        $second = $engine->completeRecommendationFromTransaction($profile, $fill, $user);

        $this->assertSame((int) $first->id, (int) $second->id);
        $this->assertSame(TradingRecommendation::STATUS_EXECUTED, $second->status);
        $this->assertSame((int) $fill->id, (int) $second->executed_transaction_id);
        $this->assertSame(1, TradingRecommendation::query()->where('id', $rec->id)->count());
    }

    public function test_invalid_status_still_rejected_before_mark_executed(): void
    {
        [$user, $profile, $stock] = $this->seedUserStock();
        $rec = $this->makePendingBuyRecommendation($profile, $stock, 500);
        $rec->forceFill(['status' => TradingRecommendation::STATUS_PENDING_REVIEW])->save();

        $fill = $this->createBuy($profile, $stock, $user, 2, 100);
        $fill->forceFill(['recommendation_id' => $rec->id])->save();

        try {
            app(ExecutionEngine::class)->completeRecommendationFromTransaction($profile, $fill, $user);
            $this->fail('Expected ValidationException for non-pending recommendation.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('recommendation_id', $e->errors());
            $this->assertStringContainsString('pending execution', $e->errors()['recommendation_id'][0]);
        }

        $rec->refresh();
        $this->assertSame(TradingRecommendation::STATUS_PENDING_REVIEW, $rec->status);
        $this->assertNull($rec->executed_transaction_id);
    }

    public function test_api_fill_still_returns_executed_and_updates_holding(): void
    {
        [$user, $profile, $stock] = $this->seedUserStock();
        $rec = $this->makePendingBuyRecommendation($profile, $stock, 500);

        $this->actingAs($user)->withProfileHeader($user, $profile);
        $fill = $this->postJson('/api/transactions', [
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 2,
            'price' => 100,
            'fees' => 1,
            'transaction_date' => now()->toDateString(),
            'recommendation_id' => $rec->id,
        ]);

        $fill->assertCreated();
        $this->assertSame('executed', $fill->json('tos.recommendation_status'));
        $this->assertSame($rec->id, (int) $fill->json('tos.recommendation_id'));

        $rec->refresh();
        $this->assertSame(TradingRecommendation::STATUS_EXECUTED, $rec->status);
        $this->assertSame(TradingRecommendation::RESERVATION_CONVERTED, $rec->reservation_status);
        $this->assertDatabaseHas('portfolio_transactions', [
            'id' => $fill->json('data.id'),
            'recommendation_id' => $rec->id,
            'source' => Transaction::SOURCE_RECOMMENDATION,
        ]);
        $this->assertDatabaseHas('portfolio_holdings', [
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
        ]);
    }

    /**
     * @return array{0: User, 1: \App\Models\PortfolioProfile, 2: Stock}
     */
    protected function seedUserStock(float $cash = 50_000): array
    {
        $user = User::query()->create([
            'name' => 'Mark Exec User',
            'email' => 'mex-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);
        app(CashManagementService::class)->deposit($profile, $cash, 'seed', $user);

        $stock = Stock::query()->create([
            'symbol' => 'MX'.strtoupper(Str::random(4)),
            'exchange' => 'NSE',
            'name' => 'Mark Exec Stock',
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
