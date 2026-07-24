<?php

namespace Tests\Unit;

use App\Models\Setting;
use App\Services\BenchmarkPriceSyncService;
use App\Services\IndexCatalogService;
use App\Services\IndexPriceSyncService;
use App\Services\PortfolioLoggerService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class BenchmarkPriceSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_skips_when_already_synced_today(): void
    {
        // The service compares dates in cron_timezone (Asia/Kolkata), not the app
        // timezone — seeding with plain now() made this fail between midnight and
        // 05:30 IST when the UTC date is still the previous day.
        $today = \Carbon\Carbon::now(app(SettingsService::class)->get('cron_timezone', 'Asia/Kolkata'))->toDateString();
        Setting::setValue(BenchmarkPriceSyncService::KEY_LAST_SYNC_DATE, $today);

        $indexSync = Mockery::mock(IndexPriceSyncService::class);
        $indexSync->shouldNotReceive('syncOneSymbol');

        $service = new BenchmarkPriceSyncService(
            $indexSync,
            app(IndexCatalogService::class),
            app(SettingsService::class),
            Mockery::mock(PortfolioLoggerService::class),
        );

        $result = $service->syncIfNeeded();

        $this->assertTrue($result['skipped']);
        $this->assertTrue($result['success']);
    }

    public function test_force_syncs_primary_via_index_service(): void
    {
        $indexSync = Mockery::mock(IndexPriceSyncService::class);
        $indexSync->shouldReceive('syncOneSymbol')
            ->once()
            ->with('NIFTY50', 'daily')
            ->andReturn([
                'success' => true,
                'stored_rows' => 12,
                'fetched_rows' => 12,
                'full_history' => false,
                'from_date' => now()->subDays(14)->toDateString(),
                'to_date' => now()->toDateString(),
                'errors' => [],
            ]);

        $logger = Mockery::mock(PortfolioLoggerService::class);
        $logger->shouldReceive('scheduler')->once();

        $service = new BenchmarkPriceSyncService(
            $indexSync,
            app(IndexCatalogService::class),
            app(SettingsService::class),
            $logger,
        );

        $result = $service->syncIfNeeded(force: true);

        $this->assertFalse($result['skipped']);
        $this->assertTrue($result['success']);
        $this->assertSame(12, $result['stored_rows']);
        $cronToday = \Carbon\Carbon::now(app(SettingsService::class)->get('cron_timezone', 'Asia/Kolkata'))->toDateString();
        $this->assertSame($cronToday, Setting::getValue(BenchmarkPriceSyncService::KEY_LAST_SYNC_DATE));
    }
}
