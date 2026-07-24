<?php

namespace Tests\Unit;

use App\Models\Setting;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Services\HistoryDepthBackfillService;
use App\Services\PortfolioLoggerService;
use App\Services\StockPriceHistoryService;
use App\Services\SyncLogService;
use App\Services\UniversePriceSyncService;
use App\Services\UniverseStockResolverService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class HistoryDepthBackfillServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function makeService(
        ?StockPriceHistoryService $history = null,
    ): HistoryDepthBackfillService {
        $syncLog = Mockery::mock(SyncLogService::class);
        $syncLog->shouldReceive('beginRun')->andReturn('depth-run');
        $syncLog->shouldReceive('log');
        $syncLog->shouldReceive('completeRun');

        $logger = Mockery::mock(PortfolioLoggerService::class);
        $logger->shouldReceive('scheduler');

        $universeSync = Mockery::mock(UniversePriceSyncService::class);
        $universeSync->shouldReceive('isSyncInProgress')->andReturn(false);

        return new HistoryDepthBackfillService(
            app(UniverseStockResolverService::class),
            $history ?? app(StockPriceHistoryService::class),
            $universeSync,
            $syncLog,
            $logger,
        );
    }

    protected function makeStock(string $symbol, bool $benchmark = false): Stock
    {
        return Stock::query()->create([
            'symbol' => $symbol,
            'exchange' => 'NSE',
            'name' => $symbol,
            'is_active' => true,
            'is_benchmark' => $benchmark,
        ]);
    }

    public function test_pre_listing_prefix_gap_is_reported_only_with_flag(): void
    {
        Carbon::setTestNow('2026-07-21 12:00:00');

        // Stock row created recently with recent OHLCV only: the prefix edge gap
        // is normally suppressed as "pre-listing".
        $stock = $this->makeStock('NEWLIST');
        foreach (range(0, 30) as $daysAgo) {
            $date = now()->subDays($daysAgo);
            if ((int) $date->format('N') >= 6) {
                continue;
            }
            StockPrice::query()->create([
                'stock_id' => $stock->id,
                'price_date' => $date->toDateString(),
                'close_price' => 100,
                'adjusted_close_price' => 100,
                'provider_source' => 'test',
                'data_source' => 'test',
                'created_at' => now(),
            ]);
        }

        $history = app(StockPriceHistoryService::class);
        $from = now()->subDays(550);
        $to = now()->subDay();

        $default = $history->getMissingHistoryRanges($stock, $from->copy(), $to->copy());
        $withFlag = $history->getMissingHistoryRanges($stock, $from->copy(), $to->copy(), includePreListingPrefix: true);

        $this->assertSame([], $default, 'prefix should be suppressed as pre-listing by default');
        $this->assertNotEmpty($withFlag, 'flag must surface the prefix gap for depth backfill');
        $this->assertSame($from->toDateString(), $withFlag[0]['from']->toDateString());
    }

    public function test_run_batch_processes_indexes_first_then_equities_with_flag(): void
    {
        Carbon::setTestNow('2026-07-21 12:00:00');
        config([
            'portfolio.history_depth_backfill.enabled' => true,
            'portfolio.history_depth_backfill.target_history_days' => 550,
            'portfolio.history_depth_backfill.delay_ms_between_stocks' => 0,
        ]);

        $index = $this->makeStock('NIFTY50', benchmark: true);
        $equity = $this->makeStock('DEEPME');

        $seen = [];
        $history = Mockery::mock(StockPriceHistoryService::class);
        $history->shouldReceive('fetchMissingHistory')
            ->twice()
            ->withArgs(function (Stock $stock, Carbon $from, Carbon $to, bool $notify, bool $includePreListingPrefix) use (&$seen) {
                $seen[] = $stock->symbol;

                return $includePreListingPrefix === true && $notify === false;
            })
            ->andReturn([
                'success' => true,
                'cache_hit' => false,
                'stored_rows' => 120,
                'errors' => [],
            ]);

        $service = $this->makeService($history);
        $stats = $service->runBatch(batchSize: 10);

        $this->assertSame(['NIFTY50', 'DEEPME'], $seen, 'indices must be deepened before equities');
        $this->assertSame(1, $stats['indexes_processed']);
        $this->assertSame(2, $stats['processed']);
        $this->assertSame(2, $stats['deepened']);
        $this->assertSame(240, $stats['stored_rows']);
        $this->assertTrue($stats['cycle_completed']);
        $this->assertNotEmpty(Setting::getValue(HistoryDepthBackfillService::KEY_INDEXES_DONE_AT));
    }

    public function test_campaign_completes_goes_idle_and_rearms_when_target_raised(): void
    {
        Carbon::setTestNow('2026-07-21 12:00:00');
        config([
            'portfolio.history_depth_backfill.enabled' => true,
            'portfolio.history_depth_backfill.target_history_days' => 550,
            'portfolio.history_depth_backfill.delay_ms_between_stocks' => 0,
        ]);

        $this->makeStock('ONLYONE');

        $history = Mockery::mock(StockPriceHistoryService::class);
        $history->shouldReceive('fetchMissingHistory')->andReturn([
            'success' => true,
            'cache_hit' => true,
            'stored_rows' => 0,
            'errors' => [],
        ]);

        $service = $this->makeService($history);
        $this->assertTrue($service->isDue());

        $stats = $service->runBatch(batchSize: 10);
        $this->assertTrue($stats['cycle_completed']);
        $this->assertSame(1, $stats['already_deep']);
        $this->assertTrue($service->isCompleted());
        $this->assertFalse($service->isDue());

        // Second run does nothing.
        $again = $service->runBatch(batchSize: 10);
        $this->assertTrue($again['skipped']);
        $this->assertSame('completed', $again['reason']);

        // Raising the target re-arms the campaign.
        config(['portfolio.history_depth_backfill.target_history_days' => 730]);
        $this->assertFalse($service->isCompleted());
        $this->assertTrue($service->isDue());

        // resetCampaign also re-arms with the same target.
        config(['portfolio.history_depth_backfill.target_history_days' => 550]);
        $this->assertTrue($service->isCompleted());
        $service->resetCampaign();
        $this->assertFalse($service->isCompleted());
    }

    public function test_cursor_advances_across_batches_and_provider_errors_are_counted(): void
    {
        Carbon::setTestNow('2026-07-21 12:00:00');
        config([
            'portfolio.history_depth_backfill.enabled' => true,
            'portfolio.history_depth_backfill.target_history_days' => 550,
            'portfolio.history_depth_backfill.delay_ms_between_stocks' => 0,
        ]);

        $first = $this->makeStock('AAA');
        $this->makeStock('BBB');

        $history = Mockery::mock(StockPriceHistoryService::class);
        $history->shouldReceive('fetchMissingHistory')->andReturn([
            'success' => false,
            'cache_hit' => false,
            'stored_rows' => 0,
            'errors' => ['nse: boom'],
        ]);

        $service = $this->makeService($history);

        $batch1 = $service->runBatch(batchSize: 1);
        $this->assertSame(1, $batch1['processed']);
        $this->assertSame(1, $batch1['failed']);
        $this->assertFalse($batch1['cycle_completed']);
        $this->assertSame(
            (string) $first->id,
            Setting::getValue(HistoryDepthBackfillService::KEY_CURSOR_STOCK_ID),
        );

        $batch2 = $service->runBatch(batchSize: 1);
        $this->assertSame(1, $batch2['processed']);
        $this->assertTrue($batch2['cycle_completed']);
        $this->assertTrue($service->isCompleted());

        $progress = json_decode((string) Setting::getValue(HistoryDepthBackfillService::KEY_PROGRESS_JSON), true);
        $this->assertSame(2, $progress['processed']);
        $this->assertSame(2, $progress['failed']);
    }
}
