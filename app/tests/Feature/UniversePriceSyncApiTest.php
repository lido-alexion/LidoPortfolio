<?php

namespace Tests\Feature;

use App\Models\Stock;
use App\Models\User;
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
            ->getJson('/api/universe-price-sync/status?scope=all_nse')
            ->assertOk();

        $response->assertJsonPath('data.scope', 'all_nse');
        $response->assertJsonPath('data.universe_count', 1);
        $response->assertJsonStructure([
            'data' => [
                'enabled',
                'in_progress',
                'universe_count',
                'stocks_with_prices',
                'progress_percent',
                'rate_limits' => ['likely_rate_limited', 'recent_issues'],
            ],
        ]);
    }

    public function test_admin_can_trigger_daily_batch(): void
    {
        config(['portfolio.universe_price_sync.delay_ms_between_stocks' => 0]);

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

        $response = $this->actingAs($admin)
            ->postJson('/api/universe-price-sync/run', [
                'mode' => 'daily',
                'scope' => 'all_nse',
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
        $master->shouldReceive('syncStockMaster')->once()->andReturn([
            'added' => 1,
            'updated' => 0,
            'deactivated' => 0,
            'skipped' => 0,
            'source' => 'nse',
        ]);
        $this->app->instance(StockMasterSyncService::class, $master);

        $this->actingAs($admin)
            ->postJson('/api/universe-price-sync/stock-master')
            ->assertOk()
            ->assertJsonPath('data.added', 1);
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
}
