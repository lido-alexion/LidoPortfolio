<?php

namespace Tests\Feature\Execution;

use App\Engines\Execution\LiveBrokerExecutionService;
use App\Models\BrokerConnection;
use App\Models\CapitalRequest;
use App\Models\CashLedgerEntry;
use App\Models\Holding;
use App\Models\PortfolioProfile;
use App\Models\PositionProtection;
use App\Models\Stock;
use App\Models\TradingOrder;
use App\Models\TradingRecommendation;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Broker\BrokerOrderSnapshot;
use App\Services\Broker\FakeBrokerGateway;
use App\Services\CashManagementService;
use App\Services\CorporateActionService;
use App\Services\Ownership\HoldingAdoptionService;
use App\Services\Security\TotpService;
use App\Services\Protection\PositionProtectionService;
use App\Services\StrategyConfigurationService;
use App\Services\TransactionWriteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * V4-FEAT-002 — Advanced Broker Orders (GTT Target / Stop-Loss).
 */
class AdvancedOrdersFeatureTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, list<string>> */
    protected array $recoveryCodes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        Http::preventStrayRequests();
        app(FakeBrokerGateway::class)->reset();
    }

    public function test_gtt_target_placement_uses_strategy_target_price(): void
    {
        [$user, $profile, $holding] = $this->readyStrategyPosition(qty: 10, price: 100, targetAmount: 1500);
        $this->setMode($user, $profile, PortfolioProfile::EXECUTION_MODE_SEMI_AUTOMATIC);

        $res = $this->place($holding, 'target', $user)->assertCreated();
        $this->assertSame('target', $res->json('data.protection_type'));
        $this->assertSame('active', $res->json('data.state'));
        $this->assertEqualsWithDelta(150.0, (float) $res->json('data.trigger_price'), 0.0001);
        $this->assertSame(1, app(FakeBrokerGateway::class)->gttPlaceCalls);
        $placed = app(FakeBrokerGateway::class)->gttsPlaced[0];
        $this->assertEqualsWithDelta(150.0, $placed->triggerPrice, 0.0001);
        $this->assertSame('target', $placed->protectionType);
    }

    public function test_gtt_stop_loss_placement_uses_strategy_stop_price(): void
    {
        [$user, $profile, $holding] = $this->readyStrategyPosition();
        $this->setMode($user, $profile, PortfolioProfile::EXECUTION_MODE_SEMI_AUTOMATIC);

        $res = $this->place($holding, 'stop', $user)->assertCreated();
        $this->assertSame('stop', $res->json('data.protection_type'));
        $this->assertEqualsWithDelta(90.0, (float) $res->json('data.trigger_price'), 0.0001);
        $placed = app(FakeBrokerGateway::class)->gttsPlaced[0];
        $this->assertEqualsWithDelta(90.0, $placed->triggerPrice, 0.0001);
        $this->assertSame('stop', $placed->protectionType);
    }

    public function test_only_one_protection_and_target_replaces_stop(): void
    {
        [$user, $profile, $holding] = $this->readyStrategyPosition(qty: 10, price: 100, targetAmount: 1500);
        $this->setMode($user, $profile, PortfolioProfile::EXECUTION_MODE_SEMI_AUTOMATIC);

        $stop = $this->place($holding, 'stop', $user)->assertCreated();
        $target = $this->place($holding, 'target', $user)->assertCreated();

        $this->assertSame(1, PositionProtection::query()->open()->count());
        $this->assertSame('target', $target->json('data.protection_type'));
        $this->assertSame('cancelled', PositionProtection::query()->find($stop->json('data.id'))->state);
        $this->assertSame(1, app(FakeBrokerGateway::class)->gttCancelCalls);
    }

    public function test_stop_replaces_target(): void
    {
        [$user, $profile, $holding] = $this->readyStrategyPosition(qty: 10, price: 100, targetAmount: 1500);
        $this->setMode($user, $profile, PortfolioProfile::EXECUTION_MODE_SEMI_AUTOMATIC);

        $target = $this->place($holding, 'target', $user)->assertCreated();
        $stop = $this->place($holding, 'stop', $user)->assertCreated();

        $this->assertSame(1, PositionProtection::query()->open()->count());
        $this->assertSame('stop', $stop->json('data.protection_type'));
        $this->assertSame('cancelled', PositionProtection::query()->find($target->json('data.id'))->state);
    }

    public function test_manual_mode_does_not_auto_place_and_rejects_explicit_place(): void
    {
        [$user, $profile, $holding] = $this->readyStrategyPosition();
        $this->assertSame(PortfolioProfile::EXECUTION_MODE_MANUAL, $profile->executionMode());

        $this->place($holding, 'stop', $user)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'EXECUTION_MODE_MANUAL');
        $this->assertSame(0, app(FakeBrokerGateway::class)->gttPlaceCalls);
        $this->assertSame(0, PositionProtection::query()->count());
    }

    public function test_semi_automatic_requires_explicit_action_and_totp(): void
    {
        [$user, $profile, $holding] = $this->readyStrategyPosition();
        $this->setMode($user, $profile, PortfolioProfile::EXECUTION_MODE_SEMI_AUTOMATIC);

        $this->postJson('/api/v1/protections', [
            'holding_id' => $holding->id,
            'type' => 'stop',
        ])->assertStatus(403)->assertJsonPath('error.code', 'TOTP_REQUIRED');
        $this->assertSame(0, app(FakeBrokerGateway::class)->gttPlaceCalls);

        $this->place($holding, 'stop', $user)->assertCreated();
        $this->assertSame(1, app(FakeBrokerGateway::class)->gttPlaceCalls);
    }

    public function test_automatic_places_stop_after_buy_fill_without_per_order_totp(): void
    {
        [$user, $profile] = $this->actingReadyUser();
        $this->setMode($user, $profile, PortfolioProfile::EXECUTION_MODE_AUTOMATIC, confirm: true);
        $version = app(StrategyConfigurationService::class)->ensureActive($profile);
        $stock = $this->stock();
        $rec = $this->pendingBuy($profile, $stock, 500);
        $rec->forceFill([
            'status' => TradingRecommendation::STATUS_PENDING_REVIEW,
            'approved_at' => null,
            'strategy_version_id' => $version->id,
        ])->save();

        app(LiveBrokerExecutionService::class)->submitAutomaticForProfile($profile->fresh(['user']));
        $this->assertSame(0, app(FakeBrokerGateway::class)->gttPlaceCalls);

        $order = TradingOrder::query()->where('recommendation_id', $rec->id)->first();
        $this->assertNotNull($order);
        app(FakeBrokerGateway::class)->seedSnapshot(new BrokerOrderSnapshot(
            $order->broker_order_id,
            'filled',
            (float) $order->quantity,
            0,
            100,
            'COMPLETE',
        ));
        app(LiveBrokerExecutionService::class)->reconcileOrder($profile, $order->fresh());

        $holding = Holding::query()
            ->where('profile_id', $profile->id)
            ->where('stock_id', $stock->id)
            ->where('quantity', '>', 0)
            ->first();
        $this->assertNotNull($holding);
        $this->assertNotNull($holding->strategy_id);

        $this->assertSame(1, app(FakeBrokerGateway::class)->gttPlaceCalls);
        $protection = PositionProtection::query()->open()->first();
        $this->assertNotNull($protection);
        $this->assertSame(PositionProtection::TYPE_STOP, $protection->protection_type);
        $this->assertSame(PositionProtection::STATE_ACTIVE, $protection->state);
        $this->assertSame(TradingRecommendation::STATUS_EXECUTED, $rec->fresh()->status);
    }

    public function test_entitlement_and_totp_are_enforced(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        app(CashManagementService::class)->deposit($profile, 100_000, 'seed', $user);
        $this->connectKite($user);
        $this->actingAs($user)->withProfileHeader($user, $profile);
        $this->postJson('/api/v1/totp/begin')->assertOk();
        $otp = app(TotpService::class)->currentOtpForTests($user->fresh());
        $codes = $this->postJson('/api/v1/totp/confirm', ['code' => $otp])->assertOk()->json('data.recovery_codes');
        $this->recoveryCodes[$user->id] = $codes;

        $holding = $this->strategyHolding($profile, $this->stock());
        $profile->forceFill(['execution_mode' => PortfolioProfile::EXECUTION_MODE_SEMI_AUTOMATIC])->save();

        $this->postJson('/api/v1/protections', [
            'holding_id' => $holding->id,
            'type' => 'stop',
            'recovery_code' => $this->totpCode($user),
        ])->assertStatus(403)->assertJsonPath('error.code', 'EXECUTION_NOT_ENTITLED');
    }

    public function test_missing_target_or_stop_marks_needs_attention_without_placing(): void
    {
        [$user, $profile, $holding] = $this->readyStrategyPosition(qty: 10, price: 100, targetAmount: 0);
        $holding->forceFill(['target_amount' => null])->save();
        $this->setMode($user, $profile, PortfolioProfile::EXECUTION_MODE_SEMI_AUTOMATIC);

        $this->place($holding, 'target', $user)
            ->assertCreated()
            ->assertJsonPath('data.state', 'needs_attention')
            ->assertJsonPath('data.last_error', 'missing_target');
        $this->assertSame(0, app(FakeBrokerGateway::class)->gttPlaceCalls);

        $emptyStop = $this->strategyHolding($profile, $this->stock(), qty: 10, price: 100, withBuy: false);
        $this->place($emptyStop, 'stop', $user)
            ->assertCreated()
            ->assertJsonPath('data.state', 'needs_attention')
            ->assertJsonPath('data.last_error', 'missing_stop');
    }

    public function test_material_buy_and_sell_synchronize_non_material_does_not(): void
    {
        [$user, $profile, $holding] = $this->readyStrategyPosition();
        $this->setMode($user, $profile, PortfolioProfile::EXECUTION_MODE_SEMI_AUTOMATIC);
        $this->place($holding, 'stop', $user)->assertCreated();
        $fake = app(FakeBrokerGateway::class);
        $this->assertSame(1, $fake->gttPlaceCalls);
        $modifies = $fake->gttModifyCalls;

        $stock = Stock::query()->find($holding->stock_id);
        $buyTx = Transaction::query()->where('stock_id', $stock->id)->where('type', 'buy')->first();
        app(TransactionWriteService::class)->create($profile, $stock, [
            'type' => 'buy',
            'quantity' => 5,
            'price' => 100,
            'fees' => 0,
            'transaction_date' => now()->toDateString(),
            'source' => Transaction::SOURCE_RECOMMENDATION,
            'recommendation_id' => $buyTx->recommendation_id,
        ]);
        $this->assertGreaterThan($modifies, $fake->gttModifyCalls);
        $afterBuy = $fake->gttModifyCalls;

        $holding = $holding->fresh();
        app(TransactionWriteService::class)->create($profile, $stock, [
            'type' => 'sell',
            'quantity' => 2,
            'price' => 110,
            'fees' => 0,
            'transaction_date' => now()->toDateString(),
            'source' => Transaction::SOURCE_MANUAL,
        ]);
        $this->assertGreaterThan($afterBuy, $fake->gttModifyCalls);
        $afterSell = $fake->gttModifyCalls;

        $tx = Transaction::query()->where('stock_id', $stock->id)->where('type', 'buy')->first();
        app(TransactionWriteService::class)->update($profile, $tx, $stock, [
            'type' => 'buy',
            'quantity' => $tx->quantity,
            'price' => $tx->price,
            'fees' => $tx->fees,
            'transaction_date' => $tx->transaction_date->toDateString(),
            'notes' => 'non-material notes only',
        ]);
        $this->assertSame($afterSell, $fake->gttModifyCalls);
        $this->assertSame(1, PositionProtection::query()->open()->count());
    }

    public function test_rights_tagged_buy_synchronizes_protection_like_a_purchase(): void
    {
        [$user, $profile, $holding] = $this->readyStrategyPosition();
        $this->setMode($user, $profile, PortfolioProfile::EXECUTION_MODE_SEMI_AUTOMATIC);
        $this->place($holding, 'stop', $user)->assertCreated();
        $fake = app(FakeBrokerGateway::class);
        $modifies = $fake->gttModifyCalls;

        $stock = Stock::query()->find($holding->stock_id);
        $buyTx = Transaction::query()->where('stock_id', $stock->id)->where('type', 'buy')->first();
        $tx = app(TransactionWriteService::class)->create($profile, $stock, [
            'type' => 'buy',
            'quantity' => 4,
            'price' => 50,
            'fees' => 0,
            'transaction_date' => now()->toDateString(),
            'source' => Transaction::SOURCE_RIGHTS,
            'recommendation_id' => $buyTx->recommendation_id,
        ]);
        $this->assertSame(Transaction::SOURCE_RECOMMENDATION, $tx->source);
        $this->assertGreaterThan($modifies, $fake->gttModifyCalls);

        $afterPurchase = $fake->gttModifyCalls;
        $strategyLot = $holding->fresh();
        $strategyLot->forceFill([
            'quantity' => (float) $strategyLot->quantity + 2,
            'invested_amount' => (float) $strategyLot->invested_amount + 100,
        ])->save();
        app(PositionProtectionService::class)->afterCommittedFill(
            $profile,
            $stock,
            'buy',
            Transaction::SOURCE_RIGHTS,
        );
        $this->assertGreaterThan($afterPurchase, $fake->gttModifyCalls);

        $afterRights = $fake->gttModifyCalls;
        app(PositionProtectionService::class)->afterCommittedFill(
            $profile,
            $stock,
            'buy',
            Transaction::SOURCE_BONUS,
        );
        $this->assertSame($afterRights, $fake->gttModifyCalls);
        $this->assertSame(1, PositionProtection::query()->open()->count());
    }

    public function test_modify_when_supported_else_cancel_and_replace(): void
    {
        [$user, $profile, $holding] = $this->readyStrategyPosition();
        $this->setMode($user, $profile, PortfolioProfile::EXECUTION_MODE_SEMI_AUTOMATIC);
        $this->place($holding, 'stop', $user)->assertCreated();
        $fake = app(FakeBrokerGateway::class);
        $stock = Stock::query()->find($holding->stock_id);
        $buyTx = Transaction::query()->where('stock_id', $stock->id)->first();

        app(TransactionWriteService::class)->create($profile, $stock, [
            'type' => 'buy',
            'quantity' => 3,
            'price' => 100,
            'fees' => 0,
            'transaction_date' => now()->toDateString(),
            'source' => Transaction::SOURCE_RECOMMENDATION,
            'recommendation_id' => $buyTx->recommendation_id,
        ]);
        $this->assertSame(1, $fake->gttModifyCalls);
        $this->assertSame(1, $fake->gttPlaceCalls);

        $fake->supportsModify = false;
        $cancels = $fake->gttCancelCalls;
        app(TransactionWriteService::class)->create($profile, $stock, [
            'type' => 'buy',
            'quantity' => 2,
            'price' => 100,
            'fees' => 0,
            'transaction_date' => now()->toDateString(),
            'source' => Transaction::SOURCE_RECOMMENDATION,
            'recommendation_id' => $buyTx->recommendation_id,
        ]);
        $this->assertSame($cancels + 1, $fake->gttCancelCalls);
        $this->assertSame(2, $fake->gttPlaceCalls);
        $this->assertSame(1, PositionProtection::query()->open()->count());
    }

    public function test_sync_retry_then_needs_attention(): void
    {
        [$user, $profile, $holding] = $this->readyStrategyPosition();
        $this->setMode($user, $profile, PortfolioProfile::EXECUTION_MODE_SEMI_AUTOMATIC);
        $fake = app(FakeBrokerGateway::class);
        $fake->gttFailRemaining = 1;
        $res = $this->place($holding, 'stop', $user)->assertCreated();
        $this->assertSame('synchronizing', $res->json('data.state'));
        $id = $res->json('data.id');

        $this->postJson("/api/v1/protections/{$id}/reconcile")->assertOk()
            ->assertJsonPath('data.state', 'active');

        $holding2 = $this->strategyHolding($profile, $this->stock());
        $fake->gttFailRemaining = 5;
        $fail = $this->place($holding2, 'stop', $user)->assertCreated();
        $failId = $fail->json('data.id');
        $this->postJson("/api/v1/protections/{$failId}/reconcile");
        $this->postJson("/api/v1/protections/{$failId}/reconcile")
            ->assertOk()
            ->assertJsonPath('data.state', 'needs_attention');
        $this->assertGreaterThan(0, (float) $holding2->fresh()->quantity);
    }

    public function test_partial_gtt_sell_fills_first_then_syncs_on_next_cycle(): void
    {
        [$user, $profile, $holding] = $this->readyStrategyPosition(qty: 10, price: 100);
        $this->setMode($user, $profile, PortfolioProfile::EXECUTION_MODE_SEMI_AUTOMATIC);
        $res = $this->place($holding, 'stop', $user)->assertCreated();
        $id = $res->json('data.id');
        $gttId = $res->json('data.broker_gtt_id');
        $fake = app(FakeBrokerGateway::class);
        $modifies = $fake->gttModifyCalls;
        $txBefore = Transaction::query()->count();

        $fake->seedGttFill($gttId, 4, 90, stillActive: true);
        $this->postJson("/api/v1/protections/{$id}/reconcile")->assertOk();
        $this->assertSame($modifies, $fake->gttModifyCalls);
        $this->assertSame($txBefore + 1, Transaction::query()->count());
        $this->assertEqualsWithDelta(6.0, (float) $holding->fresh()->quantity, 0.0001);
        $this->assertTrue((bool) PositionProtection::query()->find($id)->sync_deferred);

        $this->postJson("/api/v1/protections/{$id}/reconcile")->assertOk();
        $this->assertGreaterThan($modifies, $fake->gttModifyCalls);
        $this->assertEqualsWithDelta(6.0, (float) PositionProtection::query()->find($id)->quantity, 0.0001);
        $this->assertSame(TradingRecommendation::STATUS_EXECUTED, TradingRecommendation::query()->first()->status);
    }

    public function test_full_sell_clears_protection_with_no_orphans(): void
    {
        [$user, $profile, $holding] = $this->readyStrategyPosition(qty: 10, price: 100);
        $this->setMode($user, $profile, PortfolioProfile::EXECUTION_MODE_SEMI_AUTOMATIC);
        $res = $this->place($holding, 'stop', $user)->assertCreated();
        $gttId = $res->json('data.broker_gtt_id');
        app(FakeBrokerGateway::class)->seedGttFill($gttId, 10, 90, stillActive: false);

        $this->postJson('/api/v1/protections/'.$res->json('data.id').'/reconcile')->assertOk();
        $this->assertSame(0, PositionProtection::query()->open()->count());
        $this->assertSame(PositionProtection::STATE_RECONCILED, PositionProtection::query()->find($res->json('data.id'))->state);
        $this->assertSame('cancelled', app(FakeBrokerGateway::class)->gtts[$gttId]['snapshot']->status);
        $openQty = Holding::query()
            ->where('profile_id', $profile->id)
            ->where('stock_id', $holding->stock_id)
            ->where('quantity', '>', 0)
            ->count();
        $this->assertSame(0, $openQty);
    }

    public function test_ambiguous_broker_response_does_not_duplicate_orders(): void
    {
        [$user, $profile, $holding] = $this->readyStrategyPosition();
        $this->setMode($user, $profile, PortfolioProfile::EXECUTION_MODE_SEMI_AUTOMATIC);
        $fake = app(FakeBrokerGateway::class);
        $fake->nextGttPlaceAmbiguous = true;
        $res = $this->place($holding, 'stop', $user)->assertCreated();
        $this->assertSame('synchronizing', $res->json('data.state'));
        $this->assertSame('unknown', $res->json('data.broker_status'));
        $this->assertSame(1, $fake->gttPlaceCalls);

        $this->place($holding, 'stop', $user);
        $this->assertSame(1, $fake->gttPlaceCalls);
        $this->assertSame(1, PositionProtection::query()->open()->count());
    }

    public function test_spec001_merged_position_and_spec003_restatement_sync_protection(): void
    {
        [$user, $profile, $dest] = $this->readyStrategyPosition(qty: 100, price: 1200, targetAmount: 170000);
        $this->setMode($user, $profile, PortfolioProfile::EXECUTION_MODE_SEMI_AUTOMATIC);
        $this->place($dest, 'stop', $user)->assertCreated();
        $fake = app(FakeBrokerGateway::class);
        $modifies = $fake->gttModifyCalls;

        $stock = Stock::query()->find($dest->stock_id);
        $unmanaged = Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'quantity' => 50,
            'avg_buy_price' => 1000,
            'invested_amount' => 50000,
            'updated_at' => now(),
        ]);
        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 50,
            'price' => 1000,
            'fees' => 0,
            'transaction_date' => '2026-01-01',
            'source' => Transaction::SOURCE_MANUAL,
        ]);

        app(HoldingAdoptionService::class)->adopt($profile, $unmanaged, (int) $dest->strategy_id, $user);
        $this->assertGreaterThan($modifies, $fake->gttModifyCalls);
        $unmanagedFresh = Holding::query()->find($unmanaged->id);
        $this->assertTrue($unmanagedFresh === null || (float) $unmanagedFresh->quantity <= 0.00001);
        $this->assertSame(1, PositionProtection::query()->open()->count());
        $merged = Holding::query()->find($dest->id);
        $this->assertEqualsWithDelta(150.0, (float) $merged->quantity, 0.0001);

        $afterMerge = $fake->gttModifyCalls;
        app(CorporateActionService::class)->apply($profile, $stock, [
            'action_type' => 'split',
            'ratio_from' => 1,
            'ratio_to' => 2,
            'ex_date' => now()->toDateString(),
        ]);
        $this->assertGreaterThan($afterMerge, $fake->gttModifyCalls);
        $this->assertEqualsWithDelta(300.0, (float) $merged->fresh()->quantity, 0.0001);
        $protection = PositionProtection::query()->open()->first();
        $this->assertEqualsWithDelta(300.0, (float) $protection->quantity, 0.0001);
    }

    public function test_cross_user_and_cross_portfolio_isolation(): void
    {
        [$user, $profile, $holding] = $this->readyStrategyPosition();
        $this->setMode($user, $profile, PortfolioProfile::EXECUTION_MODE_SEMI_AUTOMATIC);
        $id = $this->place($holding, 'stop', $user)->assertCreated()->json('data.id');

        $other = User::factory()->create();
        $other->forceFill(['automated_execution_entitled_at' => now()])->save();
        $otherProfile = $this->defaultPortfolioFor($other);
        $this->actingAs($other)->withProfileHeader($other, $otherProfile)
            ->getJson('/api/v1/protections/'.$id)
            ->assertStatus(404);

        $second = $this->createPortfolioProfile($user, 'Second', false);
        $this->assertNotSame($profile->id, $second->id);
        $this->actingAs($user)->withProfileHeader($user, $second)
            ->getJson('/api/v1/protections/'.$id)
            ->assertStatus(404);
    }

    public function test_place_does_not_write_ledger_cash_or_execute_recommendation(): void
    {
        [$user, $profile, $holding] = $this->readyStrategyPosition();
        $this->setMode($user, $profile, PortfolioProfile::EXECUTION_MODE_SEMI_AUTOMATIC);
        $tx = Transaction::query()->count();
        $cash = CashLedgerEntry::query()->count();
        $rec = TradingRecommendation::query()->first();
        $this->assertSame(TradingRecommendation::STATUS_EXECUTED, $rec->status);

        $pending = TradingRecommendation::query()->create([
            'profile_id' => $profile->id,
            'security_id' => $holding->stock_id,
            'recommendation_type' => 'OPEN_POSITION',
            'status' => TradingRecommendation::STATUS_PENDING_EXECUTION,
            'priority' => 1,
            'strategy_score' => 80,
            'confidence' => 0.8,
            'risk_level' => 'medium',
            'generated_at' => now(),
            'approved_at' => now(),
        ]);

        $this->place($holding, 'stop', $user)->assertCreated();
        $this->assertSame($tx, Transaction::query()->count());
        $this->assertSame($cash, CashLedgerEntry::query()->count());
        $this->assertSame(TradingRecommendation::STATUS_PENDING_EXECUTION, $pending->fresh()->status);
        $this->assertSame(0, CapitalRequest::query()->count());
    }

    /**
     * @return array{0: User, 1: PortfolioProfile, 2: Holding}
     */
    protected function readyStrategyPosition(float $qty = 10, float $price = 100, float $targetAmount = 1000): array
    {
        [$user, $profile] = $this->actingReadyUser();
        $holding = $this->strategyHolding($profile, $this->stock(), $qty, $price, $targetAmount);

        return [$user, $profile, $holding];
    }

    protected function strategyHolding(
        PortfolioProfile $profile,
        Stock $stock,
        float $qty = 10,
        float $price = 100,
        float $targetAmount = 1000,
        bool $withBuy = true,
    ): Holding {
        $version = app(StrategyConfigurationService::class)->ensureActive($profile);
        $strategy = $version->strategy;
        $rec = TradingRecommendation::query()->create([
            'profile_id' => $profile->id,
            'security_id' => $stock->id,
            'strategy_version_id' => $version->id,
            'recommendation_type' => TradingRecommendation::ACTION_OPEN_POSITION,
            'status' => TradingRecommendation::STATUS_EXECUTED,
            'priority' => 1,
            'strategy_score' => 80,
            'confidence' => 0.8,
            'risk_level' => TradingRecommendation::RISK_MEDIUM,
            'generated_at' => now()->subDay(),
            'executed_at' => now()->subDay(),
        ]);
        if ($withBuy) {
            Transaction::query()->create([
                'profile_id' => $profile->id,
                'stock_id' => $stock->id,
                'type' => 'buy',
                'quantity' => $qty,
                'price' => $price,
                'fees' => 0,
                'transaction_date' => now()->subDay()->toDateString(),
                'source' => Transaction::SOURCE_RECOMMENDATION,
                'recommendation_id' => $rec->id,
            ]);
        }

        return Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'strategy_id' => $strategy->id,
            'owner_key' => Holding::ownerKeyFor((int) $strategy->id),
            'quantity' => $qty,
            'avg_buy_price' => $price,
            'invested_amount' => $qty * $price,
            'target_amount' => $targetAmount > 0 ? $targetAmount : null,
            'filled_amount' => $qty * $price,
            'updated_at' => now(),
        ]);
    }

    protected function place(Holding $holding, string $type, User $user)
    {
        return $this->postJson('/api/v1/protections', [
            'holding_id' => $holding->id,
            'type' => $type,
            'recovery_code' => $this->totpCode($user),
        ]);
    }

    /**
     * @return array{0: User, 1: PortfolioProfile}
     */
    protected function actingReadyUser(): array
    {
        $user = User::factory()->create();
        $user->forceFill(['automated_execution_entitled_at' => now()])->save();
        $profile = $this->defaultPortfolioFor($user);
        app(CashManagementService::class)->deposit($profile, 100_000, 'seed', $user);
        $this->connectKite($user);
        $this->actingAs($user)->withProfileHeader($user, $profile);
        $this->postJson('/api/v1/totp/begin')->assertOk();
        $otp = app(TotpService::class)->currentOtpForTests($user->fresh());
        $codes = $this->postJson('/api/v1/totp/confirm', ['code' => $otp])->assertOk()->json('data.recovery_codes');
        $this->recoveryCodes[$user->id] = $codes;

        return [$user->fresh(), $profile->fresh()];
    }

    protected function totpCode(User $user): string
    {
        $code = array_shift($this->recoveryCodes[$user->id]);
        $this->assertNotEmpty($code);

        return $code;
    }

    protected function setMode(User $user, PortfolioProfile $profile, string $mode, bool $confirm = false): void
    {
        $this->putJson('/api/v1/execution/mode', [
            'execution_mode' => $mode,
            'confirm_automatic' => $confirm,
            'recovery_code' => $this->totpCode($user),
        ])->assertOk();
    }

    protected function connectKite(User $user): void
    {
        $row = BrokerConnection::query()->firstOrNew([
            'user_id' => $user->id,
            'provider' => BrokerConnection::PROVIDER_KITE,
        ]);
        $row->forceFill([
            'access_token' => 'test-access-token',
            'connected_at' => now(),
            'expires_at' => now()->addDay(),
            'broker_user_id' => 'AB1234',
        ])->save();
    }

    protected function stock(): Stock
    {
        return Stock::query()->create([
            'symbol' => 'P'.strtoupper(Str::random(5)),
            'exchange' => 'NSE',
            'name' => 'Protection Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);
    }

    protected function pendingBuy(PortfolioProfile $profile, Stock $stock, float $amount = 500): TradingRecommendation
    {
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
