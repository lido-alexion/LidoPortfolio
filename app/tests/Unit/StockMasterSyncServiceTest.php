<?php

namespace Tests\Unit;

use App\Models\Stock;
use App\Services\ProviderResolverService;
use App\Services\SyncLogService;
use App\Services\StockMasterSyncService;
use App\Services\BseEquityMasterService;
use App\Services\DualListedNseRepairService;
use App\Services\PriceFetchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class StockMasterSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_parse_nse_equity_csv_prefers_eq_over_be_for_same_isin(): void
    {
        $csv = implode("\n", [
            'SYMBOL,NAME OF COMPANY,SERIES,DATE OF LISTING,ISIN NUMBER',
            'INFY,Infosys Limited,EQ,01-01-2000,INE009A01021',
            'INFY,Infosys Limited,BE,01-01-2000,INE009A01021',
        ]);

        $service = $this->makeService();
        $rows = $service->parseNseEquityCsv($csv);

        $this->assertCount(1, $rows);
        $this->assertSame('INFY', $rows[0]['symbol']);
        $this->assertSame('EQ', $rows[0]['series']);
    }

    public function test_parse_nse_equity_csv_includes_be_only_isin(): void
    {
        $csv = implode("\n", [
            'SYMBOL,NAME OF COMPANY,SERIES,DATE OF LISTING,ISIN NUMBER',
            'TOKYOPLAST,Tokyo Plast International Limited,BE,11-OCT-1995,INE932C01012',
        ]);

        $service = $this->makeService();
        $rows = $service->parseNseEquityCsv($csv);

        $this->assertCount(1, $rows);
        $this->assertSame('TOKYOPLAST', $rows[0]['symbol']);
        $this->assertSame('BE', $rows[0]['series']);
        $this->assertSame('INE932C01012', $rows[0]['isin']);
    }

    public function test_upsert_preserves_id_and_deactivates_missing(): void
    {
        $existing = Stock::query()->create([
            'symbol' => 'OLD',
            'exchange' => 'NSE',
            'name' => 'Old Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        $service = $this->makeService();
        $csv = "SYMBOL,NAME OF COMPANY,SERIES,ISIN NUMBER\nNEW,New Stock,EQ,INE000000001\n";
        $rows = $service->parseNseEquityCsv($csv);

        foreach ($rows as $row) {
            $normalized = (new ProviderResolverService)->normalizeSymbol($row['symbol'], 'NSE');
            $reflection = new \ReflectionClass($service);
            $method = $reflection->getMethod('upsertMasterRow');
            $method->setAccessible(true);
            $method->invoke(
                $service,
                $normalized['symbol'],
                $normalized['exchange'],
                $row['name'],
                $row['isin'],
                $row['series'],
            );
        }

        $reflection = new \ReflectionClass($service);
        $deactivate = $reflection->getMethod('deactivateMissing');
        $deactivate->setAccessible(true);
        $deactivate->invoke($service, ['NEW|NSE'], 'NSE');

        $this->assertFalse($existing->fresh()->is_active);
        $created = Stock::query()->where('symbol', 'NEW')->first();
        $this->assertTrue($created->is_active);
        $this->assertSame('EQ', $created->series);
    }

    public function test_upsert_persists_be_series_on_nse_row(): void
    {
        $service = $this->makeService();
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('upsertMasterRow');
        $method->setAccessible(true);
        $method->invoke(
            $service,
            'TOKYOPLAST',
            'NSE',
            'Tokyo Plast International Limited',
            'INE932C01012',
            'BE',
        );

        $stock = Stock::query()->where('symbol', 'TOKYOPLAST')->where('exchange', 'NSE')->first();
        $this->assertNotNull($stock);
        $this->assertSame('BE', $stock->series);
    }

    protected function makeService(): StockMasterSyncService
    {
        $syncLog = Mockery::mock(SyncLogService::class);
        $syncLog->shouldReceive('beginRun')->andReturn(null);
        $syncLog->shouldReceive('log')->andReturnNull();
        $syncLog->shouldReceive('completeRun')->andReturnNull();
        $priceFetch = Mockery::mock(PriceFetchService::class);
        $bseMaster = Mockery::mock(BseEquityMasterService::class);
        $dualListedRepair = Mockery::mock(DualListedNseRepairService::class);
        $dualListedRepair->shouldReceive('repair')->andReturn([
            'pairs_found' => 0,
            'bse_rows_deactivated' => 0,
            'bse_prices_deleted' => 0,
            'bse_metrics_deleted' => 0,
            'references_repointed' => 0,
            'nse_backfill_stocks' => 0,
            'nse_backfill_rows' => 0,
            'nse_backfill_failures' => 0,
            'errors' => [],
        ]);

        return new StockMasterSyncService(new ProviderResolverService, $syncLog, $priceFetch, $bseMaster, $dualListedRepair);
    }
}
