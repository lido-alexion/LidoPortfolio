<?php

namespace Tests\Feature\Execution;

use App\Engines\Execution\ExecutionGate;
use App\Engines\Execution\LiveBrokerExecutionService;
use App\Models\BrokerConnection;
use App\Models\PortfolioProfile;
use App\Models\PortfolioReconciliationRun;
use App\Models\Setting;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\TradingOrder;
use App\Models\TradingRecommendation;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Broker\BrokerOrderSnapshot;
use App\Services\Broker\FakeBrokerGateway;
use App\Services\CashManagementService;
use App\Services\Security\TotpService;
use Carbon\Carbon;
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
        Carbon::setTestNow(Carbon::parse('2026-09-11 08:00:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
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

    public function test_mode_changes_are_blocked_during_market_safety_window(): void
    {
        [$user, $profile] = $this->actingReadyUser();
        Setting::setValue('cron_timezone', 'Asia/Kolkata');
        Setting::setValue('market_open_time', '09:15');
        Setting::setValue('market_close_time', '15:30');
        Carbon::setTestNow(Carbon::parse('2026-09-11 09:15:00', 'Asia/Kolkata'));
        try {
            $this->putJson('/api/v1/execution/mode', [
                'execution_mode' => PortfolioProfile::EXECUTION_MODE_SEMI_AUTOMATIC,
                'recovery_code' => $this->totpCode($user),
            ])->assertStatus(422)->assertJsonPath('error.code', 'EXECUTION_MODE_CHANGE_BLACKOUT');
            $this->assertSame(PortfolioProfile::EXECUTION_MODE_MANUAL, $profile->fresh()->executionMode());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_only_one_portfolio_per_investor_can_use_live_execution_mode(): void
    {
        [$user, $first] = $this->actingReadyUser();
        $this->setMode($user, $first, PortfolioProfile::EXECUTION_MODE_SEMI_AUTOMATIC);
        $second = PortfolioProfile::query()->create([
            'user_id' => $user->id, 'name' => 'Second Portfolio', 'is_default' => false,
            'portfolio_type' => PortfolioProfile::TYPE_LIVE,
        ]);
        $this->withProfileHeader($user, $second)->putJson('/api/v1/execution/mode', [
            'execution_mode' => PortfolioProfile::EXECUTION_MODE_AUTOMATIC,
            'confirm_automatic' => true,
            'recovery_code' => $this->totpCode($user),
        ])->assertStatus(422)->assertJsonPath('error.code', 'EXECUTION_MODE_ACCOUNT_CONFLICT');
        $this->assertSame(PortfolioProfile::EXECUTION_MODE_MANUAL, $second->fresh()->executionMode());
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

        $this->assertSame(1, $submit->json('data.submitted'));
        $this->assertSame('submitted', $submit->json('data.results.0.outcome'));
        $this->assertSame(1, app(FakeBrokerGateway::class)->placeCalls);
        $this->assertSame(TradingRecommendation::STATUS_PENDING_EXECUTION, $rec->fresh()->status);
        $order = TradingOrder::query()->where('recommendation_id', $rec->id)->first();
        $this->assertNotNull($order->broker_order_id);
        $this->assertNotSame(TradingOrder::BROKER_FILLED, $order->broker_status);
        $this->assertCount(6, $user->fresh()->totp_recovery_codes);
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

        $fake->reconciliationSnapshot = [
            'provider' => 'kite', 'captured_at' => now()->toISOString(),
            'holdings' => [[
                'symbol' => $buy->security->symbol, 'exchange' => 'NSE', 'quantity' => 5,
            ]],
            'positions' => [], 'current_cash' => 0,
        ];
        $fake->seedSnapshot(new BrokerOrderSnapshot($buyOrder->broker_order_id, 'filled', 5, 0, 101, 'COMPLETE'));
        $live->reconcileOrder($profile, $buyOrder->fresh());
        $this->assertSame(TradingRecommendation::STATUS_EXECUTED, $buy->fresh()->status);
        $this->assertSame(1, Transaction::query()->where('recommendation_id', $buy->id)->count());
        $this->assertSame(1, PortfolioReconciliationRun::query()->where('profile_id', $profile->id)->where('trigger', 'post_trade')->count());
        $live->reconcileOrder($profile, $buyOrder->fresh());
        $this->assertSame(1, Transaction::query()->where('recommendation_id', $buy->id)->count());
        $this->assertSame(1, PortfolioReconciliationRun::query()->where('profile_id', $profile->id)->where('trigger', 'post_trade')->count());

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
        ])->assertOk()->assertJsonPath('data.results.0.outcome', 'ambiguous');
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
        // The fake snapshot omits the newly filled holding; keep this test focused on residual sizing.
        $profile->fresh()->forceFill(['execution_blocked_by_reconciliation' => false])->save();

        $this->assertSame(TradingRecommendation::STATUS_PENDING_EXECUTION, $rec->fresh()->status);
        $this->assertSame(600.0, (float) $rec->fresh()->remaining_target_amount);
        $this->assertSame(400.0, (float) $rec->fresh()->external_executed_amount);

        $second = $live->submitOne($user, $profile, $rec->id, ExecutionGate::TRIGGER_SEMI);
        $this->assertArrayHasKey('order_id', $second, json_encode($second));
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

    public function test_final_gate_blocks_halt_activated_after_semi_automatic_admission(): void
    {
        [$user, $profile] = $this->actingReadyUser();
        $this->setMode($user, $profile, PortfolioProfile::EXECUTION_MODE_SEMI_AUTOMATIC);
        $rec = $this->pendingBuy($profile);
        $fake = app(FakeBrokerGateway::class);
        $fake->beforeAvailableEquityFunds = function () use ($user): void {
            $user->fresh()->forceFill(['execution_state' => User::EXECUTION_STATE_EMERGENCY_HALT])->save();
        };

        $this->postJson('/api/v1/execution/submit-selected', [
            'recommendation_ids' => [$rec->id],
            'recovery_code' => $this->totpCode($user),
        ])->assertOk()
            ->assertJsonPath('data.results.0.outcome', 'blocked')
            ->assertJsonPath('data.results.0.reason', 'EXECUTION_EMERGENCY_HALT');

        $this->assertSame(0, $fake->placeCalls);
        $this->assertSame(TradingRecommendation::STATUS_PENDING_EXECUTION, $rec->fresh()->status);
        $this->assertDatabaseMissing('portfolio_tos_orders', ['recommendation_id' => $rec->id]);
    }

    public function test_final_gate_blocks_automatic_submission_after_entitlement_revocation(): void
    {
        [$user, $profile] = $this->actingReadyUser();
        $this->setMode($user, $profile, PortfolioProfile::EXECUTION_MODE_AUTOMATIC, confirm: true);
        $rec = $this->pendingBuy($profile);
        $fake = app(FakeBrokerGateway::class);
        $fake->beforeAvailableEquityFunds = function () use ($user): void {
            $user->fresh()->forceFill(['automated_execution_entitled_at' => null])->save();
        };

        $result = app(LiveBrokerExecutionService::class)->submitAutomaticForProfile($profile->fresh(['user']));

        $this->assertSame(0, $fake->placeCalls);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame('EXECUTION_NOT_ENTITLED', $result['results'][0]['reason']);
        $this->assertDatabaseMissing('portfolio_tos_orders', ['recommendation_id' => $rec->id]);
    }

    public function test_final_gate_blocks_submission_after_reconciliation_becomes_blocking(): void
    {
        [$user, $profile] = $this->actingReadyUser();
        $this->setMode($user, $profile, PortfolioProfile::EXECUTION_MODE_AUTOMATIC, confirm: true);
        $rec = $this->pendingBuy($profile);
        $fake = app(FakeBrokerGateway::class);
        $fake->beforeAvailableEquityFunds = function () use ($profile): void {
            $profile->fresh()->forceFill(['execution_blocked_by_reconciliation' => true])->save();
        };

        $result = app(LiveBrokerExecutionService::class)->submitAutomaticForProfile($profile->fresh(['user']));

        $this->assertSame(0, $fake->placeCalls);
        $this->assertSame('RECONCILIATION_HOLDINGS_MISMATCH', $result['results'][0]['reason']);
        $this->assertDatabaseMissing('portfolio_tos_orders', ['recommendation_id' => $rec->id]);
    }

    public function test_final_gate_blocks_submission_after_broker_connection_is_removed(): void
    {
        [$user, $profile] = $this->actingReadyUser();
        $this->setMode($user, $profile, PortfolioProfile::EXECUTION_MODE_AUTOMATIC, confirm: true);
        $rec = $this->pendingBuy($profile);
        $fake = app(FakeBrokerGateway::class);
        $fake->beforeAvailableEquityFunds = function () use ($user): void {
            BrokerConnection::query()->where('user_id', $user->id)->delete();
        };

        $result = app(LiveBrokerExecutionService::class)->submitAutomaticForProfile($profile->fresh(['user']));

        $this->assertSame(0, $fake->placeCalls);
        $this->assertSame('BROKER_NOT_CONNECTED', $result['results'][0]['reason']);
        $this->assertDatabaseMissing('portfolio_tos_orders', ['recommendation_id' => $rec->id]);
    }

    public function test_final_gate_rechecks_execution_window_after_preparation(): void
    {
        [$user, $profile] = $this->actingReadyUser();
        $this->setMode($user, $profile, PortfolioProfile::EXECUTION_MODE_AUTOMATIC, confirm: true);
        $rec = $this->pendingBuy($profile);
        $rec->forceFill([
            'execution_anchor_date' => '2026-09-11',
            'first_eligible_execution_date' => '2026-09-11',
            'second_eligible_execution_date' => '2026-09-11',
            'execution_expires_at' => Carbon::parse('2026-09-11 15:30:00', 'Asia/Kolkata'),
        ])->save();
        Carbon::setTestNow(Carbon::parse('2026-09-11 09:16:00', 'Asia/Kolkata'));
        $fake = app(FakeBrokerGateway::class);
        $fake->beforeAvailableEquityFunds = function (): void {
            Carbon::setTestNow(Carbon::parse('2026-09-11 15:30:00', 'Asia/Kolkata'));
        };

        $result = app(LiveBrokerExecutionService::class)->submitAutomaticForProfile($profile->fresh(['user']));

        $this->assertSame(0, $fake->placeCalls);
        $this->assertSame('outside_execution_opportunity', $result['results'][0]['reason']);
        $this->assertSame(TradingRecommendation::STATUS_PENDING_EXECUTION, $rec->fresh()->status);
    }

    public function test_margin_retry_rechecks_final_gate_before_a_second_broker_attempt(): void
    {
        [$user, $profile] = $this->actingReadyUser();
        $this->setMode($user, $profile, PortfolioProfile::EXECUTION_MODE_SEMI_AUTOMATIC);
        $rec = $this->pendingBuy($profile, amount: 10_000);
        $fake = app(FakeBrokerGateway::class);
        $fake->insufficientFundsFailuresRemaining = 1;
        $fake->afterPlaceOrder = function () use ($user): void {
            $user->fresh()->forceFill(['execution_state' => User::EXECUTION_STATE_EMERGENCY_HALT])->save();
        };

        $result = app(LiveBrokerExecutionService::class)->submitOne(
            $user,
            $profile,
            $rec->id,
            ExecutionGate::TRIGGER_SEMI,
        );

        $this->assertSame(1, $fake->placeCalls);
        $this->assertSame('blocked', $result['outcome']);
        $this->assertSame('EXECUTION_EMERGENCY_HALT', $result['reason']);
    }

    public function test_final_gate_keeps_successful_automatic_batch_order_when_later_order_is_halted(): void
    {
        [$user, $profile] = $this->actingReadyUser();
        $this->setMode($user, $profile, PortfolioProfile::EXECUTION_MODE_AUTOMATIC, confirm: true);
        $first = $this->pendingBuy($profile, amount: 1_000);
        $second = $this->pendingBuy($profile, amount: 2_000);
        $fake = app(FakeBrokerGateway::class);
        $fake->afterPlaceOrder = function () use ($user): void {
            $user->fresh()->forceFill(['execution_state' => User::EXECUTION_STATE_EMERGENCY_HALT])->save();
        };

        $result = app(LiveBrokerExecutionService::class)->submitAutomaticForProfile($profile->fresh(['user']));

        $this->assertSame(1, $fake->placeCalls);
        $this->assertSame(1, $result['submitted']);
        $this->assertSame(1, $result['skipped']);
        $this->assertDatabaseHas('portfolio_tos_orders', ['recommendation_id' => $first->id]);
        $this->assertDatabaseMissing('portfolio_tos_orders', ['recommendation_id' => $second->id]);
    }

    public function test_final_state_revalidation_blocks_stock_invalidated_during_preparation(): void
    {
        [$user, $profile] = $this->actingReadyUser();
        $this->setMode($user, $profile, PortfolioProfile::EXECUTION_MODE_AUTOMATIC, confirm: true);
        $stock = $this->stock();
        $rec = $this->pendingBuy($profile, $stock);
        $rec->forceFill(['execution_anchor_date' => now()->toDateString()])->save();
        $fake = app(FakeBrokerGateway::class);
        $fake->beforeAvailableEquityFunds = function () use ($stock): void {
            $stock->fresh()->forceFill(['is_active' => false])->save();
        };

        $result = app(LiveBrokerExecutionService::class)->submitAutomaticForProfile($profile->fresh(['user']));

        $this->assertSame(0, $fake->placeCalls);
        $this->assertSame('stock_inactive', $result['results'][0]['reason']);
        $this->assertSame(TradingRecommendation::STATUS_CANCELLED, $rec->fresh()->status);
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

    public function test_emergency_halt_blocks_all_new_broker_submissions_until_recovered(): void
    {
        [$user, $profile] = $this->actingReadyUser();
        $this->setMode($user, $profile, PortfolioProfile::EXECUTION_MODE_SEMI_AUTOMATIC);
        $rec = $this->pendingBuy($profile);

        $this->postJson('/api/v1/execution/halt', ['reason' => 'phone lost'])
            ->assertOk()
            ->assertJsonPath('data.execution_state', User::EXECUTION_STATE_EMERGENCY_HALT);

        $this->postJson('/api/v1/execution/submit-selected', [
            'recommendation_ids' => [$rec->id],
            'recovery_code' => $this->totpCode($user),
        ])->assertStatus(423)->assertJsonPath('error.code', 'EXECUTION_EMERGENCY_HALT');
        $this->assertSame(0, app(FakeBrokerGateway::class)->placeCalls);

        $this->postJson('/api/v1/execution/recover', [
            'confirm' => true,
            'recovery_code' => $this->totpCode($user),
        ])->assertOk()->assertJsonPath('data.execution_state', User::EXECUTION_STATE_NORMAL);

        $this->postJson('/api/v1/execution/submit-selected', [
            'recommendation_ids' => [$rec->id],
            'recovery_code' => $this->totpCode($user),
        ])->assertOk()->assertJsonPath('data.results.0.outcome', 'submitted');
        $this->assertSame(1, app(FakeBrokerGateway::class)->placeCalls);
    }

    public function test_kite_emergency_disconnect_halts_and_destroys_local_credential(): void
    {
        [$user] = $this->actingReadyUser();

        $this->postJson('/api/v1/broker/kite/emergency-disconnect', ['reason' => 'suspected compromise'])
            ->assertOk()
            ->assertJsonPath('data.execution_state', User::EXECUTION_STATE_EMERGENCY_HALT)
            ->assertJsonPath('data.broker.connected', false);

        $this->assertDatabaseMissing('portfolio_broker_connections', ['user_id' => $user->id]);
        $this->assertDatabaseHas('portfolio_execution_safety_events', [
            'user_id' => $user->id,
            'event' => 'execution.kite_disconnected',
            'status' => 'completed',
        ]);
    }

    public function test_emergency_cancel_open_orders_disconnect_cancels_only_primary_orders(): void
    {
        [$user, $profile] = $this->actingReadyUser();
        $this->setMode($user, $profile, PortfolioProfile::EXECUTION_MODE_SEMI_AUTOMATIC);
        $rec = $this->pendingBuy($profile);
        $this->postJson('/api/v1/execution/submit-selected', [
            'recommendation_ids' => [$rec->id],
            'recovery_code' => $this->totpCode($user),
        ])->assertOk();
        $primary = TradingOrder::query()->where('recommendation_id', $rec->id)->firstOrFail();
        $protective = TradingOrder::query()->create([
            'profile_id' => $profile->id,
            'security_id' => $primary->security_id,
            'side' => 'sell',
            'quantity' => 1,
            'order_type' => 'gtt_protection',
            'status' => TradingOrder::STATUS_PENDING,
            'broker_provider' => 'kite',
            'broker_order_id' => 'protective-1',
            'broker_status' => TradingOrder::BROKER_OPEN,
            'filled_quantity' => 0,
        ]);

        $this->postJson('/api/v1/broker/kite/emergency-cancel-open-orders-disconnect', [
            'confirm' => true,
            'reason' => 'panic button',
        ])->assertOk()
            ->assertJsonPath('data.cancelled_orders', 1)
            ->assertJsonPath('data.broker.connected', false);

        $this->assertSame(TradingOrder::BROKER_CANCELLED, $primary->fresh()->broker_status);
        $this->assertSame(TradingOrder::BROKER_OPEN, $protective->fresh()->broker_status);
        $this->assertSame(User::EXECUTION_STATE_EMERGENCY_HALT, $user->fresh()->executionState());
    }

    public function test_live_quote_strict_policy_blocks_and_fallback_policy_uses_latest_close(): void
    {
        [$user, $profile] = $this->actingReadyUser();
        $this->setMode($user, $profile, PortfolioProfile::EXECUTION_MODE_SEMI_AUTOMATIC);
        $stock = $this->stock();
        $rec = $this->pendingBuy($profile, $stock, 1_000);
        $rec->forceFill([
            'target_amount' => 1_000,
            'capital_resolved_amount' => 1_000,
            'remaining_target_amount' => 1_000,
            'external_executed_amount' => 0,
        ])->save();
        app(FakeBrokerGateway::class)->liveQuote = null;

        $result = app(LiveBrokerExecutionService::class)->submitOne($user, $profile, $rec->id, ExecutionGate::TRIGGER_SEMI);
        $this->assertSame('live_quote_unavailable', $result['reason']);
        $this->assertSame(0, app(FakeBrokerGateway::class)->placeCalls);

        StockPrice::query()->create([
            'stock_id' => $stock->id,
            'price_date' => now()->toDateString(),
            'close_price' => 125,
            'open_price' => 125,
            'high_price' => 125,
            'low_price' => 125,
            'volume' => 1000,
            'data_source' => 'test',
        ]);
        $this->putJson('/api/v1/execution/quote-policy', [
            'live_quote_policy' => User::LIVE_QUOTE_POLICY_ALLOW_CLOSE_FALLBACK,
        ])->assertOk();

        $result = app(LiveBrokerExecutionService::class)->submitOne($user->fresh(), $profile, $rec->id, ExecutionGate::TRIGGER_SEMI);
        $order = TradingOrder::query()->findOrFail($result['order_id']);
        $this->assertSame(8.0, (float) $order->quantity);
        $this->assertSame(125.0, (float) $order->limit_price);
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
