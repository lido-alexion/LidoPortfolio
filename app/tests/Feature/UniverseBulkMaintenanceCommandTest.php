<?php

namespace Tests\Feature;

use App\Services\AdminOperationalAlertService;
use App\Services\IndexPriceSyncService;
use App\Services\PriceHistoryGapService;
use App\Services\UniversePriceSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class UniverseBulkMaintenanceCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_failures_do_not_launch_automatic_per_stock_gap_fill_or_extra_retries(): void
    {
        config(['portfolio.universe_price_sync.maintenance_gap_fill_enabled' => true]);
        $sync = Mockery::mock(UniversePriceSyncService::class);
        $sync->shouldReceive('isEnabled')->andReturn(true);
        $sync->shouldReceive('isMaintenanceWindowDue')->andReturn(true);
        $sync->shouldReceive('isMaintenanceWindowStart')->andReturn(false);
        $sync->shouldReceive('isSyncInProgress')->andReturn(false);
        $sync->shouldReceive('maintenanceIntervalMinutes')->andReturn(5);
        $sync->shouldReceive('sync')->once()->andReturn([
            'collection_method' => 'exchange_bhavcopy', 'processed' => 1, 'succeeded' => 0,
            'failed' => 1, 'skipped' => 0, 'stored_rows' => 0, 'cache_hits' => 0,
            'rate_limit_hits' => 0, 'cursor_stock_id' => 0, 'cycle_completed' => true,
        ]);
        $this->app->instance(UniversePriceSyncService::class, $sync);
        $gaps = Mockery::mock(PriceHistoryGapService::class);
        $gaps->shouldNotReceive('fillBatch');
        $this->app->instance(PriceHistoryGapService::class, $gaps);
        $indexes = Mockery::mock(IndexPriceSyncService::class);
        $indexes->shouldReceive('isEnabled')->andReturn(false);
        $this->app->instance(IndexPriceSyncService::class, $indexes);
        $alerts = Mockery::mock(AdminOperationalAlertService::class);
        $alerts->shouldReceive('syncAndNotify')->once()->andReturn([]);
        $this->app->instance(AdminOperationalAlertService::class, $alerts);

        $this->artisan('portfolio:run-universe-maintenance', ['--gap-retries' => 3])->assertExitCode(1);
    }
}
