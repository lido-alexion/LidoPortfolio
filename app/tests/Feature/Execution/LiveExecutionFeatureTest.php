<?php

namespace Tests\Feature\Execution;

use App\Engines\Execution\ExecutionGate;
use App\Engines\Execution\LiveBrokerExecutionService;
use App\Models\BrokerConnection;
use App\Models\PortfolioProfile;
use App\Models\Stock;
use App\Models\TradingOrder;
use App\Models\TradingRecommendation;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Broker\BrokerOrderSnapshot;
use App\Services\Broker\FakeBrokerGateway;
use App\Services\CashManagementService;
use App\Services\Security\TotpService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class LiveExecutionFeatureTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, list<string>> */
    protected array $recoveryCodes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        Http::preventStrayRequests();
        app(FakeBrokerGateway::class)->reset();
    }

    public function test_new_portfolio_defaults_to_manual_and_manual_does_not_submit(): void
    {
        [$user, $profile] = $this->actingReadyUser();
        $this->assertSame(PortfolioProfile::EXECUTION_MODE_MANUAL, $profile->executionMode());

        $rec = $this->pendingBuy($profile);
        $this->postJson('/api/v1/execution/submit-selected', [
            'recommendation_ids' => [$rec->id],
            'recovery_code' => $this->totpCode($user),
        ])->assertStatus(403)->assertJsonPath('error.code', 'EXECUTION_MODE_MANUAL');

        $this->assertSame(0, app(FakeBrokerGateway::class)->placeCalls);
    }

    public function test_semi_automatic_requires_explicit_action_and_totp(): void
    {
        [$user, $profile] = $this->actingReadyUser();
        $this->setMode($user, $profile, PortfolioProfile::EXECUTION_MODE_SEMI_AUTOMATIC);
        $rec = $this->pendingBuy($profile);

        $this->postJson('/api/v1/execution/submit-selected', [
            'recommendation_ids' => [$rec->id],
        ])->assertStatus(403)->assertJsonPath('error.code', 'TOTP_REQUIRED');

        $this->assertSame(0, app(FakeBrokerGateway::class)->placeCalls);

        $submit = $this->postJson('/api/v1/execution/submit-selected', [
            'recommendation_ids' => [$rec->id],
            'recovery_code' => $this->totpCode($user),
        ])->assertOk();

        $this->assertSame('submitted', $submit->json('data.0.outcome'));
        $this->assertSame(1, app(FakeBrokerGateway::class)->placeCalls);
        $this->assertSame(TradingRecommendation::STATUS_PENDING_EXECUTION, $rec->fresh()->status);
        $order = TradingOrder::query()->where('recommendation_id', $rec->id)->first();
        $this->assertNotNull($order->broker_order_id);
        $this->assertNotSame(TradingOrder::BROKER_FILLED, $order->broker_status);
    }

    public function test_automatic_does_not_require_per_order_approval_or_totp_code(): void
    {
        [$user, $profile] = $this->actingReadyUser();
        $this->setMode($user, $profile, PortfolioProfile::EXECUTION_MODE_AUTOMATIC, confirm: true);
        $rec = $this->pendingReviewBuy($profile);

        $result = app(LiveBrokerExecutionService::class)->submitAutomaticForProfile($profile->fresh(['user']));
        $this->assertSame(1, $result['submitted']);
        $this->assertSame(1, app(FakeBrokerGateway::class)->placeCalls);
        $this->assertSame(TradingRecommendation::STATUS_PENDING_EXECUTION, $rec->fresh()->status);
    }

    public function test_entitlement_is_user_scoped_and_admin_only(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create();
        $this->defaultPortfolioFor($admin);
        $this->defaultPortfolioFor($member);

        $this->actingAs($member)->withProfileHeader($member)
            ->putJson("/api/v1/admin/users/{$member->id}/automated-execution-entitlement", ['entitled' => true])
            ->assertForbidden();

        $this->actingAs($admin)->withProfileHeader($admin)
            ->putJson("/api/v1/admin/users/{$member->id}/automated-execution-entitlement", ['entitled' => true])
            ->assertOk()
            ->assertJsonPath('data.automated_execution_entitled', true);

        $this->assertTrue($member->fresh()->automatedExecutionEntitled());
        $this->assertFalse($admin->fresh()->automatedExecutionEntitled());
    }

    public function test_manual_to_automatic_requires_confirmation_and_downgrade_is_safe(): void
    {
        [$user, $profile] = $this->actingReadyUser();
        $otp = $this->totpCode($user);

        $this->putJson('/api/v1/execution/mode', [
            'execution_mode' => 'automatic',
            'recovery_code' => $otp,
        ])->assertStatus(422)->assertJsonPath('error.code', 'EXECUTION_AUTOMATIC_CONFIRM_REQUIRED');

        $this->putJson('/api/v1/execution/mode', [
            'execution_mode' => 'automatic',
            'confirm_automatic' => true,
            'recovery_code' => $this->totpCode($user),
        ])->assertOk()->assertJsonPath('data.execution_mode', 'automatic');

        $rec = $this->pendingBuy($profile);
        app(LiveBrokerExecutionService::class)->submitAutomaticForProfile($profile->fresh(['user']));
        $order = TradingOrder::query()->where('recommendation_id', $rec->id)->first();
        $this->assertNotNull($order);

        $this->putJson('/api/v1/execution/mode', [
            'execution_mode' => 'manual',
        ])->assertOk()->assertJsonPath('data.execution_mode', 'manual');

        $this->assertSame($order->broker_order_id, $order->fresh()->broker_order_id);
        $this->assertNotSame(TradingOrder::STATUS_CANCELLED, $order->fresh()->status);
    }

    public function test_cross_user_portfolio_and_direct_api_cannot_bypass_gates(): void
    {
        [$user, $profile] = $this->actingReadyUser();
        $this->setMode($user, $profile, PortfolioProfile::EXECUTION_MODE_SEMI_AUTOMATIC);
        $rec = $this->pendingBuy($profile);

        $other = User::factory()->create();
        $other->forceFill(['automated_execution_entitled_at' => now()])->save();
        $this->defaultPortfolioFor($other);

        $response = $this->actingAs($other)->withHeader('X-Profile-Id', (string) $profile->id)
            ->postJson('/api/v1/execution/submit-selected', [
                'recommendation_ids' => [$rec->id],
                'totp' => '123456',
            ]);
        $this->assertContains($response->status(), [403, 404]);

        $this->assertSame(0, app(FakeBrokerGateway::class)->placeCalls);
    }

    public function test_manual_execution_does_not_require_totp(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        app(CashManagementService::class)->deposit($profile, 50_000, 'seed', $user);
        $stock = $this->stock();
        $rec = $this->pendingBuy($profile, $stock, 500);
        $this->actingAs($user)->withProfileHeader($user, $profile);

        $this->postJson('/api/transactions', [
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 5,
            'price' => 100,
            'fees' => 0,
            'transaction_date' => now()->toDateString(),
            'recommendation_id' => $rec->id,
        ])->assertCreated();

        $this->assertSame(TradingRecommendation::STATUS_EXECUTED, $rec->fresh()->status);
        $this->assertFalse($user->fresh()->totpIsActive());
    }

    public function test_buy_sell_fill_reject_cancel_partial_ambiguous_and_idempotent_reconcile(): void
    {
        [$user, $profile] = $this->actingReadyUser();
        $this->setMode($user, $profile, PortfolioProfile::EXECUTION_MODE_SEMI_AUTOMATIC);
        $live = app(LiveBrokerExecutionService::class);
        $fake = app(FakeBrokerGateway::class);

        $buy = $this->pendingBuy($profile);
        $this->postJson('/api/v1/execution/submit-selected', [
            'recommendation_ids' => [$buy->id],
            'recovery_code' => $this->totpCode($user),
        ])->assertOk();
        $buyOrder = TradingOrder::query()->where('recommendation_id', $buy->id)->first();
        $this->assertNotSame(TradingRecommendation::STATUS_EXECUTED, $buy->fresh()->status);

        $fake->seedSnapshot(new BrokerOrderSnapshot($buyOrder->broker_order_id, 'partial', 2, 3, 100, 'OPEN'));
        $live->reconcileOrder($profile, $buyOrder->fresh());
        $this->assertSame(TradingRecommendation::STATUS_PENDING_EXECUTION, $buy->fresh()->status);
        $this->assertSame(0, Transaction::query()->where('recommendation_id', $buy->id)->count());

        $fake->seedSnapshot(new BrokerOrderSnapshot($buyOrder->broker_order_id, 'filled', 5, 0, 101, 'COMPLETE'));
        $live->reconcileOrder($profile, $buyOrder->fresh());
        $this->assertSame(TradingRecommendation::STATUS_EXECUTED, $buy->fresh()->status);
        $this->assertSame(1, Transaction::query()->where('recommendation_id', $buy->id)->count());
        $live->reconcileOrder($profile, $buyOrder->fresh());
        $this->assertSame(1, Transaction::query()->where('recommendation_id', $buy->id)->count());

        $sell = $this->pendingSell($profile);
        $this->postJson('/api/v1/execution/submit-selected', [
            'recommendation_ids' => [$sell->id],
            'recovery_code' => $this->totpCode($user),
        ])->assertOk();
        $this->assertSame('sell', TradingOrder::query()->where('recommendation_id', $sell->id)->value('side'));

        $rejectRec = $this->pendingBuy($profile, $this->stock(), 400);
        $fake->nextPlaceRejected = true;
        $this->postJson('/api/v1/execution/submit-selected', [
            'recommendation_ids' => [$rejectRec->id],
            'recovery_code' => $this->totpCode($user),
        ])->assertOk();
        $this->assertSame(TradingOrder::BROKER_REJECTED, TradingOrder::query()->where('recommendation_id', $rejectRec->id)->value('broker_status'));
        $this->assertSame(TradingRecommendation::STATUS_PENDING_EXECUTION, $rejectRec->fresh()->status);

        $cancelRec = $this->pendingBuy($profile, $this->stock(), 300);
        $this->postJson('/api/v1/execution/submit-selected', [
            'recommendation_ids' => [$cancelRec->id],
            'recovery_code' => $this->totpCode($user),
        ])->assertOk();
        $cancelOrder = TradingOrder::query()->where('recommendation_id', $cancelRec->id)->first();
        $fake->seedSnapshot(new BrokerOrderSnapshot($cancelOrder->broker_order_id, 'cancelled', 0, 0, null, 'CANCELLED'));
        $live->reconcileOrder($profile, $cancelOrder->fresh());
        $this->assertSame(TradingOrder::STATUS_CANCELLED, $cancelOrder->fresh()->status);

        $ambiguous = $this->pendingBuy($profile, $this->stock(), 200);
        $before = $fake->placeCalls;
        $fake->nextPlaceAmbiguous = true;
        $this->postJson('/api/v1/execution/submit-selected', [
            'recommendation_ids' => [$ambiguous->id],
            'recovery_code' => $this->totpCode($user),
        ])->assertOk()->assertJsonPath('data.0.outcome', 'ambiguous');
        $this->postJson('/api/v1/execution/submit-selected', [
            'recommendation_ids' => [$ambiguous->id],
            'recovery_code' => $this->totpCode($user),
        ])->assertOk();
        $this->assertSame($before + 1, $fake->placeCalls);
    }

    public function test_target_seeking_fill_keeps_remaining_gap_open_and_resizes_next_order(): void
    {
        [$user, $profile] = $this->actingReadyUser();
        $this->setMode($user, $profile, PortfolioProfile::EXECUTION_MODE_SEMI_AUTOMATIC);
        $live = app(LiveBrokerExecutionService::class);
        $fake = app(FakeBrokerGateway::class);
        $rec = $this->pendingBuy($profile, amount: 500);
        $rec->forceFill([
            'target_amount' => 1_000,
            'capital_resolved_amount' => 1_000,
            'remaining_target_amount' => 1_000,
            'original_display_quantity' => 10,
            'external_executed_amount' => 0,
            'internal_executed_amount' => 0,
        ])->save();

        $first = $live->submitOne($user, $profile, $rec->id, ExecutionGate::TRIGGER_SEMI);
        $firstOrder = TradingOrder::query()->findOrFail($first['order_id']);
        $this->assertSame(10.0, (float) $firstOrder->quantity);
        $fake->seedSnapshot(new BrokerOrderSnapshot($firstOrder->broker_order_id, 'filled', 4, 0, 100, 'COMPLETE'));
        $live->reconcileOrder($profile, $firstOrder);

        $this->assertSame(TradingRecommendation::STATUS_PENDING_EXECUTION, $rec->fresh()->status);
        $this->assertSame(600.0, (float) $rec->fresh()->remaining_target_amount);
        $this->assertSame(400.0, (float) $rec->fresh()->external_executed_amount);

        $second = $live->submitOne($user, $profile, $rec->id, ExecutionGate::TRIGGER_SEMI);
        $this->assertSame(6.0, (float) TradingOrder::query()->findOrFail($second['order_id'])->quantity);
    }

    public function test_buy_retries_twice_with_successive_five_percent_quantity_reductions_only_for_margin_error(): void
    {
        [$user, $profile] = $this->actingReadyUser();
        $this->setMode($user, $profile, PortfolioProfile::EXECUTION_MODE_SEMI_AUTOMATIC);
        $rec = $this->pendingBuy($profile, amount: 10_000);
        $fake = app(FakeBrokerGateway::class);
        $fake->insufficientFundsFailuresRemaining = 2;

        $result = app(LiveBrokerExecutionService::class)->submitOne(
            $user,
            $profile,
            $rec->id,
            ExecutionGate::TRIGGER_SEMI,
        );

        $this->assertSame(3, $fake->placeCalls);
        $this->assertSame([100.0, 95.0, 90.0], array_map(fn ($request) => $request->quantity, $fake->placed));
        $this->assertSame(2, $result['insufficient_funds_retries']);
        $this->assertSame('submitted', $result['outcome']);
    }

    public function test_non_margin_rejection_is_never_quantity_retried(): void
    {
        [$user, $profile] = $this->actingReadyUser();
        $this->setMode($user, $profile, PortfolioProfile::EXECUTION_MODE_SEMI_AUTOMATIC);
        $rec = $this->pendingBuy($profile, amount: 1_000);
        $fake = app(FakeBrokerGateway::class);
        $fake->nextPlaceRejected = true;

        app(LiveBrokerExecutionService::class)->submitOne($user, $profile, $rec->id, ExecutionGate::TRIGGER_SEMI);

        $this->assertSame(1, $fake->placeCalls);
    }

    public function test_buy_quantity_is_bounded_by_current_shared_kite_funds(): void
    {
        [$user, $profile] = $this->actingReadyUser();
        $this->setMode($user, $profile, PortfolioProfile::EXECUTION_MODE_SEMI_AUTOMATIC);
        $rec = $this->pendingBuy($profile, amount: 1_000);
        $fake = app(FakeBrokerGateway::class);
        $fake->availableFunds = 450;

        app(LiveBrokerExecutionService::class)->submitOne($user, $profile, $rec->id, ExecutionGate::TRIGGER_SEMI);

        $this->assertSame(4.0, $fake->placed[0]->quantity);
    }

    public function test_mode_changes_cancel_or_reapprove_only_unsubmitted_feat_039_intent(): void
    {
        [$user, $profile] = $this->actingReadyUser();
        $this->setMode($user, $profile, PortfolioProfile::EXECUTION_MODE_AUTOMATIC, confirm: true);
        $needsApproval = $this->pendingBuy($profile);
        $needsApproval->forceFill(['execution_anchor_date' => now()->toDateString()])->save();

        $this->setMode($user, $profile, PortfolioProfile::EXECUTION_MODE_SEMI_AUTOMATIC);
        $this->assertSame(TradingRecommendation::STATUS_PENDING_REVIEW, $needsApproval->fresh()->status);
        $this->assertNull($needsApproval->fresh()->approved_at);

        $cancelled = $this->pendingBuy($profile);
        $cancelled->forceFill(['execution_anchor_date' => now()->toDateString()])->save();
        $this->setMode($user, $profile, PortfolioProfile::EXECUTION_MODE_MANUAL);
        $this->assertSame(TradingRecommendation::STATUS_CANCELLED, $cancelled->fresh()->status);
        $this->assertSame('mode_changed_to_manual', $cancelled->fresh()->cancellation_reason);
    }

    public function test_current_state_revalidation_cancels_unsubmitted_feat_039_intent_for_inactive_stock(): void
    {
        [$user, $profile] = $this->actingReadyUser();
        $this->setMode($user, $profile, PortfolioProfile::EXECUTION_MODE_SEMI_AUTOMATIC);
        $rec = $this->pendingBuy($profile);
        $rec->forceFill(['execution_anchor_date' => now()->toDateString()])->save();
        $rec->security()->update(['is_active' => false]);

        $this->postJson('/api/v1/execution/submit-selected', [
            'recommendation_ids' => [$rec->id],
            'recovery_code' => $this->totpCode($user),
        ])->assertOk();

        $this->assertSame(TradingRecommendation::STATUS_CANCELLED, $rec->fresh()->status);
        $this->assertSame('stock_inactive', $rec->fresh()->cancellation_reason);
        $this->assertSame(0, app(FakeBrokerGateway::class)->placeCalls);
    }

    public function test_execution_mode_is_portfolio_scoped(): void
    {
        [$user, $profile] = $this->actingReadyUser();
        $manualProfile = $this->createPortfolioProfile($user, 'Manual book', false);
        app(CashManagementService::class)->deposit($manualProfile, 50_000, 'seed', $user);

        $this->setMode($user, $profile, PortfolioProfile::EXECUTION_MODE_SEMI_AUTOMATIC);
        $this->assertSame(PortfolioProfile::EXECUTION_MODE_MANUAL, $manualProfile->fresh()->executionMode());

        $semiRec = $this->pendingBuy($profile);
        $manualRec = $this->pendingBuy($manualProfile);

        $this->withProfileHeader($user, $profile)
            ->postJson('/api/v1/execution/submit-selected', [
                'recommendation_ids' => [$semiRec->id],
                'recovery_code' => $this->totpCode($user),
            ])->assertOk();

        $this->withProfileHeader($user, $manualProfile)
            ->postJson('/api/v1/execution/submit-selected', [
                'recommendation_ids' => [$manualRec->id],
                'recovery_code' => $this->totpCode($user),
            ])->assertStatus(403)->assertJsonPath('error.code', 'EXECUTION_MODE_MANUAL');

        $this->assertSame(1, app(FakeBrokerGateway::class)->placeCalls);
        $this->assertNull(TradingOrder::query()->where('recommendation_id', $manualRec->id)->first());
    }

    public function test_entitlement_cannot_leak_to_another_user(): void
    {
        $this->actingReadyUser();
        $other = User::factory()->create();
        $otherProfile = $this->defaultPortfolioFor($other);
        $otherProfile->forceFill(['execution_mode' => PortfolioProfile::EXECUTION_MODE_SEMI_AUTOMATIC])->save();
        $this->connectKite($other);
        app(CashManagementService::class)->deposit($otherProfile, 50_000, 'seed', $other);
        $this->actingAs($other)->withProfileHeader($other, $otherProfile);
        $this->postJson('/api/v1/totp/begin')->assertOk();
        $otp = app(TotpService::class)->currentOtpForTests($other->fresh());
        $codes = $this->postJson('/api/v1/totp/confirm', ['code' => $otp])->assertOk()->json('data.recovery_codes');
        $rec = $this->pendingBuy($otherProfile);

        $this->postJson('/api/v1/execution/submit-selected', [
            'recommendation_ids' => [$rec->id],
            'recovery_code' => $codes[0],
        ])->assertStatus(403)->assertJsonPath('error.code', 'EXECUTION_NOT_ENTITLED');

        $this->assertSame(0, app(FakeBrokerGateway::class)->placeCalls);
    }

    public function test_in_flight_broker_order_blocks_manual_fill_and_manual_execute(): void
    {
        [$user, $profile] = $this->actingReadyUser();
        $this->setMode($user, $profile, PortfolioProfile::EXECUTION_MODE_SEMI_AUTOMATIC);
        $stock = $this->stock();
        $rec = $this->pendingBuy($profile, $stock, 500);
        $this->postJson('/api/v1/execution/submit-selected', [
            'recommendation_ids' => [$rec->id],
            'recovery_code' => $this->totpCode($user),
        ])->assertOk();
        $order = TradingOrder::query()->where('recommendation_id', $rec->id)->first();
        $this->assertNotNull($order->broker_order_id);

        $this->postJson('/api/transactions', [
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 5,
            'price' => 100,
            'fees' => 0,
            'transaction_date' => now()->toDateString(),
            'recommendation_id' => $rec->id,
        ])->assertStatus(422);

        $this->postJson('/api/v1/orders/'.$order->id.'/execute', [
            'price' => 100,
            'fees' => 0,
            'transaction_date' => now()->toDateString(),
        ])->assertStatus(422);
        $this->assertSame(TradingRecommendation::STATUS_PENDING_EXECUTION, $rec->fresh()->status);
    }

    public function test_reservation_is_not_double_consumed_on_idempotent_fill(): void
    {
        [$user, $profile] = $this->actingReadyUser();
        $this->setMode($user, $profile, PortfolioProfile::EXECUTION_MODE_SEMI_AUTOMATIC);
        $rec = $this->pendingBuy($profile);
        $rec->forceFill([
            'reservation_status' => TradingRecommendation::RESERVATION_RESERVED,
            'reserved_amount' => 500,
        ])->save();
        $cashBefore = app(CashManagementService::class)->balance($profile);

        $this->postJson('/api/v1/execution/submit-selected', [
            'recommendation_ids' => [$rec->id],
            'recovery_code' => $this->totpCode($user),
        ])->assertOk();
        $order = TradingOrder::query()->where('recommendation_id', $rec->id)->first();
        $fake = app(FakeBrokerGateway::class);
        $live = app(LiveBrokerExecutionService::class);

        $fake->seedSnapshot(new BrokerOrderSnapshot($order->broker_order_id, 'filled', 5, 0, 100, 'COMPLETE'));
        $live->reconcileOrder($profile, $order->fresh());
        $this->assertSame(TradingRecommendation::STATUS_EXECUTED, $rec->fresh()->status);
        $this->assertSame(0.0, (float) $rec->fresh()->reserved_amount);
        $this->assertSame(1, Transaction::query()->where('recommendation_id', $rec->id)->count());
        $cashAfter = app(CashManagementService::class)->balance($profile);

        $live->reconcileOrder($profile, $order->fresh());
        $this->assertSame(1, Transaction::query()->where('recommendation_id', $rec->id)->count());
        $this->assertSame($cashAfter, app(CashManagementService::class)->balance($profile));
        $this->assertLessThan($cashBefore, $cashAfter);
    }

    public function test_server_blocks_submit_without_totp_enrollment(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['automated_execution_entitled_at' => now()])->save();
        $profile = $this->defaultPortfolioFor($user);
        $profile->forceFill(['execution_mode' => PortfolioProfile::EXECUTION_MODE_SEMI_AUTOMATIC])->save();
        $this->connectKite($user);
        $this->actingAs($user)->withProfileHeader($user, $profile);
        $rec = $this->pendingBuy($profile);

        $this->postJson('/api/v1/execution/submit-selected', [
            'recommendation_ids' => [$rec->id],
            'totp' => '123456',
        ])->assertStatus(403)->assertJsonPath('error.code', 'TOTP_REQUIRED');
    }

    /**
     * @return array{0: User, 1: PortfolioProfile}
     */
    protected function actingReadyUser(): array
    {
        $user = User::factory()->create();
        $user->forceFill(['automated_execution_entitled_at' => now()])->save();
        $profile = $this->defaultPortfolioFor($user);
        $this->assertSame(PortfolioProfile::EXECUTION_MODE_MANUAL, $profile->fresh()->executionMode());
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
        $this->assertSame($mode, $profile->fresh()->executionMode());
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
            'symbol' => 'T'.strtoupper(Str::random(5)),
            'exchange' => 'NSE',
            'name' => 'Test Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);
    }

    protected function pendingBuy(?PortfolioProfile $profile = null, ?Stock $stock = null, float $amount = 500): TradingRecommendation
    {
        $stock ??= $this->stock();

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

    protected function pendingReviewBuy(PortfolioProfile $profile): TradingRecommendation
    {
        $rec = $this->pendingBuy($profile);
        $rec->forceFill(['status' => TradingRecommendation::STATUS_PENDING_REVIEW, 'approved_at' => null])->save();

        return $rec->fresh();
    }

    protected function pendingSell(PortfolioProfile $profile): TradingRecommendation
    {
        $stock = $this->stock();

        return TradingRecommendation::query()->create([
            'profile_id' => $profile->id,
            'security_id' => $stock->id,
            'recommendation_type' => 'EXIT_POSITION',
            'status' => TradingRecommendation::STATUS_PENDING_EXECUTION,
            'priority' => 1,
            'strategy_score' => 80,
            'confidence' => 0.8,
            'risk_level' => 'medium',
            'suggested_allocation_amount' => 500,
            'reference_price' => 100,
            'execution_plan' => [
                'suggested_quantity' => 5,
                'suggested_investment_amount' => 500,
                'side' => 'sell',
            ],
            'approved_at' => now(),
            'generated_at' => now(),
            'reservation_status' => TradingRecommendation::RESERVATION_NONE,
            'reserved_amount' => 0,
        ]);
    }
}
