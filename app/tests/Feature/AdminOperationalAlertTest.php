<?php

namespace Tests\Feature;

use App\Models\OperationalAlert;
use App\Models\Setting;
use App\Models\SyncRun;
use App\Models\User;
use App\Services\AdminOperationalAlertService;
use App\Services\ProfileSettingsService;
use App\Services\SyncLogService;
use App\Services\TelegramNotificationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminOperationalAlertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-06 20:30:00', 'Asia/Kolkata'));
        Setting::setValue('cron_timezone', 'Asia/Kolkata');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Mirrors the collector's persisted source evidence for a one-session run. */
    protected function validatedDailyFiles(array $exchanges, string $date = '2026-07-06'): array
    {
        $files = [];
        foreach ($exchanges as $exchange => $status) {
            $files[] = [
                'exchange' => $exchange, 'date' => $date, 'status' => $status,
                'checked_at' => now()->toIso8601String(), 'coverage_hash' => str_repeat('a', 64),
                'source_revision_sha256' => str_repeat('b', 64),
            ];
        }
        return [
            'from_date' => $date, 'to_date' => $date, 'expected_exchanges' => array_keys($exchanges),
            'expected_files' => array_map(fn ($exchange) => ['exchange' => $exchange, 'date' => $date], array_keys($exchanges)),
            'files' => $files,
        ];
    }

    protected function setValidatedDailyWindowRun(array $exchanges = ['NSE', 'BSE']): void
    {
        $expected = $files = [];
        foreach (['2026-07-03', '2026-07-06'] as $date) {
            foreach ($exchanges as $exchange) {
                $expected[] = ['exchange' => $exchange, 'date' => $date];
                $files[] = ['exchange' => $exchange, 'date' => $date, 'status' => 'applied',
                    'checked_at' => now()->toIso8601String(), 'coverage_hash' => str_repeat('a', 64),
                    'source_revision_sha256' => str_repeat('b', 64)];
            }
        }
        Setting::setValue('universe_price_sync_last_run_json', json_encode([
            'collection_method' => 'exchange_bhavcopy', 'completed_at' => now()->toIso8601String(),
            'processed' => 574, 'succeeded' => 500, 'failed' => 74,
            'bulk' => ['from_date' => '2026-07-03', 'to_date' => '2026-07-06',
                'expected_exchanges' => $exchanges, 'expected_files' => $expected, 'files' => $files,
                'file_errors' => 0, 'identity_conflicts' => 0, 'unavailable_stocks' => 74],
        ]));
        SyncRun::query()->create(['id' => (string) Str::uuid(), 'job_name' => SyncLogService::JOB_UNIVERSE_PRICE_SYNC,
            'status' => 'partial', 'started_at' => now()->subMinute(), 'finished_at' => now()->timezone('Asia/Kolkata'),
            'stocks_processed' => 574, 'failures' => 74, 'summary' => 'bounded daily receipt run']);
    }

    protected function incompleteDailyPriceReport(float $coverage = 80.0, ?string $latest = '2026-07-03'): array
    {
        return ['as_of' => now()->toIso8601String(), 'datasets' => ['daily_prices' => [
            'freshness' => 'incomplete', 'coverage' => $coverage, 'backlog' => 115,
            'last_successful_ingestion' => $latest,
        ]]];
    }

    public function test_scheduler_inactive_alert_is_created(): void
    {
        $keys = collect(app(AdminOperationalAlertService::class)->evaluateConditions())
            ->pluck('key')
            ->all();

        $this->assertContains(AdminOperationalAlertService::KEY_SCHEDULER_INACTIVE, $keys);
    }

    public function test_daily_price_row_gaps_are_ignored_only_with_current_complete_source_proof(): void
    {
        config(['portfolio.universe_price_sync.enabled' => true]);
        $this->setValidatedDailyWindowRun();
        $this->assertTrue(app(AdminOperationalAlertService::class)->hasValidatedDailyPriceRowsOnly($this->incompleteDailyPriceReport()));
    }

    public function test_daily_price_suppression_fails_closed_for_disabled_missing_or_unmatched_source(): void
    {
        config(['portfolio.universe_price_sync.enabled' => false]);
        $this->assertFalse(app(AdminOperationalAlertService::class)->hasValidatedDailyPriceRowsOnly($this->incompleteDailyPriceReport()));

        config(['portfolio.universe_price_sync.enabled' => true]);
        Setting::setValue('universe_price_sync_last_run_json', json_encode(['collection_method' => 'exchange_bhavcopy']));
        $this->assertFalse(app(AdminOperationalAlertService::class)->hasValidatedDailyPriceRowsOnly($this->incompleteDailyPriceReport()));

        $this->setValidatedDailyWindowRun();
        SyncRun::query()->where('job_name', SyncLogService::JOB_UNIVERSE_PRICE_SYNC)->update(['finished_at' => now()->subHour()]);
        $this->assertFalse(app(AdminOperationalAlertService::class)->hasValidatedDailyPriceRowsOnly($this->incompleteDailyPriceReport()));
    }

    public function test_daily_price_suppression_requires_rows_in_required_session_and_nse_receipt(): void
    {
        config(['portfolio.universe_price_sync.enabled' => true]);
        $this->setValidatedDailyWindowRun(['BSE']);
        $this->assertFalse(app(AdminOperationalAlertService::class)->hasValidatedDailyPriceRowsOnly($this->incompleteDailyPriceReport()));

        SyncRun::query()->delete();
        $this->setValidatedDailyWindowRun();
        $this->assertFalse(app(AdminOperationalAlertService::class)->hasValidatedDailyPriceRowsOnly($this->incompleteDailyPriceReport(0.0, '2026-07-02')));
    }

    public function test_daily_price_suppression_rejects_absent_or_malformed_report_provenance(): void
    {
        config(['portfolio.universe_price_sync.enabled' => true]);
        $this->setValidatedDailyWindowRun();
        $report = $this->incompleteDailyPriceReport();
        unset($report['as_of']);
        $this->assertFalse(app(AdminOperationalAlertService::class)->hasValidatedDailyPriceRowsOnly($report));

        $report = $this->incompleteDailyPriceReport();
        $report['datasets']['daily_prices']['last_successful_ingestion'] = 'recently';
        $this->assertFalse(app(AdminOperationalAlertService::class)->hasValidatedDailyPriceRowsOnly($report));

        $payload = json_decode((string) Setting::getValue('universe_price_sync_last_run_json'), true);
        unset($payload['bulk']['identity_conflicts']);
        Setting::setValue('universe_price_sync_last_run_json', json_encode($payload));
        $this->assertFalse(app(AdminOperationalAlertService::class)->hasValidatedDailyPriceRowsOnly($this->incompleteDailyPriceReport()));
    }

    public function test_stock_master_failure_creates_alert_and_notifies_admin_telegram(): void
    {
        config(['portfolio.universe_price_sync.enabled' => false]);

        $admin = User::factory()->create(['is_admin' => true]);
        $profile = $this->defaultPortfolioFor($admin);
        app(ProfileSettingsService::class)->update($profile, [
            'notifications_enabled' => 'true',
            'telegram_bot_token' => 'admin-token',
            'telegram_chat_id' => 'admin-chat',
        ]);

        SyncRun::query()->create([
            'id' => (string) Str::uuid(),
            'job_name' => SyncLogService::JOB_STOCK_MASTER,
            'status' => 'failed',
            'started_at' => now()->subHour(),
            'finished_at' => now()->subHour(),
            'summary' => 'CSV download failed',
        ]);

        SyncRun::query()->create([
            'id' => (string) Str::uuid(),
            'job_name' => SyncLogService::JOB_DAILY_MARKET_DATA,
            'status' => 'success',
            'started_at' => now()->subHours(2),
            'finished_at' => now()->subHours(2),
        ]);

        $telegram = $this->createMock(TelegramNotificationService::class);
        $telegram->expects($this->atLeastOnce())
            ->method('sendAdminOperationalAlert')
            ->with($this->callback(fn (string $message) => str_contains(strtolower($message), 'stock master')))
            ->willReturn(['sent' => true, 'recipients' => 1]);
        $this->app->instance(TelegramNotificationService::class, $telegram);

        $result = app(AdminOperationalAlertService::class)->syncAndNotify();

        $this->assertContains(AdminOperationalAlertService::KEY_STOCK_MASTER_FAILED, $result['notified']);
    }

    public function test_admin_status_includes_operational_alerts(): void
    {
        config(['portfolio.universe_price_sync.enabled' => false]);

        $admin = User::factory()->create(['is_admin' => true]);
        $this->defaultPortfolioFor($admin);

        SyncRun::query()->create([
            'id' => (string) Str::uuid(),
            'job_name' => SyncLogService::JOB_STOCK_MASTER,
            'status' => 'failed',
            'started_at' => now()->subHour(),
            'finished_at' => now()->subHour(),
            'summary' => 'CSV download failed',
        ]);
        SyncRun::query()->create([
            'id' => (string) Str::uuid(),
            'job_name' => SyncLogService::JOB_DAILY_MARKET_DATA,
            'status' => 'success',
            'started_at' => now()->subHours(2),
            'finished_at' => now()->subHours(2),
        ]);

        $response = $this->actingAs($admin)
            ->getJson('/api/universe-price-sync/status?scope=all_equities')
            ->assertOk();

        $keys = collect($response->json('data.operational_alerts.active'))->pluck('key')->all();
        $this->assertContains(AdminOperationalAlertService::KEY_STOCK_MASTER_FAILED, $keys);
        $response->assertJsonStructure([
            'data' => [
                'operational_alerts' => ['active', 'unacknowledged_count', 'admin_telegram_recipients'],
            ],
        ]);
    }

    public function test_admin_can_acknowledge_all_operational_alerts(): void
    {
        config(['portfolio.universe_price_sync.enabled' => false]);

        $admin = User::factory()->create(['is_admin' => true]);
        $this->defaultPortfolioFor($admin);

        OperationalAlert::query()->create([
            'alert_key' => AdminOperationalAlertService::KEY_DAILY_SYNC_OVERDUE,
            'severity' => 'warning',
            'title' => 'Daily market sync overdue',
            'message' => 'Test message',
            'context' => null,
            'first_triggered_at' => now(),
            'last_triggered_at' => now(),
        ]);
        OperationalAlert::query()->create([
            'alert_key' => AdminOperationalAlertService::KEY_STOCK_MASTER_FAILED,
            'severity' => 'critical',
            'title' => 'Stock master sync failed',
            'message' => 'Test message',
            'context' => null,
            'first_triggered_at' => now(),
            'last_triggered_at' => now(),
        ]);

        $this->actingAs($admin)
            ->postJson('/api/operational-alerts/acknowledge-all')
            ->assertOk()
            ->assertJsonPath('data.cleared_count', 2)
            ->assertJsonPath('data.unacknowledged_count', 0);
    }

    public function test_admin_can_acknowledge_operational_alert(): void
    {
        config(['portfolio.universe_price_sync.enabled' => false]);

        $admin = User::factory()->create(['is_admin' => true]);
        $this->defaultPortfolioFor($admin);

        OperationalAlert::query()->create([
            'alert_key' => AdminOperationalAlertService::KEY_DAILY_SYNC_OVERDUE,
            'severity' => 'warning',
            'title' => 'Daily market sync overdue',
            'message' => 'Test message',
            'context' => null,
            'first_triggered_at' => now(),
            'last_triggered_at' => now(),
        ]);

        $this->actingAs($admin)
            ->postJson('/api/operational-alerts/acknowledge', [
                'key' => AdminOperationalAlertService::KEY_DAILY_SYNC_OVERDUE,
            ])
            ->assertOk()
            ->assertJsonPath('data.unacknowledged_count', 0);

        $this->assertNotNull(
            OperationalAlert::query()->find(AdminOperationalAlertService::KEY_DAILY_SYNC_OVERDUE)?->acknowledged_at,
        );
    }

    public function test_non_admin_cannot_access_operational_alerts(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $this->defaultPortfolioFor($user);

        $this->actingAs($user)
            ->getJson('/api/operational-alerts')
            ->assertForbidden();
    }

    public function test_check_operational_alerts_command_runs(): void
    {
        $this->artisan('portfolio:check-operational-alerts')
            ->assertExitCode(0);
    }

    public function test_admin_can_clear_dismissed_alert_manually(): void
    {
        config(['portfolio.universe_price_sync.enabled' => false]);

        $admin = User::factory()->create(['is_admin' => true]);
        $this->defaultPortfolioFor($admin);

        OperationalAlert::query()->create([
            'alert_key' => AdminOperationalAlertService::KEY_STOCK_MASTER_FAILED,
            'severity' => 'critical',
            'title' => 'Stock master sync failed',
            'message' => 'Test message',
            'context' => null,
            'first_triggered_at' => now(),
            'last_triggered_at' => now(),
            'acknowledged_at' => now(),
        ]);

        $this->actingAs($admin)
            ->postJson('/api/operational-alerts/clear', [
                'key' => AdminOperationalAlertService::KEY_STOCK_MASTER_FAILED,
            ])
            ->assertOk()
            ->assertJsonPath('data.active', []);

        $row = OperationalAlert::query()->find(AdminOperationalAlertService::KEY_STOCK_MASTER_FAILED);
        $this->assertNotNull($row->resolved_at);
        $this->assertNotNull($row->manually_cleared_at);
    }

    public function test_manually_cleared_alert_stays_hidden_while_condition_persists(): void
    {
        config(['portfolio.universe_price_sync.enabled' => false]);

        $admin = User::factory()->create(['is_admin' => true]);
        $this->defaultPortfolioFor($admin);

        SyncRun::query()->create([
            'id' => (string) Str::uuid(),
            'job_name' => SyncLogService::JOB_STOCK_MASTER,
            'status' => 'failed',
            'started_at' => now()->subHour(),
            'finished_at' => now()->subHour(),
            'summary' => 'CSV download failed',
        ]);
        SyncRun::query()->create([
            'id' => (string) Str::uuid(),
            'job_name' => SyncLogService::JOB_DAILY_MARKET_DATA,
            'status' => 'success',
            'started_at' => now()->subHours(2),
            'finished_at' => now()->subHours(2),
        ]);

        $service = app(AdminOperationalAlertService::class);
        $service->syncActiveAlerts(false);
        $service->clearManually(AdminOperationalAlertService::KEY_STOCK_MASTER_FAILED);

        $service->syncActiveAlerts(false);

        $keys = collect($service->getActiveAlertsForApi())->pluck('key')->all();
        $this->assertNotContains(AdminOperationalAlertService::KEY_STOCK_MASTER_FAILED, $keys);
    }

    public function test_admin_can_clear_all_dismissed_alerts(): void
    {
        config(['portfolio.universe_price_sync.enabled' => false]);

        $admin = User::factory()->create(['is_admin' => true]);
        $this->defaultPortfolioFor($admin);

        OperationalAlert::query()->create([
            'alert_key' => AdminOperationalAlertService::KEY_DAILY_SYNC_OVERDUE,
            'severity' => 'warning',
            'title' => 'Daily market sync overdue',
            'message' => 'Test message',
            'context' => null,
            'first_triggered_at' => now(),
            'last_triggered_at' => now(),
            'acknowledged_at' => now(),
        ]);
        OperationalAlert::query()->create([
            'alert_key' => AdminOperationalAlertService::KEY_STOCK_MASTER_FAILED,
            'severity' => 'critical',
            'title' => 'Stock master sync failed',
            'message' => 'Test message',
            'context' => null,
            'first_triggered_at' => now(),
            'last_triggered_at' => now(),
            'acknowledged_at' => now(),
        ]);

        $this->actingAs($admin)
            ->postJson('/api/operational-alerts/clear-dismissed')
            ->assertOk()
            ->assertJsonPath('data.cleared_count', 2)
            ->assertJsonPath('data.active', []);
    }

    public function test_clear_ping_not_sent_when_flag_disabled(): void
    {
        $this->seedHealthySyncState();
        Setting::setValue('admin_ops_telegram_ping_when_clear', 'false');

        $admin = User::factory()->create(['is_admin' => true]);
        $this->defaultPortfolioFor($admin);

        $telegram = $this->createMock(TelegramNotificationService::class);
        $telegram->expects($this->never())->method('sendAdminOperationalAlert');
        $this->app->instance(TelegramNotificationService::class, $telegram);

        $this->actingAs($admin)
            ->postJson('/api/operational-alerts/run-check')
            ->assertOk()
            ->assertJsonPath('data.active', []);
    }

    public function test_clear_ping_sent_when_flag_enabled_and_no_alerts(): void
    {
        $this->seedHealthySyncState();
        Setting::setValue('admin_ops_telegram_ping_when_clear', 'true');

        $admin = User::factory()->create(['is_admin' => true]);
        $profile = $this->defaultPortfolioFor($admin);
        app(ProfileSettingsService::class)->update($profile, [
            'notifications_enabled' => 'true',
            'telegram_bot_token' => 'admin-token',
            'telegram_chat_id' => 'admin-chat',
        ]);

        $telegram = $this->createMock(TelegramNotificationService::class);
        $telegram->expects($this->once())
            ->method('sendAdminOperationalAlert')
            ->with($this->callback(fn (string $message) => str_contains(strtolower($message), 'no active operational alerts')))
            ->willReturn(['sent' => true, 'recipients' => 1]);
        $this->app->instance(TelegramNotificationService::class, $telegram);

        $this->actingAs($admin)
            ->postJson('/api/operational-alerts/run-check')
            ->assertOk()
            ->assertJsonPath('data.active', [])
            ->assertJsonFragment(['_all_clear']);
    }

    public function test_automatic_sync_and_notify_does_not_send_clear_ping_even_with_flag_on(): void
    {
        $this->seedHealthySyncState();
        Setting::setValue('admin_ops_telegram_ping_when_clear', 'true');

        $telegram = $this->createMock(TelegramNotificationService::class);
        $telegram->expects($this->never())->method('sendAdminOperationalAlert');
        $this->app->instance(TelegramNotificationService::class, $telegram);

        $result = app(AdminOperationalAlertService::class)->syncAndNotify();
        $this->assertSame([], $result['active']);
        $this->assertNotContains('_all_clear', $result['notified']);
    }

    public function test_non_admin_cannot_run_operational_alert_check(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $this->defaultPortfolioFor($user);

        $this->actingAs($user)
            ->postJson('/api/operational-alerts/run-check')
            ->assertForbidden();
    }

    public function test_universe_sync_not_overdue_at_maintenance_window_open_with_earlier_run(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 19:05:00', 'Asia/Kolkata'));
        $this->seedUniverseSyncState(finishedAt: now()->copy()->setTime(5, 1, 23));

        $keys = collect(app(AdminOperationalAlertService::class)->evaluateConditions())
            ->pluck('key')
            ->all();

        $this->assertNotContains(AdminOperationalAlertService::KEY_UNIVERSE_SYNC_OVERDUE, $keys);
    }

    public function test_universe_sync_overdue_when_maintenance_window_has_no_recent_run(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 19:46:00', 'Asia/Kolkata'));
        $this->seedUniverseSyncState(finishedAt: now()->copy()->setTime(5, 1, 23));

        $keys = collect(app(AdminOperationalAlertService::class)->evaluateConditions())
            ->pluck('key')
            ->all();

        $this->assertContains(AdminOperationalAlertService::KEY_UNIVERSE_SYNC_OVERDUE, $keys);
    }

    public function test_universe_sync_not_overdue_when_run_exists_in_maintenance_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 20:30:00', 'Asia/Kolkata'));
        $this->seedUniverseSyncState(finishedAt: now()->copy()->setTime(20, 15, 0));

        $keys = collect(app(AdminOperationalAlertService::class)->evaluateConditions())
            ->pluck('key')
            ->all();

        $this->assertNotContains(AdminOperationalAlertService::KEY_UNIVERSE_SYNC_OVERDUE, $keys);
    }

    public function test_empty_provider_storm_does_not_create_rate_limit_alert(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 20:30:00', 'Asia/Kolkata'));
        config(['portfolio.universe_price_sync.enabled' => true]);

        Setting::setValue('universe_price_sync_last_run_json', json_encode([
            'processed' => 125,
            'succeeded' => 0,
            'failed' => 125,
            'rate_limit_hits' => 0,
            'failure_rate_percent' => 100,
            'completed_at' => now()->subMinutes(10)->toIso8601String(),
            'errors' => ['RELIANCE: nse: returned 0 rows in requested range'],
        ]));

        SyncRun::query()->create([
            'id' => (string) Str::uuid(),
            'job_name' => SyncLogService::JOB_UNIVERSE_PRICE_SYNC,
            'status' => 'failed',
            'started_at' => now()->subMinutes(15),
            'finished_at' => now()->subMinutes(10),
            'stocks_processed' => 125,
            'failures' => 125,
            'summary' => 'ok=0 fail=125',
        ]);
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

        $keys = collect(app(AdminOperationalAlertService::class)->evaluateConditions())
            ->pluck('key')
            ->all();

        $this->assertContains(AdminOperationalAlertService::KEY_UNIVERSE_SYNC_FAILED, $keys);
        $this->assertNotContains(AdminOperationalAlertService::KEY_PROVIDER_RATE_LIMIT, $keys);
    }

    public function test_validated_bhavcopy_with_partial_coverage_does_not_raise_universe_failure(): void
    {
        config(['portfolio.universe_price_sync.enabled' => true]);
        Setting::setValue('universe_price_sync_last_run_json', json_encode([
            'collection_method' => 'exchange_bhavcopy', 'processed' => 574, 'succeeded' => 0, 'failed' => 574,
            'completed_at' => now()->toIso8601String(), 'rate_limit_hits' => 0,
            'bulk' => ['file_errors' => 0, 'identity_conflicts' => 0, 'unavailable_stocks' => 574,
                'files_downloaded' => 2] + $this->validatedDailyFiles(['NSE' => 'applied', 'BSE' => 'applied']),
        ]));
        SyncRun::query()->create([
            'id' => (string) Str::uuid(), 'job_name' => SyncLogService::JOB_UNIVERSE_PRICE_SYNC,
            'status' => 'failed', 'started_at' => now()->subMinute(), 'finished_at' => now()->timezone('Asia/Kolkata'),
            'stocks_processed' => 574, 'failures' => 574, 'summary' => 'source=exchange_bhavcopy',
        ]);

        $keys = collect(app(AdminOperationalAlertService::class)->evaluateConditions())->pluck('key')->all();
        $this->assertNotContains(AdminOperationalAlertService::KEY_UNIVERSE_SYNC_FAILED, $keys);
    }

    public function test_source_evidence_must_match_complete_expected_exchange_session_set(): void
    {
        config(['portfolio.universe_price_sync.enabled' => true]);
        $first = $this->validatedDailyFiles(['NSE' => 'applied', 'BSE' => 'receipt_cached'], '2026-07-03');
        $second = $this->validatedDailyFiles(['NSE' => 'applied', 'BSE' => 'applied'], '2026-07-06');
        $valid = [
            'from_date' => '2026-07-03', 'to_date' => '2026-07-06', 'expected_exchanges' => ['NSE', 'BSE'],
            'expected_files' => array_merge($first['expected_files'], $second['expected_files']),
            'files' => array_merge($first['files'], $second['files']),
        ];
        $cases = [
            array_slice($valid['files'], 1), // missing exchange
            array_slice($valid['files'], 0, 2), // missing session
            array_merge($valid['files'], [$valid['files'][0]]), // duplicate receipt
            array_merge(array_slice($valid['files'], 0, 3), [array_replace($valid['files'][3], ['date' => '2026-07-02'])]),
            [array_replace($valid['files'][0], ['checked_at' => '2026-06-01T00:00:00+00:00']), ...array_slice($valid['files'], 1)], // stale receipt
            [array_replace($valid['files'][0], ['checked_at' => now()->addHour()->toIso8601String()]), ...array_slice($valid['files'], 1)], // forged future check
            [array_replace($valid['files'][0], ['checked_at' => 'now']), ...array_slice($valid['files'], 1)], // non-ISO timestamp
        ];
        foreach ($cases as $files) {
            $payload = ['collection_method' => 'exchange_bhavcopy', 'processed' => 2, 'succeeded' => 0,
                'failed' => 2, 'completed_at' => now()->toIso8601String(), 'bulk' => [
                    'file_errors' => 0, 'identity_conflicts' => 0, 'unavailable_stocks' => 2,
                    'from_date' => $valid['from_date'], 'to_date' => $valid['to_date'],
                    'expected_exchanges' => $valid['expected_exchanges'],
                    'expected_files' => $valid['expected_files'], 'files' => $files,
                ]];
            Setting::setValue('universe_price_sync_last_run_json', json_encode($payload));
            SyncRun::query()->create(['id' => (string) Str::uuid(), 'job_name' => SyncLogService::JOB_UNIVERSE_PRICE_SYNC,
                'status' => 'partial', 'started_at' => now()->subMinute(), 'finished_at' => now()->timezone('Asia/Kolkata'),
                'stocks_processed' => 2, 'failures' => 2, 'summary' => 'bounded daily run']);
            $keys = collect(app(AdminOperationalAlertService::class)->evaluateConditions())->pluck('key')->all();
            $this->assertContains(AdminOperationalAlertService::KEY_UNIVERSE_SYNC_FAILED, $keys);
            SyncRun::query()->where('job_name', SyncLogService::JOB_UNIVERSE_PRICE_SYNC)->delete();
        }
        $payload['bulk']['files'] = $valid['files'];
        $payload['bulk']['expected_files'] = array_slice($valid['expected_files'], 0, 2);
        Setting::setValue('universe_price_sync_last_run_json', json_encode($payload));
        SyncRun::query()->create(['id' => (string) Str::uuid(), 'job_name' => SyncLogService::JOB_UNIVERSE_PRICE_SYNC,
            'status' => 'partial', 'started_at' => now()->subMinute(), 'finished_at' => now()->timezone('Asia/Kolkata'),
            'stocks_processed' => 2, 'failures' => 2, 'summary' => 'truncated expected workset']);
        $keys = collect(app(AdminOperationalAlertService::class)->evaluateConditions())->pluck('key')->all();
        $this->assertContains(AdminOperationalAlertService::KEY_UNIVERSE_SYNC_FAILED, $keys);
        SyncRun::query()->where('job_name', SyncLogService::JOB_UNIVERSE_PRICE_SYNC)->delete();

        $payload['bulk']['expected_files'] = $valid['expected_files'];
        Setting::setValue('universe_price_sync_last_run_json', json_encode($payload));
        SyncRun::query()->create(['id' => (string) Str::uuid(), 'job_name' => SyncLogService::JOB_UNIVERSE_PRICE_SYNC,
            'status' => 'partial', 'started_at' => now()->subMinute(), 'finished_at' => now()->timezone('Asia/Kolkata'),
            'stocks_processed' => 2, 'failures' => 2, 'summary' => 'complete receipts']);
        $keys = collect(app(AdminOperationalAlertService::class)->evaluateConditions())->pluck('key')->all();
        $this->assertNotContains(AdminOperationalAlertService::KEY_UNIVERSE_SYNC_FAILED, $keys);
    }

    public function test_old_or_future_completion_timestamp_cannot_suppress_coverage_alert(): void
    {
        config(['portfolio.universe_price_sync.enabled' => true]);
        foreach ([now()->subHour(), now()->addHour()] as $completion) {
            $evidence = $this->validatedDailyFiles(['NSE' => 'applied', 'BSE' => 'applied']);
            Setting::setValue('universe_price_sync_last_run_json', json_encode([
                'collection_method' => 'exchange_bhavcopy', 'processed' => 2, 'succeeded' => 0, 'failed' => 2,
                'completed_at' => $completion->toIso8601String(), 'bulk' => array_merge([
                    'file_errors' => 0, 'identity_conflicts' => 0, 'unavailable_stocks' => 2,
                ], $evidence),
            ]));
            SyncRun::query()->create(['id' => (string) Str::uuid(), 'job_name' => SyncLogService::JOB_UNIVERSE_PRICE_SYNC,
                'status' => 'failed', 'started_at' => now()->subMinute(), 'finished_at' => now()->timezone('Asia/Kolkata'),
                'stocks_processed' => 2, 'failures' => 2, 'summary' => 'timestamp mismatch']);
            $keys = collect(app(AdminOperationalAlertService::class)->evaluateConditions())->pluck('key')->all();
            $this->assertContains(AdminOperationalAlertService::KEY_UNIVERSE_SYNC_FAILED, $keys);
            SyncRun::query()->where('job_name', SyncLogService::JOB_UNIVERSE_PRICE_SYNC)->delete();
        }
    }

    public function test_retry_pending_exchange_file_is_not_actionable_when_provenance_is_complete(): void
    {
        config(['portfolio.universe_price_sync.enabled' => true]);
        Setting::setValue('universe_price_sync_last_run_json', json_encode([
            'collection_method' => 'exchange_bhavcopy', 'processed' => 2, 'succeeded' => 1, 'failed' => 1,
            'completed_at' => now()->toIso8601String(), 'bulk' => [
                'file_errors' => 0, 'identity_conflicts' => 0, 'unavailable_stocks' => 1,
                'files' => [['exchange' => 'NSE', 'date' => '2026-07-06', 'status' => 'retry_deferred', 'retry_attempt' => 2]],
            ],
        ]));
        SyncRun::query()->create(['id' => (string) Str::uuid(), 'job_name' => SyncLogService::JOB_UNIVERSE_PRICE_SYNC,
            'status' => 'success', 'started_at' => now()->subMinute(), 'finished_at' => now()->timezone('Asia/Kolkata'),
            'stocks_processed' => 2, 'failures' => 0, 'summary' => 'retry deferred']);
        $keys = collect(app(AdminOperationalAlertService::class)->evaluateConditions())->pluck('key')->all();
        $this->assertNotContains(AdminOperationalAlertService::KEY_UNIVERSE_SYNC_FAILED, $keys);

        $payload = json_decode((string) Setting::getValue('universe_price_sync_last_run_json'), true);
        $payload['bulk']['files'][0]['retry_attempt'] = 4;
        Setting::setValue('universe_price_sync_last_run_json', json_encode($payload));
        $keys = collect(app(AdminOperationalAlertService::class)->evaluateConditions())->pluck('key')->all();
        $this->assertContains(AdminOperationalAlertService::KEY_UNIVERSE_SYNC_FAILED, $keys);
    }

    public function test_first_attempt_is_not_quiet_without_complete_provenance_or_retry_stage(): void
    {
        config(['portfolio.universe_price_sync.enabled' => true]);
        $evidence = $this->validatedDailyFiles(['NSE' => 'applied']);
        $evidence['expected_exchanges'][] = 'BSE';
        $evidence['expected_files'][] = ['exchange' => 'BSE', 'date' => '2026-07-06'];
        $evidence['files'][] = ['exchange' => 'BSE', 'date' => '2026-07-06', 'status' => 'failed',
            'failure_stage' => 'exchange_fetch', 'error' => 'temporary source transport failure'];
        Setting::setValue('universe_price_sync_last_run_json', json_encode([
            'collection_method' => 'exchange_bhavcopy', 'completed_at' => now()->toIso8601String(),
            'failed' => 1, 'succeeded' => 1, 'errors' => ['BSE:2026-07-06: temporary source transport failure'], 'bulk' => array_merge([
                'file_errors' => 1, 'identity_conflicts' => 0, 'unavailable_stocks' => 1,
            ], $evidence),
        ]));
        SyncRun::query()->create(['id' => (string) Str::uuid(), 'job_name' => SyncLogService::JOB_UNIVERSE_PRICE_SYNC,
            'status' => 'partial', 'started_at' => now()->subMinute(), 'finished_at' => now()->timezone('Asia/Kolkata'),
            'stocks_processed' => 2, 'failures' => 1, 'summary' => 'retry attempt missing']);
        $keys = collect(app(AdminOperationalAlertService::class)->evaluateConditions())->pluck('key')->all();
        $this->assertContains(AdminOperationalAlertService::KEY_UNIVERSE_SYNC_FAILED, $keys);
    }

    public function test_empty_expected_workset_is_healthy_for_a_proven_nontrading_window(): void
    {
        config(['portfolio.universe_price_sync.enabled' => true]);
        Setting::setValue('universe_price_sync_last_run_json', json_encode([
            'collection_method' => 'exchange_bhavcopy', 'completed_at' => now()->toIso8601String(),
            'failed' => 2, 'succeeded' => 0, 'bulk' => [
                'from_date' => '2026-07-04', 'to_date' => '2026-07-05', 'expected_exchanges' => ['NSE', 'BSE'],
                'file_errors' => 0, 'identity_conflicts' => 0, 'unavailable_stocks' => 2,
                'expected_files' => [], 'files' => [],
            ],
        ]));
        SyncRun::query()->create(['id' => (string) Str::uuid(), 'job_name' => SyncLogService::JOB_UNIVERSE_PRICE_SYNC,
            'status' => 'failed', 'started_at' => now()->subMinute(), 'finished_at' => now()->timezone('Asia/Kolkata'),
            'stocks_processed' => 2, 'failures' => 2, 'summary' => 'no expected exchange sessions']);
        $keys = collect(app(AdminOperationalAlertService::class)->evaluateConditions())->pluck('key')->all();
        $this->assertNotContains(AdminOperationalAlertService::KEY_UNIVERSE_SYNC_FAILED, $keys);
    }

    public function test_cached_validated_receipts_are_healthy_but_missing_exchange_file_is_not(): void
    {
        config(['portfolio.universe_price_sync.enabled' => true]);
        $base = ['collection_method' => 'exchange_bhavcopy', 'processed' => 2, 'succeeded' => 0, 'failed' => 2,
            'completed_at' => now()->toIso8601String(), 'rate_limit_hits' => 0,
            'bulk' => ['file_errors' => 0, 'identity_conflicts' => 0, 'unavailable_stocks' => 2,
                'files_downloaded' => 0] + $this->validatedDailyFiles(['NSE' => 'receipt_cached', 'BSE' => 'receipt_cached'])];
        Setting::setValue('universe_price_sync_last_run_json', json_encode($base));
        SyncRun::query()->create([
            'id' => (string) Str::uuid(), 'job_name' => SyncLogService::JOB_UNIVERSE_PRICE_SYNC,
            'status' => 'failed', 'started_at' => now()->subMinute(), 'finished_at' => now()->timezone('Asia/Kolkata'),
            'stocks_processed' => 2, 'failures' => 2, 'summary' => 'cached receipt coverage partial',
        ]);
        $keys = collect(app(AdminOperationalAlertService::class)->evaluateConditions())->pluck('key')->all();
        $this->assertNotContains(AdminOperationalAlertService::KEY_UNIVERSE_SYNC_FAILED, $keys);

        Setting::setValue('universe_price_sync_last_run_json', json_encode(array_replace_recursive($base, [
            'bulk' => ['file_errors' => 1, 'files' => [['exchange' => 'BSE', 'status' => 'failed', 'retry_attempt' => 2]]],
        ])));
        $keys = collect(app(AdminOperationalAlertService::class)->evaluateConditions())->pluck('key')->all();
        $this->assertContains(AdminOperationalAlertService::KEY_UNIVERSE_SYNC_FAILED, $keys);
    }

    public function test_schema_failure_in_one_exchange_keeps_mixed_source_run_actionable(): void
    {
        config(['portfolio.universe_price_sync.enabled' => true]);
        Setting::setValue('universe_price_sync_last_run_json', json_encode([
            'collection_method' => 'exchange_bhavcopy', 'processed' => 574, 'succeeded' => 0, 'failed' => 574,
            'completed_at' => now()->toIso8601String(), 'rate_limit_hits' => 0,
            'bulk' => ['file_errors' => 1, 'identity_conflicts' => 0, 'unavailable_stocks' => 574,
                'files_downloaded' => 2, 'files' => [
                    ['exchange' => 'NSE', 'status' => 'applied'],
                    ['exchange' => 'BSE', 'status' => 'failed', 'error' => 'invalid schema', 'retry_attempt' => 2],
                ]],
        ]));
        SyncRun::query()->create([
            'id' => (string) Str::uuid(), 'job_name' => SyncLogService::JOB_UNIVERSE_PRICE_SYNC,
            'status' => 'failed', 'started_at' => now()->subMinute(), 'finished_at' => now()->timezone('Asia/Kolkata'),
            'stocks_processed' => 574, 'failures' => 574, 'summary' => 'NSE okay, BSE schema invalid',
        ]);

        $keys = collect(app(AdminOperationalAlertService::class)->evaluateConditions())->pluck('key')->all();
        $this->assertContains(AdminOperationalAlertService::KEY_UNIVERSE_SYNC_FAILED, $keys);
    }

    public function test_partial_run_identity_conflict_alerts_even_with_zero_file_errors(): void
    {
        config(['portfolio.universe_price_sync.enabled' => true]);
        Setting::setValue('universe_price_sync_last_run_json', json_encode([
            'collection_method' => 'exchange_bhavcopy', 'processed' => 574, 'succeeded' => 0, 'failed' => 574,
            'completed_at' => now()->toIso8601String(),
            'bulk' => ['file_errors' => 0, 'identity_conflicts' => 1, 'unavailable_stocks' => 574,
                'files' => [['exchange' => 'NSE', 'status' => 'identity_conflict'], ['exchange' => 'BSE', 'status' => 'applied']]],
        ]));
        SyncRun::query()->create([
            'id' => (string) Str::uuid(), 'job_name' => SyncLogService::JOB_UNIVERSE_PRICE_SYNC,
            'status' => 'partial', 'started_at' => now()->subMinute(), 'finished_at' => now()->timezone('Asia/Kolkata'),
            'stocks_processed' => 574, 'failures' => 1, 'summary' => 'identity conflict',
        ]);

        $alerts = collect(app(AdminOperationalAlertService::class)->evaluateConditions())
            ->where('key', AdminOperationalAlertService::KEY_UNIVERSE_SYNC_FAILED);
        $this->assertCount(1, $alerts);
        $this->assertSame(1, $alerts->first()['context']['identity_conflicts']);
    }

    public function test_partial_daily_run_without_validated_file_evidence_stays_visible(): void
    {
        config(['portfolio.universe_price_sync.enabled' => true]);
        Setting::setValue('universe_price_sync_last_run_json', json_encode([
            'collection_method' => 'exchange_bhavcopy', 'processed' => 574, 'succeeded' => 0, 'failed' => 574,
            'completed_at' => now()->toIso8601String(),
            'bulk' => ['file_errors' => 0, 'identity_conflicts' => 0, 'unavailable_stocks' => 574, 'files' => []],
        ]));
        SyncRun::query()->create([
            'id' => (string) Str::uuid(), 'job_name' => SyncLogService::JOB_UNIVERSE_PRICE_SYNC,
            'status' => 'partial', 'started_at' => now()->subMinute(), 'finished_at' => now()->timezone('Asia/Kolkata'),
            'stocks_processed' => 574, 'failures' => 574, 'summary' => 'no file evidence',
        ]);

        $keys = collect(app(AdminOperationalAlertService::class)->evaluateConditions())->pluck('key')->all();
        $this->assertContains(AdminOperationalAlertService::KEY_UNIVERSE_SYNC_FAILED, $keys);
    }

    public function test_retry_deferred_after_multiple_attempts_stays_visible_on_partial_run(): void
    {
        config(['portfolio.universe_price_sync.enabled' => true]);
        Setting::setValue('universe_price_sync_last_run_json', json_encode([
            'collection_method' => 'exchange_bhavcopy', 'processed' => 574, 'succeeded' => 0, 'failed' => 574,
            'completed_at' => now()->toIso8601String(),
            'bulk' => ['file_errors' => 1, 'identity_conflicts' => 0, 'unavailable_stocks' => 574,
                'files' => [['exchange' => 'NSE', 'status' => 'retry_deferred', 'retry_attempt' => 2]]],
        ]));
        SyncRun::query()->create([
            'id' => (string) Str::uuid(), 'job_name' => SyncLogService::JOB_UNIVERSE_PRICE_SYNC,
            'status' => 'partial', 'started_at' => now()->subMinute(), 'finished_at' => now()->timezone('Asia/Kolkata'),
            'stocks_processed' => 574, 'failures' => 574, 'summary' => 'retry deferred',
        ]);

        $keys = collect(app(AdminOperationalAlertService::class)->evaluateConditions())->pluck('key')->all();
        $this->assertContains(AdminOperationalAlertService::KEY_UNIVERSE_SYNC_FAILED, $keys);
    }

    public function test_newer_failed_run_is_not_muted_by_an_older_healthy_receipt(): void
    {
        config(['portfolio.universe_price_sync.enabled' => true]);
        Setting::setValue('universe_price_sync_last_run_json', json_encode([
            'collection_method' => 'exchange_bhavcopy', 'processed' => 574, 'succeeded' => 0, 'failed' => 574,
            'completed_at' => now()->subHour()->toIso8601String(), 'rate_limit_hits' => 0,
            'bulk' => ['file_errors' => 0, 'identity_conflicts' => 0, 'unavailable_stocks' => 574,
                'files' => [['status' => 'applied'], ['status' => 'receipt_cached']],
            ],
        ]));
        SyncRun::query()->create([
            'id' => (string) Str::uuid(), 'job_name' => SyncLogService::JOB_UNIVERSE_PRICE_SYNC,
            'status' => 'failed', 'started_at' => now()->subMinute(), 'finished_at' => now()->timezone('Asia/Kolkata'),
            'stocks_processed' => 574, 'failures' => 574, 'summary' => 'code malfunction after last receipt',
        ]);

        $keys = collect(app(AdminOperationalAlertService::class)->evaluateConditions())->pluck('key')->all();
        $this->assertContains(AdminOperationalAlertService::KEY_UNIVERSE_SYNC_FAILED, $keys);
    }

    public function test_coverage_only_transition_resolves_old_universe_alert_without_telegram(): void
    {
        config(['portfolio.universe_price_sync.enabled' => true]);
        Setting::setValue('universe_price_sync_last_run_json', json_encode([
            'collection_method' => 'exchange_bhavcopy', 'processed' => 574, 'succeeded' => 0, 'failed' => 574,
            'completed_at' => now()->toIso8601String(), 'rate_limit_hits' => 0,
            'bulk' => ['file_errors' => 0, 'identity_conflicts' => 0, 'unavailable_stocks' => 574]
                + $this->validatedDailyFiles(['NSE' => 'receipt_cached', 'BSE' => 'applied']),
        ]));
        SyncRun::query()->create([
            'id' => (string) Str::uuid(), 'job_name' => SyncLogService::JOB_UNIVERSE_PRICE_SYNC,
            'status' => 'failed', 'started_at' => now()->subMinute(), 'finished_at' => now()->timezone('Asia/Kolkata'),
            'stocks_processed' => 574, 'failures' => 574, 'summary' => 'validated source with row coverage gaps',
        ]);
        foreach ([SyncLogService::JOB_DAILY_MARKET_DATA, SyncLogService::JOB_STOCK_MASTER] as $jobName) {
            SyncRun::query()->create([
                'id' => (string) Str::uuid(), 'job_name' => $jobName, 'status' => 'success',
                'started_at' => now(), 'finished_at' => now()->timezone('Asia/Kolkata'),
            ]);
        }
        OperationalAlert::query()->create([
            'alert_key' => AdminOperationalAlertService::KEY_UNIVERSE_SYNC_FAILED,
            'severity' => 'critical', 'title' => 'Old partial coverage alert', 'message' => 'old',
            'context' => null, 'first_triggered_at' => now()->subDay(), 'last_triggered_at' => now()->subHour(),
        ]);
        $telegram = $this->createMock(TelegramNotificationService::class);
        $telegram->expects($this->never())->method('sendAdminOperationalAlert');
        $this->app->instance(TelegramNotificationService::class, $telegram);

        $result = app(AdminOperationalAlertService::class)->syncAndNotify();
        $this->assertContains(AdminOperationalAlertService::KEY_UNIVERSE_SYNC_FAILED, $result['resolved']);
        $this->assertNotContains(AdminOperationalAlertService::KEY_UNIVERSE_SYNC_FAILED, collect($result['active'])->pluck('key')->all());
    }

    public function test_persistent_exchange_file_failure_notifies_admin_telegram(): void
    {
        config(['portfolio.universe_price_sync.enabled' => true]);
        $nse = $this->validatedDailyFiles(['NSE' => 'applied']);
        $date = '2026-07-06';
        $nse['expected_exchanges'][] = 'BSE';
        $nse['expected_files'][] = ['exchange' => 'BSE', 'date' => $date];
        $nse['files'][] = ['exchange' => 'BSE', 'date' => $date, 'status' => 'failed', 'retry_attempt' => 1,
            'retry_after' => now()->addMinutes(5)->toIso8601String(),
            'failure_stage' => 'exchange_fetch', 'error' => 'temporary source transport failure'];
        $samples = [];
        $errors = ['BSE:2026-07-06: temporary source transport failure'];
        foreach (range(1, 5) as $index) {
            $symbol = 'BSETEST'.$index;
            $samples[] = ['symbol' => $symbol, 'exchange' => 'BSE', 'reason' => 'required_history_unavailable',
                'ranges' => [['from' => $date, 'to' => $date]]];
            $errors[] = $symbol.': required sessions remain unavailable after exchange bhavcopy collection';
        }
        Setting::setValue('universe_price_sync_last_run_json', json_encode([
            'collection_method' => 'exchange_bhavcopy', 'processed' => 10, 'succeeded' => 5, 'failed' => 5,
            'completed_at' => now()->toIso8601String(), 'rate_limit_hits' => 0,
            'errors' => $errors,
            'bulk' => ['file_errors' => 1, 'identity_conflicts' => 0, 'unavailable_stocks' => 5,
                'unavailable_samples' => $samples] + $nse,
        ]));
        SyncRun::query()->create([
            'id' => (string) Str::uuid(), 'job_name' => SyncLogService::JOB_UNIVERSE_PRICE_SYNC,
            'status' => 'partial', 'started_at' => now()->subMinute(), 'finished_at' => now()->timezone('Asia/Kolkata'),
            'stocks_processed' => 10, 'failures' => 5, 'summary' => 'BSE exchange file persistently failed',
        ]);
        $messages = [];
        $telegram = $this->createStub(TelegramNotificationService::class);
        $telegram->method('sendAdminOperationalAlert')
            ->willReturnCallback(function (string $message) use (&$messages): array {
                $messages[] = $message;
                return ['sent' => true, 'recipients' => 1];
            });
        $this->app->instance(TelegramNotificationService::class, $telegram);
        $firstResult = app(AdminOperationalAlertService::class)->syncAndNotify();
        $this->assertNotContains(AdminOperationalAlertService::KEY_UNIVERSE_SYNC_FAILED, collect($firstResult['active'])->pluck('key')->all());
        $this->assertNotContains(AdminOperationalAlertService::KEY_UNIVERSE_SYNC_FAILED, $firstResult['notified']);
        $this->assertFalse(collect($messages)->contains(fn (string $message) => str_contains($message, 'Universe daily bhavcopy sync failed')));

        $retryPayload = json_decode((string) Setting::getValue('universe_price_sync_last_run_json'), true);
        $retryPayload['bulk']['files'][1]['retry_attempt'] = 2;
        Setting::setValue('universe_price_sync_last_run_json', json_encode($retryPayload));
        $result = app(AdminOperationalAlertService::class)->syncAndNotify();
        $this->assertContains(AdminOperationalAlertService::KEY_UNIVERSE_SYNC_FAILED, $result['notified']);
        $this->assertTrue(collect($messages)->contains(fn (string $message) => str_contains($message, 'Universe daily bhavcopy sync failed')));

        $retryPayload['bulk']['files'][1]['retry_attempt'] = 4;
        Setting::setValue('universe_price_sync_last_run_json', json_encode($retryPayload));
        $result = app(AdminOperationalAlertService::class)->syncAndNotify();
        $this->assertContains(AdminOperationalAlertService::KEY_UNIVERSE_SYNC_FAILED, $result['notified']);
        $this->assertTrue(collect($messages)->contains(fn (string $message) => str_contains($message, 'Universe daily bhavcopy sync failed')));
    }

    public function test_weekend_does_not_use_maintenance_overdue_threshold_when_skipped(): void
    {
        config([
            'portfolio.universe_price_sync.enabled' => true,
            'portfolio.universe_price_sync.skip_weekends' => true,
            'portfolio.universe_price_sync.weekend_retry_on_prior_session_failures' => true,
        ]);

        // Saturday evening — Friday succeeded, so weekend maintenance is skipped.
        Carbon::setTestNow(Carbon::parse('2026-08-01 20:30:00', 'Asia/Kolkata'));
        SyncRun::query()->create([
            'id' => (string) Str::uuid(),
            'job_name' => SyncLogService::JOB_UNIVERSE_PRICE_SYNC,
            'status' => 'success',
            'started_at' => Carbon::parse('2026-07-31 20:00:00', 'Asia/Kolkata')->utc(),
            'finished_at' => Carbon::parse('2026-07-31 20:05:00', 'Asia/Kolkata')->utc(),
            'stocks_processed' => 125,
            'failures' => 0,
        ]);
        Setting::setValue('universe_price_sync_last_run_json', json_encode([
            'processed' => 125,
            'succeeded' => 125,
            'failed' => 0,
            'rate_limit_hits' => 0,
            'completed_at' => Carbon::parse('2026-07-31 20:05:00', 'Asia/Kolkata')->utc()->toIso8601String(),
        ]));
        SyncRun::query()->create([
            'id' => (string) Str::uuid(),
            'job_name' => SyncLogService::JOB_DAILY_MARKET_DATA,
            'status' => 'success',
            'started_at' => now()->subHours(10),
            'finished_at' => now()->subHours(10),
        ]);
        SyncRun::query()->create([
            'id' => (string) Str::uuid(),
            'job_name' => SyncLogService::JOB_STOCK_MASTER,
            'status' => 'success',
            'started_at' => now()->subDay(),
            'finished_at' => now()->subDay(),
        ]);

        $keys = collect(app(AdminOperationalAlertService::class)->evaluateConditions())
            ->pluck('key')
            ->all();

        // Closed session + Friday last batch succeeded → overdue is suppressed (not 26h wall-clock).
        $this->assertNotContains(AdminOperationalAlertService::KEY_UNIVERSE_SYNC_OVERDUE, $keys);
        $this->assertNotContains(AdminOperationalAlertService::KEY_DAILY_SYNC_OVERDUE, $keys);
    }

    public function test_sunday_does_not_fire_overdue_when_friday_runs_succeeded(): void
    {
        config([
            'portfolio.universe_price_sync.enabled' => true,
            'portfolio.universe_price_sync.skip_weekends' => true,
            'portfolio.universe_price_sync.weekend_retry_on_prior_session_failures' => true,
        ]);

        // Sunday noon — 26h/36h wall-clock from Friday would otherwise fire.
        Carbon::setTestNow(Carbon::parse('2026-08-16 12:00:00', 'Asia/Kolkata'));
        SyncRun::query()->create([
            'id' => (string) Str::uuid(),
            'job_name' => SyncLogService::JOB_UNIVERSE_PRICE_SYNC,
            'status' => 'success',
            'started_at' => Carbon::parse('2026-08-14 23:45:05', 'Asia/Kolkata')->utc(),
            'finished_at' => Carbon::parse('2026-08-14 23:45:55', 'Asia/Kolkata')->utc(),
            'stocks_processed' => 125,
            'failures' => 0,
        ]);
        Setting::setValue('universe_price_sync_last_run_json', json_encode([
            'processed' => 125,
            'succeeded' => 125,
            'failed' => 0,
            'rate_limit_hits' => 0,
            'completed_at' => Carbon::parse('2026-08-14 23:45:55', 'Asia/Kolkata')->utc()->toIso8601String(),
        ]));
        SyncRun::query()->create([
            'id' => (string) Str::uuid(),
            'job_name' => SyncLogService::JOB_DAILY_MARKET_DATA,
            'status' => 'success',
            'started_at' => Carbon::parse('2026-08-14 16:05:04', 'Asia/Kolkata')->utc(),
            'finished_at' => Carbon::parse('2026-08-14 16:05:13', 'Asia/Kolkata')->utc(),
        ]);
        SyncRun::query()->create([
            'id' => (string) Str::uuid(),
            'job_name' => SyncLogService::JOB_STOCK_MASTER,
            'status' => 'success',
            'started_at' => now()->subDay(),
            'finished_at' => now()->subDay(),
        ]);

        $keys = collect(app(AdminOperationalAlertService::class)->evaluateConditions())
            ->pluck('key')
            ->all();

        $this->assertNotContains(AdminOperationalAlertService::KEY_UNIVERSE_SYNC_OVERDUE, $keys);
        $this->assertNotContains(AdminOperationalAlertService::KEY_DAILY_SYNC_OVERDUE, $keys);
    }

    public function test_sunday_universe_overdue_fires_when_friday_last_batch_failed(): void
    {
        config([
            'portfolio.universe_price_sync.enabled' => true,
            'portfolio.universe_price_sync.skip_weekends' => true,
            'portfolio.universe_price_sync.weekend_retry_on_prior_session_failures' => true,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-16 12:00:00', 'Asia/Kolkata'));
        SyncRun::query()->create([
            'id' => (string) Str::uuid(),
            'job_name' => SyncLogService::JOB_UNIVERSE_PRICE_SYNC,
            'status' => 'partial',
            'started_at' => Carbon::parse('2026-08-14 23:45:05', 'Asia/Kolkata')->utc(),
            'finished_at' => Carbon::parse('2026-08-14 23:45:55', 'Asia/Kolkata')->utc(),
            'stocks_processed' => 125,
            'failures' => 40,
        ]);
        Setting::setValue('universe_price_sync_last_run_json', json_encode([
            'processed' => 125,
            'succeeded' => 85,
            'failed' => 40,
            'rate_limit_hits' => 0,
            'completed_at' => Carbon::parse('2026-08-14 23:45:55', 'Asia/Kolkata')->utc()->toIso8601String(),
        ]));
        SyncRun::query()->create([
            'id' => (string) Str::uuid(),
            'job_name' => SyncLogService::JOB_DAILY_MARKET_DATA,
            'status' => 'success',
            'started_at' => Carbon::parse('2026-08-14 16:05:04', 'Asia/Kolkata')->utc(),
            'finished_at' => Carbon::parse('2026-08-14 16:05:13', 'Asia/Kolkata')->utc(),
        ]);
        SyncRun::query()->create([
            'id' => (string) Str::uuid(),
            'job_name' => SyncLogService::JOB_STOCK_MASTER,
            'status' => 'success',
            'started_at' => now()->subDay(),
            'finished_at' => now()->subDay(),
        ]);

        $keys = collect(app(AdminOperationalAlertService::class)->evaluateConditions())
            ->pluck('key')
            ->all();

        $this->assertContains(AdminOperationalAlertService::KEY_UNIVERSE_SYNC_OVERDUE, $keys);
        $this->assertNotContains(AdminOperationalAlertService::KEY_DAILY_SYNC_OVERDUE, $keys);
    }

    public function test_sunday_daily_overdue_fires_when_friday_daily_failed(): void
    {
        config([
            'portfolio.universe_price_sync.enabled' => true,
            'portfolio.universe_price_sync.skip_weekends' => true,
            'portfolio.universe_price_sync.weekend_retry_on_prior_session_failures' => true,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-16 12:00:00', 'Asia/Kolkata'));
        SyncRun::query()->create([
            'id' => (string) Str::uuid(),
            'job_name' => SyncLogService::JOB_UNIVERSE_PRICE_SYNC,
            'status' => 'success',
            'started_at' => Carbon::parse('2026-08-14 23:45:05', 'Asia/Kolkata')->utc(),
            'finished_at' => Carbon::parse('2026-08-14 23:45:55', 'Asia/Kolkata')->utc(),
            'stocks_processed' => 125,
            'failures' => 0,
        ]);
        Setting::setValue('universe_price_sync_last_run_json', json_encode([
            'processed' => 125,
            'succeeded' => 125,
            'failed' => 0,
            'rate_limit_hits' => 0,
            'completed_at' => Carbon::parse('2026-08-14 23:45:55', 'Asia/Kolkata')->utc()->toIso8601String(),
        ]));
        SyncRun::query()->create([
            'id' => (string) Str::uuid(),
            'job_name' => SyncLogService::JOB_DAILY_MARKET_DATA,
            'status' => 'failed',
            'started_at' => Carbon::parse('2026-08-14 16:05:04', 'Asia/Kolkata')->utc(),
            'finished_at' => Carbon::parse('2026-08-14 16:05:13', 'Asia/Kolkata')->utc(),
            'failures' => 12,
        ]);
        SyncRun::query()->create([
            'id' => (string) Str::uuid(),
            'job_name' => SyncLogService::JOB_DAILY_MARKET_DATA,
            'status' => 'success',
            'started_at' => Carbon::parse('2026-08-13 16:05:04', 'Asia/Kolkata')->utc(),
            'finished_at' => Carbon::parse('2026-08-13 16:05:13', 'Asia/Kolkata')->utc(),
        ]);
        SyncRun::query()->create([
            'id' => (string) Str::uuid(),
            'job_name' => SyncLogService::JOB_STOCK_MASTER,
            'status' => 'success',
            'started_at' => now()->subDay(),
            'finished_at' => now()->subDay(),
        ]);

        $keys = collect(app(AdminOperationalAlertService::class)->evaluateConditions())
            ->pluck('key')
            ->all();

        $this->assertNotContains(AdminOperationalAlertService::KEY_UNIVERSE_SYNC_OVERDUE, $keys);
        $this->assertContains(AdminOperationalAlertService::KEY_DAILY_SYNC_OVERDUE, $keys);
        $this->assertContains(AdminOperationalAlertService::KEY_DAILY_SYNC_FAILED, $keys);
    }

    protected function seedHealthySyncState(): void
    {
        config(['portfolio.universe_price_sync.enabled' => false]);

        $finishedAt = now()->subHour();
        foreach ([SyncLogService::JOB_DAILY_MARKET_DATA, SyncLogService::JOB_STOCK_MASTER] as $job) {
            SyncRun::query()->create([
                'id' => (string) Str::uuid(),
                'job_name' => $job,
                'status' => 'success',
                'started_at' => $finishedAt->copy()->subMinutes(5),
                'finished_at' => $finishedAt,
            ]);
        }
    }

    protected function seedUniverseSyncState(Carbon $finishedAt): void
    {
        config(['portfolio.universe_price_sync.enabled' => true]);

        foreach ([SyncLogService::JOB_DAILY_MARKET_DATA, SyncLogService::JOB_STOCK_MASTER] as $job) {
            SyncRun::query()->create([
                'id' => (string) Str::uuid(),
                'job_name' => $job,
                'status' => 'success',
                'started_at' => $finishedAt->copy()->subMinutes(5),
                'finished_at' => $finishedAt,
            ]);
        }

        SyncRun::query()->create([
            'id' => (string) Str::uuid(),
            'job_name' => SyncLogService::JOB_UNIVERSE_PRICE_SYNC,
            'status' => 'success',
            'started_at' => $finishedAt->copy()->subMinutes(10),
            'finished_at' => $finishedAt,
        ]);
    }
}
