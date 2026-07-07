<?php

namespace Tests\Unit;

use App\Models\Setting;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Services\PortfolioLoggerService;
use App\Services\PriceHistoryGapService;
use App\Services\RelativeStrengthService;
use App\Services\StockPriceHistoryService;
use App\Services\SyncLogService;
use App\Services\UniverseStockResolverService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class PriceHistoryGapServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_scan_batch_reports_stock_with_internal_gap(): void
    {
        Carbon::setTestNow('2026-06-21 12:00:00');
        config([
            'portfolio.universe_price_sync.enabled' => true,
            'portfolio.universe_price_sync.batch_size' => 10,
            'portfolio.universe_price_sync.history_days' => 60,
            'portfolio.history.max_internal_gap_days' => 7,
        ]);

        $stock = Stock::query()->create([
            'symbol' => 'GAPTEST',
            'exchange' => 'NSE',
            'name' => 'Gap Test',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        foreach (['2026-04-01', '2026-04-20'] as $date) {
            StockPrice::query()->create([
                'stock_id' => $stock->id,
                'price_date' => $date,
                'close_price' => 100,
                'adjusted_close_price' => 100,
                'provider_source' => 'test',
                'data_source' => 'test',
                'created_at' => now(),
            ]);
        }

        $service = new PriceHistoryGapService(
            app(UniverseStockResolverService::class),
            app(StockPriceHistoryService::class),
            app(RelativeStrengthService::class),
            Mockery::mock(SyncLogService::class),
            Mockery::mock(PortfolioLoggerService::class),
        );

        $result = $service->scanBatch(resetCursor: true);

        $this->assertSame(1, $result['scanned']);
        $this->assertSame(1, $result['with_gaps']);
        $this->assertSame('GAPTEST', $result['symbols_with_gaps'][0]['symbol']);

        Carbon::setTestNow();
    }

    public function test_fill_batch_fetches_missing_ranges_for_gapped_stock(): void
    {
        Carbon::setTestNow('2026-06-21 12:00:00');
        config([
            'portfolio.universe_price_sync.enabled' => true,
            'portfolio.universe_price_sync.batch_size' => 10,
            'portfolio.universe_price_sync.delay_ms_between_stocks' => 0,
            'portfolio.universe_price_sync.history_days' => 60,
        ]);

        $stock = Stock::query()->create([
            'symbol' => 'FILLME',
            'exchange' => 'NSE',
            'name' => 'Fill Me',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        $history = Mockery::mock(StockPriceHistoryService::class);
        $history->shouldReceive('getMissingHistoryRanges')->andReturn([
            ['from' => now()->subDays(30), 'to' => now()->subDays(20)],
        ]);
        $history->shouldReceive('fetchMissingHistory')
            ->atLeast()
            ->once()
            ->andReturn([
                'success' => true,
                'stored_rows' => 5,
                'fetched_rows' => 5,
                'errors' => [],
            ]);

        $syncLog = Mockery::mock(SyncLogService::class);
        $syncLog->shouldReceive('beginRun')->once()->andReturn('gap-run');
        $syncLog->shouldReceive('log')->atLeast()->once();
        $syncLog->shouldReceive('completeRun')->once();

        $logger = Mockery::mock(PortfolioLoggerService::class);
        $logger->shouldReceive('scheduler')->once();

        $service = new PriceHistoryGapService(
            app(UniverseStockResolverService::class),
            $history,
            app(RelativeStrengthService::class),
            $syncLog,
            $logger,
        );

        $result = $service->fillBatch(resetCursor: true);

        $this->assertGreaterThanOrEqual(1, $result['filled']);
        $this->assertGreaterThanOrEqual(5, $result['stored_rows']);
        $this->assertNotNull(Setting::getValue(PriceHistoryGapService::KEY_LAST_FILL_JSON));

        Carbon::setTestNow();
    }

    public function test_fill_cycle_chains_batches_until_cycle_complete(): void
    {
        Carbon::setTestNow('2026-06-21 12:00:00');
        config([
            'portfolio.universe_price_sync.enabled' => true,
            'portfolio.universe_price_sync.batch_size' => 1,
            'portfolio.universe_price_sync.delay_ms_between_stocks' => 0,
        ]);

        foreach (['AAA', 'BBB', 'CCC'] as $symbol) {
            Stock::query()->create([
                'symbol' => $symbol,
                'exchange' => 'NSE',
                'name' => $symbol,
                'is_active' => true,
                'is_benchmark' => false,
            ]);
        }

        $history = Mockery::mock(StockPriceHistoryService::class);
        $history->shouldReceive('getMissingHistoryRanges')->andReturn([]);
        $history->shouldReceive('fetchMissingHistory')->never();

        $syncLog = Mockery::mock(SyncLogService::class);
        $syncLog->shouldReceive('beginRun')->times(3)->andReturn('gap-run');
        $syncLog->shouldReceive('log')->atLeast()->once();
        $syncLog->shouldReceive('completeRun')->times(3);

        $logger = Mockery::mock(PortfolioLoggerService::class);
        $logger->shouldReceive('scheduler')->times(3);

        $service = new PriceHistoryGapService(
            app(UniverseStockResolverService::class),
            $history,
            app(RelativeStrengthService::class),
            $syncLog,
            $logger,
        );

        $result = $service->fillCycle(resetCursor: true, maxBatches: 10);

        $this->assertTrue($result['cycle_completed']);
        $this->assertSame(3, $result['batches_run']);
        $this->assertSame('cycle_completed', $result['stopped_reason']);

        Carbon::setTestNow();
    }
}
