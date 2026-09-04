<?php

namespace Tests\Feature;

use App\Engines\Execution\LiveBrokerExecutionService;
use App\Engines\Pipeline\DailyDecisionPipeline;
use App\Models\BrokerConnection;
use App\Models\InternalExecutionTransfer;
use App\Models\OperationalAlert;
use App\Models\PortfolioProfile;
use App\Models\Setting;
use App\Models\Stock;
use App\Models\SyncRun;
use App\Models\TradingOrder;
use App\Models\TradingRecommendation;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AdminOperationalAlertService;
use App\Services\Broker\BrokerOrderSnapshot;
use App\Services\Broker\FakeBrokerGateway;
use App\Services\CashManagementService;
use App\Services\DecisionPipelineScheduleService;
use App\Services\ProfileSettingsService;
use App\Services\Security\TotpService;
use App\Services\SyncLogService;
use App\Services\TelegramNotificationService;
use App\Services\TransactionWriteService;
use App\Support\TradingOsConfig;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * V4-FEAT-010 — unattended production pipeline + broker jobs via schedule:run.
 */
class V4Feat010UnattendedOpsTest extends TestCase
{
    use RefreshDatabase;

    /** @var array{pipeline:int,reconcile:int,submit:int,all:int} */
    protected array $telegramCounts = ['pipeline' => 0, 'reconcile' => 0, 'submit' => 0, 'all' => 0];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        Http::preventStrayRequests();
        app(FakeBrokerGateway::class)->reset();
        Carbon::setTestNow(Carbon::parse('2026-08-07 19:05:00', 'Asia/Kolkata'));
        config([
            TradingOsConfig::KEY_ENABLED => true,
            'portfolio.universe_price_sync.enabled' => false,
        ]);
    }

    protected function tearDown(): void
    {
        Cache::lock(DecisionPipelineScheduleService::AUTOMATIC_LOCK_KEY)->forceRelease();
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    public function test_laravel_schedule_registers_unattended_pipeline_reconcile_and_submit(): void
    {
        /** @var Schedule $schedule */
        $schedule = app(Schedule::class);
        $names = [];
        foreach ($schedule->events() as $event) {
            $command = (string) $event->command;
            if (str_contains($command, 'portfolio:decision-pipeline') && str_contains($command, 'scheduled')) {
                $names['pipeline'] = $event;
            }
            if (str_contains($command, 'tos:reconcile-broker-orders')) {
                $names['reconcile'] = $event;
            }
            if (str_contains($command, 'tos:submit-automatic-orders')) {
                $names['submit'] = $event;
            }
        }

        $this->assertArrayHasKey('pipeline', $names);
        $this->assertTrue($names['pipeline']->withoutOverlapping);
        $this->assertArrayHasKey('reconcile', $names);
        $this->assertTrue($names['reconcile']->withoutOverlapping);
        $this->assertArrayHasKey('submit', $names);
        $this->assertTrue($names['submit']->withoutOverlapping);
    }

    public function test_duplicate_scheduled_invocation_does_not_run_pipeline_twice(): void
    {
        $profiles = $this->seedPlainProfiles(1);
        $runs = 0;
        $this->mock(DailyDecisionPipeline::class, function ($mock) use (&$runs): void {
            $mock->shouldReceive('run')
                ->once()
                ->andReturnUsing(function () use (&$runs) {
                    $runs++;

                    return [
                        'pipeline_run' => (object) ['id' => 1],
                        'stages' => ['discovery' => ['candidates' => 0]],
                    ];
                });
        });

        $this->artisan('portfolio:decision-pipeline', ['--trigger' => 'scheduled'])->assertSuccessful();
        $this->artisan('portfolio:decision-pipeline', ['--trigger' => 'scheduled'])
            ->assertSuccessful()
            ->expectsOutputToContain('already completed automatically today');

        $this->assertSame(1, $runs);
        $this->assertTrue(app(DecisionPipelineScheduleService::class)->hasAutomaticRunToday());
        unset($profiles);
    }

    public function test_overlapping_automatic_pipeline_is_skipped_without_a_second_run(): void
    {
        $this->seedPlainProfiles(1);
        $held = Cache::lock(DecisionPipelineScheduleService::AUTOMATIC_LOCK_KEY, 60);
        $this->assertTrue($held->get());

        $this->mock(DailyDecisionPipeline::class, function ($mock): void {
            $mock->shouldReceive('run')->never();
        });

        $this->artisan('portfolio:decision-pipeline', ['--trigger' => 'scheduled'])
            ->assertSuccessful()
            ->expectsOutputToContain('another automatic execution is in progress');

        $held->release();
        $this->assertNull(OperationalAlert::query()->find(AdminOperationalAlertService::KEY_DECISION_PIPELINE_FAILED));
    }

    public function test_transient_pipeline_failure_is_retried_on_the_next_scheduled_run(): void
    {
        $profiles = $this->seedPlainProfiles(1);
        $attempts = 0;
        $this->mock(DailyDecisionPipeline::class, function ($mock) use (&$attempts): void {
            $mock->shouldReceive('run')
                ->twice()
                ->andReturnUsing(function () use (&$attempts) {
                    $attempts++;
                    if ($attempts === 1) {
                        throw new RuntimeException('transient pipeline outage');
                    }

                    return [
                        'pipeline_run' => (object) ['id' => 2],
                        'stages' => ['discovery' => ['candidates' => 0]],
                    ];
                });
        });

        $this->seedHealthySyncRuns();
        $this->bindCountingTelegram();

        $this->artisan('portfolio:decision-pipeline', ['--trigger' => 'scheduled'])->assertFailed();
        $this->assertFalse(app(DecisionPipelineScheduleService::class)->hasAutomaticRunToday());

        $this->artisan('portfolio:decision-pipeline', ['--trigger' => 'scheduled'])->assertSuccessful();
        $this->assertSame(2, $attempts);
        $this->assertTrue(app(DecisionPipelineScheduleService::class)->hasAutomaticRunToday());
        unset($profiles);
    }

    public function test_persistent_pipeline_failure_creates_in_app_alert_and_telegram_without_spam(): void
    {
        $this->seedPlainProfiles(1);
        $this->seedHealthySyncRuns();
        $admin = $this->seedAdminTelegramRecipient();

        $this->mock(DailyDecisionPipeline::class, function ($mock): void {
            $mock->shouldReceive('run')->andThrow(new RuntimeException('persistent pipeline outage'));
        });

        $this->bindCountingTelegram();

        $this->artisan('portfolio:decision-pipeline', ['--trigger' => 'scheduled'])->assertFailed();
        $this->artisan('portfolio:decision-pipeline', ['--trigger' => 'scheduled'])->assertFailed();

        $row = OperationalAlert::query()->find(AdminOperationalAlertService::KEY_DECISION_PIPELINE_FAILED);
        $this->assertNotNull($row);
        $this->assertNull($row->resolved_at);
        $this->assertStringContainsString('persistent pipeline outage', (string) $row->message);
        $this->assertSame(1, $this->telegramCounts['pipeline']);
        $this->assertGreaterThan(0, $admin->id);
    }

    public function test_scheduled_automatic_submit_places_without_human_action_and_is_idempotent(): void
    {
        [$user, $profile] = $this->actingReadyUser();
        $this->setMode($user, $profile, PortfolioProfile::EXECUTION_MODE_AUTOMATIC, confirm: true);
        $rec = $this->pendingReviewBuy($profile);

        $this->artisan('tos:submit-automatic-orders')->assertSuccessful();
        $this->assertSame(1, app(FakeBrokerGateway::class)->placeCalls);
        $this->assertSame(TradingRecommendation::STATUS_PENDING_EXECUTION, $rec->fresh()->status);

        $this->artisan('tos:submit-automatic-orders')->assertSuccessful();
        $this->assertSame(1, app(FakeBrokerGateway::class)->placeCalls);
        $this->assertSame(1, TradingOrder::query()->where('recommendation_id', $rec->id)->count());
    }

    public function test_scheduled_submit_does_not_place_for_manual_or_semi_automatic(): void
    {
        [$user, $automatic] = $this->actingReadyUser();
        $this->setMode($user, $automatic, PortfolioProfile::EXECUTION_MODE_AUTOMATIC, confirm: true);
        $this->pendingReviewBuy($automatic);

        $semi = $this->createPortfolioProfile($user, 'Semi book', false);
        app(CashManagementService::class)->deposit($semi, 50_000, 'seed', $user);
        $this->actingAs($user)->withProfileHeader($user, $semi);
        $this->setMode($user, $semi, PortfolioProfile::EXECUTION_MODE_SEMI_AUTOMATIC);
        $this->pendingBuy($semi);

        $manual = $this->createPortfolioProfile($user, 'Manual book', false);
        app(CashManagementService::class)->deposit($manual, 50_000, 'seed', $user);
        $this->pendingBuy($manual);

        $this->artisan('tos:submit-automatic-orders')->assertSuccessful();
        $this->assertSame(1, app(FakeBrokerGateway::class)->placeCalls);
        $this->assertSame(0, TradingOrder::query()->where('profile_id', $semi->id)->count());
        $this->assertSame(0, TradingOrder::query()->where('profile_id', $manual->id)->count());
    }

    public function test_account_cycle_submits_sells_before_buys_across_automatic_portfolios(): void
    {
        [$user, $buyProfile] = $this->actingReadyUser();
        $this->setMode($user, $buyProfile, PortfolioProfile::EXECUTION_MODE_AUTOMATIC, confirm: true);
        $buy = $this->pendingBuy($buyProfile);

        $sellProfile = $this->createPortfolioProfile($user, 'Sell book', false);
        app(CashManagementService::class)->deposit($sellProfile, 50_000, 'seed', $user);
        $this->withProfileHeader($user, $sellProfile);
        $this->setMode($user, $sellProfile, PortfolioProfile::EXECUTION_MODE_AUTOMATIC, confirm: true);
        $sell = $this->pendingBuy($sellProfile);
        $sell->forceFill([
            'recommendation_type' => TradingRecommendation::ACTION_EXIT_POSITION,
            'execution_plan' => ['suggested_quantity' => 2, 'suggested_investment_amount' => 200, 'side' => 'sell'],
        ])->save();

        $this->artisan('tos:submit-automatic-orders')->assertSuccessful();

        $placed = app(FakeBrokerGateway::class)->placed;
        $this->assertCount(2, $placed);
        $this->assertSame($sell->id, $placed[0]->recommendationId);
        $this->assertSame('sell', $placed[0]->side);
        $this->assertSame($buy->id, $placed[1]->recommendationId);
        $this->assertSame('buy', $placed[1]->side);
        $this->assertDatabaseHas('portfolio_execution_batches', [
            'user_id' => $user->id,
            'status' => 'completed',
        ]);
    }

    public function test_account_cycle_internally_matches_same_symbol_before_residual_broker_buy(): void
    {
        [$user, $sellerProfile] = $this->actingReadyUser();
        $this->setMode($user, $sellerProfile, PortfolioProfile::EXECUTION_MODE_AUTOMATIC, confirm: true);
        $stock = $this->stock();
        app(TransactionWriteService::class)->create($sellerProfile, $stock, [
            'type' => 'buy', 'quantity' => 5, 'price' => 80, 'fees' => 0,
            'transaction_date' => now()->toDateString(), 'source' => 'manual',
        ], applyCash: false);
        $sell = $this->pendingBuy($sellerProfile, $stock, 500);
        $sell->forceFill([
            'recommendation_type' => TradingRecommendation::ACTION_EXIT_POSITION,
            'execution_plan' => ['suggested_quantity' => 5, 'suggested_investment_amount' => 500, 'side' => 'sell'],
            'target_amount' => 0, 'capital_resolved_amount' => 500,
            'remaining_target_amount' => 500, 'original_display_quantity' => 5,
        ])->save();

        $buyerProfile = $this->createPortfolioProfile($user, 'Buyer book', false);
        app(CashManagementService::class)->deposit($buyerProfile, 50_000, 'seed', $user);
        $this->withProfileHeader($user, $buyerProfile);
        $this->setMode($user, $buyerProfile, PortfolioProfile::EXECUTION_MODE_AUTOMATIC, confirm: true);
        $buy = $this->pendingBuy($buyerProfile, $stock, 1_000);
        $buy->forceFill([
            'target_amount' => 1_000, 'capital_resolved_amount' => 1_000,
            'remaining_target_amount' => 1_000, 'original_display_quantity' => 10,
        ])->save();

        $this->artisan('tos:submit-automatic-orders')->assertSuccessful();

        $this->assertDatabaseHas('portfolio_internal_execution_transfers', [
            'sell_recommendation_id' => $sell->id,
            'buy_recommendation_id' => $buy->id,
            'quantity' => 5,
            'valuation_status' => 'provisional',
        ]);
        $this->assertSame(TradingRecommendation::STATUS_EXECUTED, $sell->fresh()->status);
        $this->assertSame(500.0, (float) $buy->fresh()->remaining_target_amount);
        $placed = app(FakeBrokerGateway::class)->placed;
        $this->assertCount(1, $placed);
        $this->assertSame($buy->id, $placed[0]->recommendationId);
        $this->assertSame(5.0, $placed[0]->quantity);

        $transfer = InternalExecutionTransfer::query()->firstOrFail();
        $this->artisan('tos:finalize-internal-transfer-valuations')->assertSuccessful();
        $this->assertSame('provisional', $transfer->fresh()->valuation_status);

        $order = TradingOrder::query()->where('recommendation_id', $buy->id)->firstOrFail();
        app(FakeBrokerGateway::class)->seedSnapshot(new BrokerOrderSnapshot(
            $order->broker_order_id,
            'filled',
            5,
            0,
            110,
            'COMPLETE',
        ));
        app(LiveBrokerExecutionService::class)->reconcileOrder($buyerProfile, $order);
        $this->artisan('tos:finalize-internal-transfer-valuations')->assertSuccessful();

        $transfer->refresh();
        $this->assertSame('final', $transfer->valuation_status);
        $this->assertSame('residual_wavg_fill', $transfer->valuation_source);
        $this->assertSame(110.0, (float) $transfer->final_unit_price);
        $this->assertSame(110.0, (float) Transaction::query()->findOrFail($transfer->sell_transaction_id)->price);
        $this->assertSame(110.0, (float) Transaction::query()->findOrFail($transfer->buy_transaction_id)->price);
    }

    public function test_reconcile_failure_alerts_telegram_once_then_recovers(): void
    {
        [$user, $profile] = $this->actingReadyUser();
        $this->setMode($user, $profile, PortfolioProfile::EXECUTION_MODE_SEMI_AUTOMATIC);
        $this->seedHealthySyncRuns();
        $this->seedAdminTelegramRecipient();
        $this->bindCountingTelegram();

        $rec = $this->pendingBuy($profile);
        $this->postJson('/api/v1/execution/submit-selected', [
            'recommendation_ids' => [$rec->id],
            'recovery_code' => $this->totpCode($user),
        ])->assertOk();

        $fake = app(FakeBrokerGateway::class);
        $this->assertSame(1, $fake->placeCalls);
        $fake->nextFetchThrows = true;
        $this->artisan('tos:reconcile-broker-orders')->assertFailed();
        $fake->nextFetchThrows = true;
        $this->artisan('tos:reconcile-broker-orders')->assertFailed();
        $this->assertSame(1, $this->telegramCounts['reconcile']);

        $alert = OperationalAlert::query()->find(AdminOperationalAlertService::KEY_BROKER_RECONCILE_FAILED);
        $this->assertNotNull($alert);
        $this->assertNull($alert->resolved_at);

        $this->artisan('tos:reconcile-broker-orders')->assertSuccessful();
        $this->assertNotNull(OperationalAlert::query()->find(AdminOperationalAlertService::KEY_BROKER_RECONCILE_FAILED)?->resolved_at);
    }

    public function test_automatic_submit_failure_alerts_without_telegram_storm(): void
    {
        [$user, $profile] = $this->actingReadyUser();
        $this->setMode($user, $profile, PortfolioProfile::EXECUTION_MODE_AUTOMATIC, confirm: true);
        $this->pendingReviewBuy($profile);
        $this->seedHealthySyncRuns();
        $this->seedAdminTelegramRecipient();
        $this->bindCountingTelegram();

        $this->mock(LiveBrokerExecutionService::class, function ($mock): void {
            $mock->shouldReceive('submitAutomaticForProfile')
                ->andThrow(new RuntimeException('Simulated broker outage.'));
        });

        $this->artisan('tos:submit-automatic-orders')->assertFailed();
        $this->artisan('tos:submit-automatic-orders')->assertFailed();

        $this->assertSame(1, $this->telegramCounts['submit']);
        $row = OperationalAlert::query()->find(AdminOperationalAlertService::KEY_AUTOMATIC_SUBMIT_FAILED);
        $this->assertNotNull($row);
        $this->assertNull($row->resolved_at);
        $this->assertStringNotContainsString('test-access-token', (string) $row->message);
    }

    public function test_scheduled_skip_does_not_notify_telegram(): void
    {
        $this->seedPlainProfiles(1);
        $this->seedHealthySyncRuns();
        $this->seedAdminTelegramRecipient();
        app(DecisionPipelineScheduleService::class)->markAutomaticRunToday('scheduled');
        $this->bindCountingTelegram();

        $this->artisan('portfolio:decision-pipeline', ['--trigger' => 'scheduled'])->assertSuccessful();

        $this->assertSame(0, $this->telegramCounts['pipeline']);
        $this->assertSame(0, $this->telegramCounts['all']);
    }

    /**
     * @return list<PortfolioProfile>
     */
    protected function seedPlainProfiles(int $count): array
    {
        $profiles = [];
        for ($i = 0; $i < $count; $i++) {
            $user = User::query()->create([
                'name' => 'FEAT010 '.$i,
                'email' => 'feat010-'.$i.'-'.Str::random(6).'@example.com',
                'password' => 'password123',
            ]);
            $profiles[] = $this->defaultPortfolioFor($user);
        }

        return $profiles;
    }

    protected function seedHealthySyncRuns(): void
    {
        SyncRun::query()->create([
            'id' => (string) Str::uuid(),
            'job_name' => SyncLogService::JOB_DAILY_MARKET_DATA,
            'status' => 'success',
            'started_at' => now()->subHours(2),
            'finished_at' => now()->subHours(2),
        ]);
        SyncRun::query()->create([
            'id' => (string) Str::uuid(),
            'job_name' => SyncLogService::JOB_STOCK_MASTER,
            'status' => 'success',
            'started_at' => now()->subDay(),
            'finished_at' => now()->subDay(),
        ]);
        Setting::setValue('cron_timezone', 'Asia/Kolkata');
    }

    protected function seedAdminTelegramRecipient(): User
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $profile = $this->defaultPortfolioFor($admin);
        app(ProfileSettingsService::class)->update($profile, [
            'notifications_enabled' => 'true',
            'telegram_bot_token' => 'admin-token',
            'telegram_chat_id' => 'admin-chat',
        ]);

        return $admin;
    }

    protected function bindCountingTelegram(): void
    {
        $this->telegramCounts = ['pipeline' => 0, 'reconcile' => 0, 'submit' => 0, 'all' => 0];
        $telegram = $this->createMock(TelegramNotificationService::class);
        $telegram->method('sendAdminOperationalAlert')
            ->willReturnCallback(function (string $message) {
                $this->telegramCounts['all']++;
                $lower = strtolower($message);
                if (str_contains($lower, 'decision pipeline')) {
                    $this->telegramCounts['pipeline']++;
                }
                if (str_contains($lower, 'broker reconciliation')) {
                    $this->telegramCounts['reconcile']++;
                }
                if (str_contains($lower, 'automatic order submission')) {
                    $this->telegramCounts['submit']++;
                }

                return ['sent' => true, 'recipients' => 1];
            });
        $telegram->method('countAdminTelegramRecipients')->willReturn(1);
        $this->app->forgetInstance(AdminOperationalAlertService::class);
        $this->app->instance(TelegramNotificationService::class, $telegram);
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
}
