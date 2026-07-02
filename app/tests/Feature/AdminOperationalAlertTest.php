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

    public function test_scheduler_inactive_alert_is_created(): void
    {
        $keys = collect(app(AdminOperationalAlertService::class)->evaluateConditions())
            ->pluck('key')
            ->all();

        $this->assertContains(AdminOperationalAlertService::KEY_SCHEDULER_INACTIVE, $keys);
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
            ->getJson('/api/universe-price-sync/status?scope=all_nse')
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
}
