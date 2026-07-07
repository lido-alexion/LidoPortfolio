<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\SyncLog;
use App\Models\SyncRun;
use App\Models\User;
use App\Services\SyncLogService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class SyncLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-06-21 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_retention_prunes_old_runs_and_logs(): void
    {
        Setting::setValue('sync_log_retention_days', '7');

        $oldRun = SyncRun::query()->create([
            'id' => (string) Str::uuid(),
            'job_name' => SyncLogService::JOB_DAILY_MARKET_DATA,
            'status' => 'success',
            'started_at' => now()->subDays(10),
            'finished_at' => now()->subDays(10),
        ]);
        SyncLog::query()->create([
            'run_id' => $oldRun->id,
            'job_name' => $oldRun->job_name,
            'level' => 'info',
            'message' => 'old entry',
            'logged_at' => now()->subDays(10),
        ]);

        $recentRun = SyncRun::query()->create([
            'id' => (string) Str::uuid(),
            'job_name' => SyncLogService::JOB_DAILY_MARKET_DATA,
            'status' => 'success',
            'started_at' => now()->subDay(),
            'finished_at' => now()->subDay(),
        ]);
        SyncLog::query()->create([
            'run_id' => $recentRun->id,
            'job_name' => $recentRun->job_name,
            'level' => 'info',
            'message' => 'recent entry',
            'logged_at' => now()->subDay(),
        ]);

        $deleted = app(SyncLogService::class)->prune();

        $this->assertSame(1, $deleted);
        $this->assertDatabaseMissing('portfolio_sync_runs', ['id' => $oldRun->id]);
        $this->assertDatabaseHas('portfolio_sync_runs', ['id' => $recentRun->id]);
        $this->assertDatabaseMissing('portfolio_sync_logs', ['message' => 'old entry']);
        $this->assertDatabaseHas('portfolio_sync_logs', ['message' => 'recent entry']);
    }

    public function test_retention_zero_disables_database_writes(): void
    {
        Setting::setValue('sync_log_retention_days', '0');

        $service = app(SyncLogService::class);
        $runId = $service->beginRun(SyncLogService::JOB_DAILY_MARKET_DATA);

        $this->assertNull($runId);
        $service->log(null, SyncLogService::JOB_DAILY_MARKET_DATA, 'info', 'should not persist');

        $this->assertDatabaseCount('portfolio_sync_runs', 0);
        $this->assertDatabaseCount('portfolio_sync_logs', 0);
    }

    public function test_begin_run_requires_logs_table(): void
    {
        Setting::setValue('sync_log_retention_days', '7');
        Schema::dropIfExists('portfolio_sync_logs');

        $service = app(SyncLogService::class);
        $runId = $service->beginRun(SyncLogService::JOB_DAILY_MARKET_DATA);

        $this->assertNull($runId);
        $this->assertDatabaseCount('portfolio_sync_runs', 0);
    }

    public function test_runs_api_includes_log_line_counts(): void
    {
        $user = User::query()->create([
            'name' => 'Runs User',
            'email' => 'runs-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $user->is_admin = true;
        $user->save();

        $run = SyncRun::query()->create([
            'id' => (string) Str::uuid(),
            'job_name' => SyncLogService::JOB_DAILY_MARKET_DATA,
            'status' => 'success',
            'started_at' => now(),
            'finished_at' => now(),
            'stocks_processed' => 13,
            'failures' => 0,
        ]);

        SyncLog::query()->create([
            'run_id' => $run->id,
            'job_name' => $run->job_name,
            'level' => 'info',
            'message' => 'Daily market data job completed',
            'logged_at' => now(),
        ]);

        $response = $this->actingAs($user)->getJson('/api/sync-logs/runs');

        $response->assertOk()
            ->assertJsonPath('data.0.run_id', $run->id)
            ->assertJsonPath('data.0.log_lines', 1)
            ->assertJsonPath('data.0.stocks_processed', 13)
            ->assertJsonPath('meta.cron_timezone', 'Asia/Kolkata');
    }

    public function test_runs_api_supports_pagination_and_date_filters(): void
    {
        Setting::setValue('cron_timezone', 'Asia/Kolkata');

        $user = User::query()->create([
            'name' => 'Runs User',
            'email' => 'runs-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $user->is_admin = true;
        $user->save();

        for ($i = 0; $i < 25; $i++) {
            SyncRun::query()->create([
                'id' => (string) Str::uuid(),
                'job_name' => SyncLogService::JOB_UNIVERSE_PRICE_SYNC,
                'status' => 'success',
                'started_at' => now()->subHours($i),
                'finished_at' => now()->subHours($i),
            ]);
        }

        $this->actingAs($user)
            ->getJson('/api/sync-logs/runs?per_page=20&page=1')
            ->assertOk()
            ->assertJsonPath('total', 25)
            ->assertJsonPath('last_page', 2)
            ->assertJsonCount(20, 'data');

        $this->actingAs($user)
            ->getJson('/api/sync-logs/runs?per_page=20&page=2')
            ->assertOk()
            ->assertJsonCount(5, 'data');
    }

    public function test_sync_logs_api_includes_scheduler_timezone_meta(): void
    {
        Setting::setValue('cron_timezone', 'UTC');

        $user = User::query()->create([
            'name' => 'Logs User',
            'email' => 'logs-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $user->is_admin = true;
        $user->save();

        $this->actingAs($user)
            ->getJson('/api/sync-logs?per_page=1')
            ->assertOk()
            ->assertJsonPath('meta.cron_timezone', 'UTC');
    }

    public function test_sync_logs_api_filters_by_level_and_search(): void
    {
        $user = User::query()->create([
            'name' => 'Logs User',
            'email' => 'logs-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $user->is_admin = true;
        $user->save();

        $run = SyncRun::query()->create([
            'id' => (string) Str::uuid(),
            'job_name' => SyncLogService::JOB_DAILY_MARKET_DATA,
            'status' => 'success',
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        SyncLog::query()->create([
            'run_id' => $run->id,
            'job_name' => $run->job_name,
            'level' => 'info',
            'message' => 'Daily market data job completed',
            'context' => ['symbol' => 'INFY'],
            'logged_at' => now(),
        ]);
        SyncLog::query()->create([
            'run_id' => $run->id,
            'job_name' => $run->job_name,
            'level' => 'error',
            'message' => 'Stock sync failed',
            'context' => ['symbol' => 'TCS'],
            'logged_at' => now()->subMinute(),
        ]);

        $response = $this->actingAs($user)->getJson('/api/sync-logs?level=error&search=TCS');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.message', 'Stock sync failed');
    }

    public function test_sync_logs_export_returns_csv_with_filters(): void
    {
        $user = User::query()->create([
            'name' => 'Export User',
            'email' => 'export-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $user->is_admin = true;
        $user->save();

        $run = SyncRun::query()->create([
            'id' => (string) Str::uuid(),
            'job_name' => SyncLogService::JOB_STOCK_MASTER,
            'status' => 'success',
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        SyncLog::query()->create([
            'run_id' => $run->id,
            'job_name' => $run->job_name,
            'level' => 'info',
            'message' => 'Stock master sync completed',
            'logged_at' => now(),
        ]);
        SyncLog::query()->create([
            'run_id' => $run->id,
            'job_name' => SyncLogService::JOB_DAILY_MARKET_DATA,
            'level' => 'info',
            'message' => 'other job message',
            'logged_at' => now(),
        ]);

        $response = $this->actingAs($user)->get('/api/sync-logs/export?job_name=stock-master');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $content = $response->streamedContent();
        $this->assertStringContainsString('Stock master sync completed', $content);
        $this->assertStringNotContainsString('other job message', $content);
    }

    public function test_settings_include_latest_sync_run_summaries(): void
    {
        $user = User::query()->create([
            'name' => 'Settings User',
            'email' => 'settings-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $user->is_admin = true;
        $user->save();

        SyncRun::query()->create([
            'id' => (string) Str::uuid(),
            'job_name' => SyncLogService::JOB_DAILY_MARKET_DATA,
            'status' => 'partial',
            'started_at' => now()->subHour(),
            'finished_at' => now()->subMinutes(30),
            'stocks_processed' => 5,
            'failures' => 1,
            'summary' => 'One failure',
        ]);

        $response = $this->actingAs($user)->getJson('/api/settings');

        $response->assertOk()
            ->assertJsonPath('data.sync_log_retention_days', '7')
            ->assertJsonPath('data.sync_log_latest_runs.daily_market_data.status', 'partial')
            ->assertJsonPath('data.sync_log_latest_runs.daily_market_data.summary', 'One failure');
    }
}
