<?php

namespace Tests\Unit;

use App\Models\Stock;
use App\Services\ProviderResolverService;
use App\Services\SyncLogService;
use App\Services\StockMasterSyncService;
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

    public function test_parse_nse_equity_csv_skips_non_eq_series(): void
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
        $this->assertSame('Infosys Limited', $rows[0]['name']);
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
        $this->assertTrue(Stock::query()->where('symbol', 'NEW')->first()->is_active);
    }

    protected function makeService(): StockMasterSyncService
    {
        $syncLog = Mockery::mock(SyncLogService::class);
        $syncLog->shouldReceive('beginRun')->andReturn(null);
        $syncLog->shouldReceive('log')->andReturnNull();
        $syncLog->shouldReceive('completeRun')->andReturnNull();

        return new StockMasterSyncService(new ProviderResolverService, $syncLog);
    }
}
