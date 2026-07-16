<?php

namespace Tests\Unit;

use App\Models\Stock;
use App\Services\BseEquityMasterService;
use App\Services\DualListedNseRepairService;
use App\Services\PriceFetchService;
use App\Services\ProviderResolverService;
use App\Services\StockMasterSyncService;
use App\Services\SyncLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class StockMasterBseDedupTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_bse_sync_skips_rows_with_nse_isin_and_marks_dual_listed(): void
    {
        Stock::query()->create([
            'symbol' => 'INFY',
            'exchange' => 'NSE',
            'name' => 'Infosys',
            'isin' => 'INE009A01021',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        $bseMaster = Mockery::mock(BseEquityMasterService::class);
        $bseMaster->shouldReceive('fetchEquityRows')->once()->andReturn([
            [
                'symbol' => 'INFY',
                'name' => 'Infosys BSE',
                'isin' => 'INE009A01021',
            ],
            [
                'symbol' => 'BSEONLY',
                'name' => 'BSE Only',
                'isin' => 'INE000B01001',
            ],
        ]);

        $service = $this->makeService($bseMaster);
        $nseIsins = ['INE009A01021' => true];
        $newIds = [];
        $dualListed = [];

        $stats = $service->syncBseMaster(null, null, $nseIsins, $newIds, $dualListed);

        $this->assertSame(1, $stats['skipped_isin_dup']);
        $this->assertSame(1, $stats['added']);
        $this->assertTrue(Stock::query()->where('symbol', 'BSEONLY')->where('exchange', 'BSE')->exists());
        $this->assertFalse(Stock::query()->where('symbol', 'INFY')->where('exchange', 'BSE')->where('is_active', true)->exists());

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('reconcileDualListedFlags');
        $method->setAccessible(true);
        $method->invoke($service, $dualListed);

        $nse = Stock::query()->where('symbol', 'INFY')->where('exchange', 'NSE')->first();
        $this->assertTrue($nse->is_dual_listed);
    }

    public function test_reconcile_dual_listed_flags_by_symbol_when_bse_isin_missing(): void
    {
        Stock::query()->create([
            'symbol' => 'TOKYOPLAST',
            'exchange' => 'NSE',
            'name' => 'Tokyo Plast',
            'isin' => 'INE932C01012',
            'is_active' => true,
            'is_benchmark' => false,
        ]);
        Stock::query()->create([
            'symbol' => 'TOKYOPLAST',
            'exchange' => 'BSE',
            'name' => 'Tokyo Plast',
            'isin' => null,
            'is_active' => false,
            'is_benchmark' => false,
        ]);

        $service = $this->makeService(Mockery::mock(BseEquityMasterService::class));
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('reconcileDualListedFlags');
        $method->setAccessible(true);
        $method->invoke($service, []);

        $nse = Stock::query()->where('symbol', 'TOKYOPLAST')->where('exchange', 'NSE')->first();
        $this->assertTrue($nse->is_dual_listed);
    }

    public function test_bse_skip_deactivates_existing_row_without_isin(): void
    {
        Stock::query()->create([
            'symbol' => 'INFY',
            'exchange' => 'NSE',
            'name' => 'Infosys',
            'isin' => 'INE009A01021',
            'is_active' => true,
            'is_benchmark' => false,
        ]);
        $legacyBse = Stock::query()->create([
            'symbol' => 'INFY',
            'exchange' => 'BSE',
            'name' => 'Infosys',
            'isin' => null,
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        $bseMaster = Mockery::mock(BseEquityMasterService::class);
        $bseMaster->shouldReceive('fetchEquityRows')->once()->andReturn([
            [
                'symbol' => 'INFY',
                'name' => 'Infosys BSE',
                'isin' => 'INE009A01021',
            ],
        ]);

        $service = $this->makeService($bseMaster);
        $nseIsins = ['INE009A01021' => true];
        $newIds = [];
        $dualListed = [];

        $service->syncBseMaster(null, null, $nseIsins, $newIds, $dualListed);

        $legacyBse->refresh();
        $this->assertFalse($legacyBse->is_active);
        $this->assertSame('INE009A01021', $legacyBse->isin);
    }

    protected function makeService(BseEquityMasterService $bseMaster): StockMasterSyncService
    {
        $syncLog = Mockery::mock(SyncLogService::class);
        $syncLog->shouldReceive('beginRun')->andReturn(null);
        $syncLog->shouldReceive('log')->andReturnNull();
        $syncLog->shouldReceive('completeRun')->andReturnNull();
        $priceFetch = Mockery::mock(PriceFetchService::class);
        $dualListedRepair = Mockery::mock(DualListedNseRepairService::class);

        return new StockMasterSyncService(new ProviderResolverService, $syncLog, $priceFetch, $bseMaster, $dualListedRepair);
    }
}
