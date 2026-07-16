<?php

namespace Tests\Unit;

use App\Models\Holding;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\User;
use App\Services\DualListedNseRepairService;
use App\Services\StockPriceHistoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class DualListedNseRepairServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_repair_purges_bse_prices_and_deactivates_duplicate(): void
    {
        $nse = Stock::query()->create([
            'symbol' => 'TOKYOPLAST',
            'exchange' => 'NSE',
            'series' => 'BE',
            'name' => 'Tokyo Plast',
            'isin' => 'INE932C01012',
            'is_dual_listed' => true,
            'is_active' => true,
            'is_benchmark' => false,
        ]);
        $bse = Stock::query()->create([
            'symbol' => 'TOKYOPLAST',
            'exchange' => 'BSE',
            'name' => 'Tokyo Plast',
            'isin' => 'INE932C01012',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        StockPrice::query()->create([
            'stock_id' => $bse->id,
            'price_date' => '2025-12-24',
            'close_price' => 120,
            'data_source' => 'bse_bhavcopy',
            'provider_source' => 'bse_bhavcopy',
        ]);

        $history = Mockery::mock(StockPriceHistoryService::class);
        $history->shouldReceive('fetchMissingHistory')->once()->andReturn([
            'success' => true,
            'stored_rows' => 5,
            'errors' => [],
        ]);

        $service = new DualListedNseRepairService($history);
        $stats = $service->repair(dryRun: false, backfill: true, maxBackfill: 10);

        $this->assertSame(1, $stats['pairs_found']);
        $this->assertSame(1, $stats['bse_prices_deleted']);
        $this->assertSame(1, $stats['bse_rows_deactivated']);
        $this->assertFalse($bse->fresh()->is_active);
        $this->assertSame(0, StockPrice::query()->where('stock_id', $bse->id)->count());
    }

    public function test_repair_matches_bse_without_isin_by_symbol(): void
    {
        $nse = Stock::query()->create([
            'symbol' => 'TOKYOPLAST',
            'exchange' => 'NSE',
            'series' => 'BE',
            'name' => 'Tokyo Plast',
            'isin' => 'INE932C01012',
            'is_active' => true,
            'is_benchmark' => false,
        ]);
        $bse = Stock::query()->create([
            'symbol' => 'TOKYOPLAST',
            'exchange' => 'BSE',
            'name' => 'Tokyo Plast',
            'isin' => null,
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        StockPrice::query()->create([
            'stock_id' => $bse->id,
            'price_date' => '2025-12-24',
            'close_price' => 120,
            'data_source' => 'bse_bhavcopy',
            'provider_source' => 'bse_bhavcopy',
        ]);

        $history = Mockery::mock(StockPriceHistoryService::class);
        $history->shouldReceive('fetchMissingHistory')->once()->andReturn([
            'success' => true,
            'stored_rows' => 3,
            'errors' => [],
        ]);

        $service = new DualListedNseRepairService($history);
        $stats = $service->repair(dryRun: false, backfill: true, maxBackfill: 10);

        $this->assertSame(1, $stats['pairs_found']);
        $this->assertSame('INE932C01012', $bse->fresh()->isin);
        $this->assertFalse($bse->fresh()->is_active);
    }

    public function test_repair_backfills_nse_when_bse_has_no_prices(): void
    {
        $nse = Stock::query()->create([
            'symbol' => 'TOKYOPLAST',
            'exchange' => 'NSE',
            'series' => 'BE',
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

        $history = Mockery::mock(StockPriceHistoryService::class);
        $history->shouldReceive('fetchMissingHistory')->once()->andReturn([
            'success' => true,
            'stored_rows' => 4,
            'errors' => [],
        ]);

        $service = new DualListedNseRepairService($history);
        $stats = $service->repair(dryRun: false, backfill: true, maxBackfill: 10);

        $this->assertSame(1, $stats['pairs_found']);
        $this->assertSame(0, $stats['bse_prices_deleted']);
        $this->assertSame(1, $stats['nse_backfill_stocks']);
        $this->assertSame(4, $stats['nse_backfill_rows']);
    }

    public function test_backfill_paired_nse_history_uses_cursor(): void
    {
        Stock::query()->create([
            'symbol' => 'AAA',
            'exchange' => 'NSE',
            'name' => 'AAA',
            'isin' => 'INEAAA01012',
            'is_active' => true,
            'is_benchmark' => false,
        ]);
        Stock::query()->create([
            'symbol' => 'BBB',
            'exchange' => 'NSE',
            'name' => 'BBB',
            'isin' => 'INEBBB01012',
            'is_active' => true,
            'is_benchmark' => false,
        ]);
        Stock::query()->create(['symbol' => 'AAA', 'exchange' => 'BSE', 'name' => 'AAA', 'is_active' => false, 'is_benchmark' => false]);
        Stock::query()->create(['symbol' => 'BBB', 'exchange' => 'BSE', 'name' => 'BBB', 'is_active' => false, 'is_benchmark' => false]);

        $history = Mockery::mock(StockPriceHistoryService::class);
        $history->shouldReceive('fetchMissingHistory')->once()->andReturn([
            'success' => true,
            'stored_rows' => 1,
            'errors' => [],
        ]);

        $service = new DualListedNseRepairService($history);
        $first = $service->backfillPairedNseHistory(1, resetCursor: true);
        $this->assertSame(1, $first['nse_backfill_stocks']);
        $this->assertSame(1, $first['nse_backfill_remaining']);

        $history->shouldReceive('fetchMissingHistory')->once()->andReturn([
            'success' => true,
            'stored_rows' => 2,
            'errors' => [],
        ]);
        $second = $service->backfillPairedNseHistory(1);
        $this->assertSame(1, $second['nse_backfill_stocks']);
        $this->assertSame(0, $second['nse_backfill_remaining']);
    }

    public function test_repair_repoints_holdings_from_bse_to_nse(): void
    {
        $user = User::factory()->create();
        $nse = Stock::query()->create([
            'symbol' => 'TOKYOPLAST',
            'exchange' => 'NSE',
            'series' => 'BE',
            'name' => 'Tokyo Plast',
            'isin' => 'INE932C01012',
            'is_active' => true,
            'is_benchmark' => false,
        ]);
        $bse = Stock::query()->create([
            'symbol' => 'TOKYOPLAST',
            'exchange' => 'BSE',
            'name' => 'Tokyo Plast',
            'isin' => 'INE932C01012',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        Holding::query()->create([
            'user_id' => $user->id,
            'stock_id' => $bse->id,
            'quantity' => 10,
            'avg_buy_price' => 100,
            'invested_amount' => 1000,
            'realized_profit' => 0,
        ]);

        $history = Mockery::mock(StockPriceHistoryService::class);
        $history->shouldReceive('fetchMissingHistory')->never();

        $service = new DualListedNseRepairService($history);
        $service->repair(dryRun: false, backfill: false);

        $this->assertSame(1, Holding::query()->where('stock_id', $nse->id)->count());
        $this->assertSame(0, Holding::query()->where('stock_id', $bse->id)->count());
    }
}
