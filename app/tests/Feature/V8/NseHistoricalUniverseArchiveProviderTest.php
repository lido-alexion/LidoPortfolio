<?php

namespace Tests\Feature\V8;

use App\Exceptions\MlHistoricalUniverseProviderException;
use App\Models\Stock;
use App\Models\V8\MlUniverseSnapshotBackfillRun;
use App\Models\V8\MlUniverseSnapshotBoundary;
use App\Services\ML\MlHistoricalUniverseMembershipService;
use App\Services\ML\NseHistoricalUniverseArchiveProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class NseHistoricalUniverseArchiveProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_bhavcopy_parser_filters_series_and_funds(): void
    {
        $result = app(NseHistoricalUniverseArchiveProvider::class)->parseLegacyBhavcopy("SYMBOL,SERIES,ISIN\nRENAME,EQ,INE000000001\nETF,EQ,INF000000002\nFUT,XX,INE000000003\nBOND,BE,INE000000004\n");
        $this->assertSame('legacy-bhavcopy', $result['format_version']);
        $this->assertSame(['RENAME', 'BOND'], array_column($result['members'], 'symbol'));
    }

    public function test_udiff_parser_accepts_udiff_headers(): void
    {
        $result = app(NseHistoricalUniverseArchiveProvider::class)->parseUdiff("TradDt,FinInstrmId,TckrSymb,SctySrs,ISIN\n20240830,1,ABC,EQ,INE000000001\n20240830,2,FUND,EQ,INF000000002\n");
        $this->assertSame('udiff', $result['format_version']);
        $this->assertSame(['ABC'], array_column($result['members'], 'symbol'));
    }

    public function test_provider_maps_renamed_security_by_isin_and_records_quality(): void
    {
        $stock = Stock::query()->create(['symbol' => 'NEWNAME', 'exchange' => 'NSE', 'isin' => 'INE000000001', 'name' => 'Renamed Co']);
        $path = storage_path('framework/testing/nse-'.bin2hex(random_bytes(4)).'.csv');
        File::put($path, "SYMBOL,SERIES,ISIN\nOLDNAME,EQ,INE000000001\n");
        config(['ml.historical_universe.bhavcopy_path' => $path, 'ml.historical_universe.mii_path' => '']);

        $snapshot = app(NseHistoricalUniverseArchiveProvider::class)->snapshotForDate('2024-08-30');
        $this->assertSame($stock->id, $snapshot['memberships'][0]['stock_id']);
        $this->assertSame(100.0, $snapshot['diagnostics']['mapping_percentage']);
        $this->assertSame('OLDNAME', $snapshot['memberships'][0]['provider_symbol']);
        File::delete($path);
    }

    public function test_mii_file_is_preferred_over_cash_bhavcopy(): void
    {
        Stock::query()->create(['symbol' => 'MIISEC', 'exchange' => 'NSE', 'isin' => 'INE000000001', 'name' => 'MII']);
        $mii = storage_path('framework/testing/mii-'.bin2hex(random_bytes(4)).'.csv');
        $bhav = storage_path('framework/testing/bhav-'.bin2hex(random_bytes(4)).'.csv');
        File::put($mii, "SYMBOL,SERIES,ISIN\nMIISEC,EQ,INE000000001\n");
        File::put($bhav, "SYMBOL,SERIES,ISIN\nUNKNOWN,EQ,INE000000002\n");
        config(['ml.historical_universe.mii_path' => $mii, 'ml.historical_universe.bhavcopy_path' => $bhav]);
        $snapshot = app(NseHistoricalUniverseArchiveProvider::class)->snapshotForDate('2024-08-30');
        $this->assertSame('nse_mii_security_file', $snapshot['source']);
        File::delete([$mii, $bhav]);
    }

    public function test_provider_rejects_mapping_below_ninety_percent_without_materializing(): void
    {
        Stock::query()->create(['symbol' => 'KNOWN', 'exchange' => 'NSE', 'isin' => 'INE000000001', 'name' => 'Known']);
        $path = storage_path('framework/testing/nse-low-'.bin2hex(random_bytes(4)).'.csv');
        File::put($path, "SYMBOL,SERIES,ISIN\nKNOWN,EQ,INE000000001\nUNKNOWN1,EQ,INE000000002\nUNKNOWN2,EQ,INE000000003\nUNKNOWN3,EQ,INE000000004\nUNKNOWN4,EQ,INE000000005\nUNKNOWN5,EQ,INE000000006\nUNKNOWN6,EQ,INE000000007\nUNKNOWN7,EQ,INE000000008\nUNKNOWN8,EQ,INE000000009\nUNKNOWN9,EQ,INE000000010\nUNKNOWN10,EQ,INE000000011\n");
        config(['ml.historical_universe.bhavcopy_path' => $path, 'ml.historical_universe.mii_path' => '']);
        try {
            app(NseHistoricalUniverseArchiveProvider::class)->snapshotForDate('2024-08-30');
            $this->fail('Expected mapping floor rejection.');
        } catch (MlHistoricalUniverseProviderException $exception) {
            $this->assertStringContainsString('below 90%', $exception->getMessage());
            $this->assertSame(10, $exception->diagnostics['unmapped_count']);
        }
        $this->assertDatabaseCount('stox_ml_universe_snapshot_boundaries', 0);
        File::delete($path);
    }

    public function test_missing_date_does_not_fallback_to_current_stock_master(): void
    {
        Stock::query()->create(['symbol' => 'CURRENT', 'exchange' => 'NSE', 'name' => 'Current', 'is_active' => true]);
        config(['ml.historical_universe.bhavcopy_path' => storage_path('framework/testing/does-not-exist')]);
        $result = app(MlHistoricalUniverseMembershipService::class)->backfillFromProvider(
            ['2020-01-01'], app(NseHistoricalUniverseArchiveProvider::class), 'nse-test', 1,
        );
        $this->assertSame('failed', $result['status']);
        $this->assertSame([], app(MlHistoricalUniverseMembershipService::class)->stockIdsForDate('2020-01-01'));
    }

    public function test_backfill_is_idempotent_and_resumable(): void
    {
        $path = storage_path('framework/testing/nse-resume-'.bin2hex(random_bytes(4)).'.csv');
        Stock::query()->create(['symbol' => 'RESUME', 'exchange' => 'NSE', 'isin' => 'INE000000001', 'name' => 'Resume']);
        config(['ml.historical_universe.bhavcopy_path' => $path, 'ml.historical_universe.mii_path' => '']);
        File::put($path, "SYMBOL,SERIES,ISIN\nRESUME,EQ,INE000000001\n");
        $service = app(MlHistoricalUniverseMembershipService::class);
        $first = $service->backfillFromProvider(['2020-01-01'], app(NseHistoricalUniverseArchiveProvider::class), 'nse-test', 1);
        $second = $service->backfillFromProvider(['2020-01-01'], app(NseHistoricalUniverseArchiveProvider::class), 'nse-test', 1, MlHistoricalUniverseMembershipService::ACTIVE_ELIGIBLE_NSE, $first['run_id']);
        $this->assertSame('completed', $second['status']);
        $this->assertSame(1, MlUniverseSnapshotBoundary::query()->count());
        $this->assertEquals(100.0, MlUniverseSnapshotBoundary::query()->firstOrFail()->quality_diagnostics['mapping_percentage']);
        $this->assertSame(1, MlUniverseSnapshotBackfillRun::query()->count());
        File::delete($path);
    }
}
