<?php

namespace Tests\Feature;

use App\Models\Stock;
use App\Models\User;
use App\Services\AdminOperationalAlertService;
use App\Services\BenchmarkPriceSyncService;
use App\Services\PriceFetchService;
use App\Services\StockMasterSyncService;
use App\Services\UniversePriceSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class UniversePriceSyncApiTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_non_admin_cannot_access_universe_price_sync_status(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)
            ->getJson('/api/universe-price-sync/status')
            ->assertForbidden();
    }

    public function test_admin_can_view_status(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        Stock::query()->create([
            'symbol' => 'INFY',
            'exchange' => 'NSE',
            'name' => 'Infosys',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        $response = $this->actingAs($admin)
            ->getJson('/api/universe-price-sync/status?scope=all_equities')
            ->assertOk();

        $response->assertJsonPath('data.scope', 'all_equities');
        $response->assertJsonPath('data.universe_count', 1);
        $response->assertJsonStructure([
            'data' => [
                'enabled',
                'in_progress',
                'universe_count',
                'stocks_with_prices',
                'progress_percent',
                'processed_through',
                'remaining_in_cycle',
                'rate_limits' => ['likely_rate_limited', 'recent_issues'],
            ],
        ]);
    }

    public function test_status_last_run_prefers_newer_sync_log_over_stale_settings(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        \App\Models\Setting::setValue(UniversePriceSyncService::KEY_LAST_RUN_JSON, json_encode([
            'mode' => 'daily',
            'scope' => 'all_equities',
            'processed' => 75,
            'succeeded' => 75,
            'failed' => 0,
            'stored_rows' => 0,
            'cache_hits' => 75,
            'rate_limit_hits' => 0,
            'completed_at' => '2026-07-07T04:15:18+05:30',
        ]));

        \App\Models\SyncRun::query()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'job_name' => \App\Services\SyncLogService::JOB_UNIVERSE_PRICE_SYNC,
            'status' => 'success',
            'started_at' => '2026-07-08 20:30:06',
            'finished_at' => '2026-07-08 20:30:37',
            'stocks_processed' => 75,
            'failures' => 0,
            'skipped' => 0,
            'summary' => 'Universe price sync (daily/all_equities): processed=75 ok=75 fail=0 stored=1 cache_hits=74',
        ]);

        $response = $this->actingAs($admin)
            ->getJson('/api/universe-price-sync/status')
            ->assertOk();

        $response->assertJsonPath('data.last_run.processed', 75);
        $response->assertJsonPath('data.last_run.stored_rows', 1);
        $this->assertStringContainsString(
            '2026-07-08',
            (string) $response->json('data.last_run.completed_at'),
        );
        $response->assertJsonPath('data.last_run.source', 'sync_log');
    }

    public function test_status_heals_cursor_and_last_batch_fields_from_sync_log_context(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $cursorStock = Stock::query()->create([
            'symbol' => 'GARWSYN',
            'exchange' => 'NSE',
            'name' => 'Garware Syn',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        \App\Models\Setting::setValue(UniversePriceSyncService::KEY_CURSOR_STOCK_ID, '100');
        \App\Models\Setting::setValue(UniversePriceSyncService::KEY_LAST_RUN_JSON, json_encode([
            'mode' => 'daily',
            'scope' => 'all_equities',
            'processed' => 75,
            'succeeded' => 75,
            'failed' => 0,
            'stored_rows' => 0,
            'cache_hits' => 75,
            'rate_limit_hits' => 0,
            'cursor_stock_id' => 100,
            'completed_at' => '2026-07-07T04:15:18+05:30',
        ]));

        $runId = (string) \Illuminate\Support\Str::uuid();
        \App\Models\SyncRun::query()->create([
            'id' => $runId,
            'job_name' => \App\Services\SyncLogService::JOB_UNIVERSE_PRICE_SYNC,
            'status' => 'success',
            'started_at' => '2026-07-08 22:40:04',
            'finished_at' => '2026-07-08 22:41:07',
            'stocks_processed' => 125,
            'failures' => 0,
            'skipped' => 0,
            'summary' => 'Universe price sync (daily/all_equities): processed=125 ok=125 fail=0 stored=7 cache_hits=112',
        ]);

        \App\Models\SyncLog::query()->create([
            'run_id' => $runId,
            'job_name' => \App\Services\SyncLogService::JOB_UNIVERSE_PRICE_SYNC,
            'level' => 'info',
            'message' => 'Universe price sync completed',
            'context' => [
                'scope' => 'all_equities',
                'mode' => 'daily',
                'universe_count' => 6971,
                'processed' => 125,
                'succeeded' => 125,
                'failed' => 0,
                'stored_rows' => 7,
                'cache_hits' => 112,
                'rate_limit_hits' => 0,
                'cycle_completed' => false,
                'cursor_stock_id' => $cursorStock->id,
                'completed_at' => '2026-07-08T17:11:07+00:00',
                'failure_rate_percent' => 0.0,
                'errors' => [],
            ],
            'logged_at' => '2026-07-08 22:41:07',
        ]);

        $response = $this->actingAs($admin)
            ->getJson('/api/universe-price-sync/status?scope=all_equities')
            ->assertOk();

        $response->assertJsonPath('data.cursor_stock_id', $cursorStock->id);
        $response->assertJsonPath('data.cursor_symbol', 'GARWSYN');
        $response->assertJsonPath('data.last_run.processed', 125);
        $response->assertJsonPath('data.last_run.stored_rows', 7);
        $response->assertJsonPath('data.last_run.cache_hits', 112);
        $response->assertJsonPath('data.last_run.cursor_stock_id', $cursorStock->id);
        $response->assertJsonPath('data.latest_sync_run.stocks_processed', 125);
        $response->assertJsonPath('data.latest_sync_run.run_id', $runId);
        $response->assertJsonPath('data.last_run.succeeded', 125);
        $response->assertJsonPath('data.last_run.rate_limit_hits', 0);
        $response->assertJsonPath('data.rate_limits.last_run_hits', 0);
        $this->assertSame(
            (string) $cursorStock->id,
            \App\Models\Setting::getValue(UniversePriceSyncService::KEY_CURSOR_STOCK_ID),
        );
    }

    public function test_status_heals_last_full_cycle_from_sync_log_context(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        \App\Models\Setting::setValue(
            UniversePriceSyncService::KEY_LAST_CYCLE_COMPLETED_AT,
            '2026-07-06T22:45:18+00:00',
        );

        $runId = (string) \Illuminate\Support\Str::uuid();
        \App\Models\SyncRun::query()->create([
            'id' => $runId,
            'job_name' => \App\Services\SyncLogService::JOB_UNIVERSE_PRICE_SYNC,
            'status' => 'success',
            'started_at' => '2026-07-08 22:45:04',
            'finished_at' => '2026-07-08 22:46:07',
            'stocks_processed' => 125,
            'failures' => 0,
            'summary' => 'Universe price sync (daily/all_equities): processed=125 ok=125 fail=0 stored=3 cache_hits=120',
        ]);

        \App\Models\SyncLog::query()->create([
            'run_id' => $runId,
            'job_name' => \App\Services\SyncLogService::JOB_UNIVERSE_PRICE_SYNC,
            'level' => 'info',
            'message' => 'Universe price sync completed',
            'context' => [
                'scope' => 'all_equities',
                'mode' => 'daily',
                'processed' => 125,
                'succeeded' => 125,
                'failed' => 0,
                'cycle_completed' => true,
                'cursor_stock_id' => 0,
                'completed_at' => '2026-07-08T17:16:07+00:00',
            ],
            'logged_at' => '2026-07-08 22:46:07',
        ]);

        $response = $this->actingAs($admin)
            ->getJson('/api/universe-price-sync/status?scope=all_equities')
            ->assertOk();

        $this->assertStringContainsString(
            '2026-07-08',
            (string) $response->json('data.last_cycle_completed_at'),
        );
        $this->assertStringContainsString(
            '2026-07-08',
            (string) \App\Models\Setting::getValue(UniversePriceSyncService::KEY_LAST_CYCLE_COMPLETED_AT),
        );
        $response->assertJsonPath('data.last_run.cycle_completed', true);
    }

    public function test_admin_can_trigger_daily_batch(): void
    {
        config(['portfolio.universe_price_sync.delay_ms_between_stocks' => 0]);

        \App\Models\Setting::setValue(
            \App\Services\BenchmarkPriceSyncService::KEY_LAST_SYNC_DATE,
            now()->toDateString(),
        );

        $admin = User::factory()->create(['is_admin' => true]);

        Stock::query()->create([
            'symbol' => 'TCS',
            'exchange' => 'NSE',
            'name' => 'TCS',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        $priceFetch = Mockery::mock(PriceFetchService::class);
        $priceFetch->shouldReceive('syncStock')
            ->once()
            ->andReturn([
                'success' => true,
                'stored_rows' => 2,
                'cache_hit' => false,
                'errors' => [],
            ]);
        $this->app->instance(PriceFetchService::class, $priceFetch);

        $benchmark = Mockery::mock(BenchmarkPriceSyncService::class);
        $benchmark->shouldReceive('syncIfNeeded')
            ->once()
            ->andReturn(['skipped' => true, 'success' => true]);
        $this->app->instance(BenchmarkPriceSyncService::class, $benchmark);

        $response = $this->actingAs($admin)
            ->postJson('/api/universe-price-sync/run', [
                'mode' => 'daily',
                'scope' => 'all_equities',
            ])
            ->assertOk();

        $response->assertJsonPath('data.run.processed', 1);
        $response->assertJsonPath('data.run.succeeded', 1);
    }

    public function test_run_returns_conflict_when_already_in_progress(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        app(UniversePriceSyncService::class)->markInProgress();

        $this->actingAs($admin)
            ->postJson('/api/universe-price-sync/run', ['mode' => 'daily'])
            ->assertStatus(409);
    }

    public function test_admin_can_trigger_stock_master_sync(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $master = Mockery::mock(StockMasterSyncService::class);
        $master->shouldReceive('syncStockMaster')
            ->once()
            ->with(false)
            ->andReturn([
            'added' => 1,
            'updated' => 0,
            'deactivated' => 0,
            'skipped' => 0,
            'source' => 'nse',
        ]);
        $this->app->instance(StockMasterSyncService::class, $master);

        $opsAlerts = Mockery::mock(AdminOperationalAlertService::class);
        $opsAlerts->shouldReceive('syncAndNotify')->once()->andReturn([
            'active' => [],
            'notified' => [],
            'resolved' => [],
        ]);
        $this->app->instance(AdminOperationalAlertService::class, $opsAlerts);

        $this->actingAs($admin)
            ->postJson('/api/universe-price-sync/stock-master')
            ->assertOk()
            ->assertJsonPath('data.added', 1);
    }

    public function test_ui_stock_master_failure_triggers_operational_alert_telegram_sync(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $master = Mockery::mock(StockMasterSyncService::class);
        $master->shouldReceive('syncStockMaster')
            ->once()
            ->andThrow(new \RuntimeException('BSE URL missing'));
        $this->app->instance(StockMasterSyncService::class, $master);

        $opsAlerts = Mockery::mock(AdminOperationalAlertService::class);
        $opsAlerts->shouldReceive('syncAndNotify')->once()->andReturn([
            'active' => [],
            'notified' => ['stock_master_failed'],
            'resolved' => [],
        ]);
        $this->app->instance(AdminOperationalAlertService::class, $opsAlerts);

        $this->actingAs($admin)
            ->postJson('/api/universe-price-sync/stock-master')
            ->assertStatus(500)
            ->assertJsonFragment(['message' => 'Stock master sync failed: BSE URL missing']);
    }

    public function test_rate_limit_detection_flags_403_errors(): void
    {
        $this->assertTrue(
            UniversePriceSyncService::looksLikeRateLimit('nse(attempt 1): HTTP 403 forbidden'),
        );
        $this->assertFalse(
            UniversePriceSyncService::looksLikeRateLimit('symbol not found'),
        );
    }

    public function test_admin_can_view_index_status(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)
            ->getJson('/api/universe-price-sync/indexes/status')
            ->assertOk();

        $response->assertJsonPath('data.primary_symbol', 'NIFTY50');
        $response->assertJsonStructure([
            'data' => [
                'enabled',
                'index_count',
                'batch_size',
                'history_days',
                'indexes',
            ],
        ]);
        $this->assertGreaterThanOrEqual(25, $response->json('data.index_count'));
    }

    public function test_admin_can_reset_index_cursor(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        \App\Models\Setting::setValue(\App\Services\IndexPriceSyncService::KEY_CURSOR_SYMBOL, 'NIFTYBANK');

        $this->actingAs($admin)
            ->postJson('/api/universe-price-sync/indexes/reset-cursor')
            ->assertOk()
            ->assertJsonPath('data.cursor_symbol', null);

        $this->assertSame('', (string) \App\Models\Setting::getValue(\App\Services\IndexPriceSyncService::KEY_CURSOR_SYMBOL, ''));
    }
}
