<?php

namespace Tests\Feature;

use App\Models\Stock;
use App\Services\PriceFetchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SyncUniversePricesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_command_runs_daily_batch_for_active_nse_stocks(): void
    {
        config([
            'portfolio.universe_price_sync.enabled' => true,
            'portfolio.universe_price_sync.delay_ms_between_stocks' => 0,
        ]);

        // Benchmark sync compares dates in cron_timezone (Asia/Kolkata); seeding
        // with the UTC date left the guard open between midnight and 05:30 IST,
        // so syncStock was called an extra time for the benchmark symbol.
        \App\Models\Setting::setValue(
            \App\Services\BenchmarkPriceSyncService::KEY_LAST_SYNC_DATE,
            \Carbon\Carbon::now(app(\App\Services\SettingsService::class)->get('cron_timezone', 'Asia/Kolkata'))->toDateString(),
        );

        Stock::query()->create([
            'symbol' => 'INFY',
            'exchange' => 'NSE',
            'name' => 'Infosys',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        $priceFetch = Mockery::mock(PriceFetchService::class);
        $priceFetch->shouldReceive('syncStock')
            ->once()
            ->andReturn([
                'success' => true,
                'stored_rows' => 3,
                'cache_hit' => true,
                'errors' => [],
            ]);
        $this->app->instance(PriceFetchService::class, $priceFetch);

        $this->artisan('portfolio:sync-universe-prices', ['--mode' => 'daily'])
            ->assertSuccessful();
    }
}
