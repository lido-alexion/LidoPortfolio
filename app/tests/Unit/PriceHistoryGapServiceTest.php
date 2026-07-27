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
use App\Support\TradingCalendar;
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
            app(\App\Services\IgnoredPriceGapService::class),
        );

        $result = $service->scanBatch(resetCursor: true);

        $this->assertSame(1, $result['scanned']);
        $this->assertSame(1, $result['with_gaps']);
        $this->assertSame('GAPTEST', $result['symbols_with_gaps'][0]['symbol']);
        $this->assertNotEmpty($result['symbols_with_gaps'][0]['ranges'] ?? []);
        $this->assertArrayHasKey('from', $result['symbols_with_gaps'][0]['ranges'][0] ?? []);
        $this->assertArrayHasKey('to', $result['symbols_with_gaps'][0]['ranges'][0] ?? []);

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
        $logger->shouldReceive('scheduler')->atLeast()->once();

        $service = new PriceHistoryGapService(
            app(UniverseStockResolverService::class),
            $history,
            app(RelativeStrengthService::class),
            $syncLog,
            $logger,
            app(\App\Services\IgnoredPriceGapService::class),
        );

        $result = $service->fillBatch(resetCursor: true);

        $this->assertGreaterThanOrEqual(1, $result['filled']);
        $this->assertGreaterThanOrEqual(5, $result['stored_rows']);
        $this->assertNotNull(Setting::getValue(PriceHistoryGapService::KEY_LAST_FILL_JSON));

        Carbon::setTestNow();
    }

    public function test_scan_all_reports_every_symbol_with_gaps(): void
    {
        Carbon::setTestNow('2026-06-21 12:00:00');
        config([
            'portfolio.universe_price_sync.enabled' => true,
            'portfolio.universe_price_sync.history_days' => 60,
            'portfolio.history.analytics_buffer_days.6m' => 60,
            'portfolio.history.max_internal_gap_days' => 7,
        ]);

        $gapped = ['GAPONE'];
        foreach (['GAPONE', 'FULLOK'] as $symbol) {
            $stock = Stock::query()->create([
                'symbol' => $symbol,
                'exchange' => 'NSE',
                'name' => $symbol,
                'is_active' => true,
                'is_benchmark' => false,
            ]);

            if ($symbol === 'GAPONE') {
                $requiredTo = TradingCalendar::lastRequiredPriceSession();
                $requiredFrom = $requiredTo->copy()->subDays(60);

                StockPrice::query()->create([
                    'stock_id' => $stock->id,
                    'price_date' => $requiredFrom->toDateString(),
                    'close_price' => 100,
                    'adjusted_close_price' => 100,
                    'provider_source' => 'test',
                    'data_source' => 'test',
                    'created_at' => now(),
                ]);
                StockPrice::query()->create([
                    'stock_id' => $stock->id,
                    'price_date' => $requiredFrom->copy()->addDays(25)->toDateString(),
                    'close_price' => 100,
                    'adjusted_close_price' => 100,
                    'provider_source' => 'test',
                    'data_source' => 'test',
                    'created_at' => now(),
                ]);
            } else {
                $requiredTo = TradingCalendar::lastRequiredPriceSession();
                $requiredFrom = $requiredTo->copy()->subDays(60);

                for ($cursor = $requiredFrom->copy(); $cursor->lte($requiredTo); $cursor->addDay()) {
                    StockPrice::query()->create([
                        'stock_id' => $stock->id,
                        'price_date' => $cursor->toDateString(),
                        'close_price' => 100,
                        'adjusted_close_price' => 100,
                        'provider_source' => 'test',
                        'data_source' => 'test',
                        'created_at' => now(),
                    ]);
                }
            }
        }

        $service = new PriceHistoryGapService(
            app(UniverseStockResolverService::class),
            app(StockPriceHistoryService::class),
            app(RelativeStrengthService::class),
            Mockery::mock(SyncLogService::class),
            Mockery::mock(PortfolioLoggerService::class),
            app(\App\Services\IgnoredPriceGapService::class),
        );

        $result = $service->scanAll();

        $this->assertTrue($result['scan_completed']);
        $this->assertSame(2, $result['scanned']);
        $this->assertSame(1, $result['with_gaps']);
        $this->assertCount(1, $result['symbols_with_gaps']);
        $this->assertSame(
            $gapped,
            array_column($result['symbols_with_gaps'], 'symbol'),
        );
        $this->assertFalse($service->isInProgress());

        $storedScan = json_decode((string) Setting::getValue(PriceHistoryGapService::KEY_LAST_SCAN_JSON), true);
        $inventory = json_decode((string) Setting::getValue(PriceHistoryGapService::KEY_GAP_INVENTORY_JSON), true);
        $this->assertIsArray($storedScan);
        $this->assertNotEmpty($storedScan['symbols_with_gaps'][0]['ranges'] ?? []);
        $this->assertIsArray($inventory);
        $this->assertArrayNotHasKey('symbols_with_gaps', $inventory);
        $this->assertCount(1, $inventory['stock_ids'] ?? []);

        Carbon::setTestNow();
    }

    public function test_fill_all_targets_only_inventory_stocks(): void
    {
        Carbon::setTestNow('2026-06-21 12:00:00');
        config([
            'portfolio.universe_price_sync.enabled' => true,
            'portfolio.universe_price_sync.delay_ms_between_stocks' => 0,
            'portfolio.universe_price_sync.history_days' => 60,
            'portfolio.universe_price_sync.gap_fill_all_batch_size' => 15,
        ]);

        $fillStock = Stock::query()->create([
            'symbol' => 'FILLME',
            'exchange' => 'NSE',
            'name' => 'Fill Me',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        Stock::query()->create([
            'symbol' => 'CLEAN',
            'exchange' => 'NSE',
            'name' => 'Clean',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        $gappedSymbols = ['FILLME'];
        $history = Mockery::mock(StockPriceHistoryService::class);
        $history->shouldReceive('getMissingHistoryRanges')->andReturnUsing(function (Stock $stock) use (&$gappedSymbols) {
            if (in_array($stock->symbol, $gappedSymbols, true)) {
                return [['from' => now()->subDays(30), 'to' => now()->subDays(20)]];
            }

            return [];
        });
        $history->shouldReceive('fetchMissingHistory')
            ->atLeast()
            ->once()
            ->andReturnUsing(function (Stock $stock) use (&$gappedSymbols) {
                $gappedSymbols = array_values(array_diff($gappedSymbols, [$stock->symbol]));

                return [
                    'success' => true,
                    'stored_rows' => 4,
                    'fetched_rows' => 4,
                    'errors' => [],
                ];
            });

        $syncLog = Mockery::mock(SyncLogService::class);
        $syncLog->shouldReceive('beginRun')->once()->andReturn('gap-run');
        $syncLog->shouldReceive('log')->atLeast()->once();
        $syncLog->shouldReceive('completeRun')->once();

        $logger = Mockery::mock(PortfolioLoggerService::class);
        $logger->shouldReceive('scheduler')->atLeast()->once();

        $service = new PriceHistoryGapService(
            app(UniverseStockResolverService::class),
            $history,
            app(RelativeStrengthService::class),
            $syncLog,
            $logger,
            app(\App\Services\IgnoredPriceGapService::class),
        );

        $result = $service->fillAll(rescanFirst: true);

        $this->assertGreaterThanOrEqual(1, $result['filled']);
        $this->assertGreaterThanOrEqual(4, $result['stored_rows']);
        $this->assertSame(1, $result['with_gaps']);
        $this->assertTrue((bool) ($result['completed'] ?? false));
        $this->assertSame(0, (int) ($result['remaining'] ?? -1));
        $this->assertFalse($service->isInProgress());

        Carbon::setTestNow();
    }

    public function test_fill_all_skips_rescan_when_inventory_is_fresh(): void
    {
        Carbon::setTestNow('2026-06-21 12:00:00');
        config([
            'portfolio.universe_price_sync.enabled' => true,
            'portfolio.universe_price_sync.delay_ms_between_stocks' => 0,
            'portfolio.universe_price_sync.gap_fill_all_batch_size' => 15,
        ]);

        $fillStock = Stock::query()->create([
            'symbol' => 'FILLME',
            'exchange' => 'NSE',
            'name' => 'Fill Me',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        Setting::setValue(PriceHistoryGapService::KEY_LAST_SCAN_JSON, json_encode([
            'scope' => 'all_equities',
            'scan_completed' => true,
            'scanned' => 2,
            'with_gaps' => 1,
            'completed_at' => now()->toIso8601String(),
        ]));
        Setting::setValue(PriceHistoryGapService::KEY_GAP_INVENTORY_JSON, json_encode([
            'scope' => 'all_equities',
            'stock_ids' => [$fillStock->id],
            'scanned_at' => now()->toIso8601String(),
            'universe_count' => 2,
            'with_gaps' => 1,
        ]));

        $history = Mockery::mock(StockPriceHistoryService::class);
        $history->shouldReceive('getMissingHistoryRanges')->andReturn([]);
        $history->shouldReceive('fetchMissingHistory')->once()->andReturn([
            'success' => true,
            'stored_rows' => 2,
            'fetched_rows' => 2,
            'errors' => [],
            'providers_tried' => ['nse'],
            'attempted_ranges' => [['from' => '2026-01-01', 'to' => '2026-01-31']],
            'remaining_ranges' => [],
            'range_results' => [],
        ]);

        $syncLog = Mockery::mock(SyncLogService::class);
        $syncLog->shouldReceive('beginRun')->once()->andReturn('gap-run');
        $syncLog->shouldReceive('log')->atLeast()->once();
        $syncLog->shouldReceive('completeRun')->once();

        $logger = Mockery::mock(PortfolioLoggerService::class);
        $logger->shouldReceive('scheduler')->atLeast()->once();

        $service = new PriceHistoryGapService(
            app(UniverseStockResolverService::class),
            $history,
            app(RelativeStrengthService::class),
            $syncLog,
            $logger,
            app(\App\Services\IgnoredPriceGapService::class),
        );

        $result = $service->fillAll(rescanFirst: true);

        $this->assertTrue((bool) ($result['completed'] ?? false));
        $this->assertSame(1, $result['filled']);

        Carbon::setTestNow();
    }

    public function test_is_in_progress_clears_stale_fill_lock(): void
    {
        Carbon::setTestNow('2026-06-21 12:00:00');
        config([
            'portfolio.universe_price_sync.enabled' => true,
        ]);

        Setting::setValue(PriceHistoryGapService::KEY_IN_PROGRESS, '1');
        Setting::setValue(PriceHistoryGapService::KEY_IN_PROGRESS_MODE, 'fill');
        Setting::setValue(PriceHistoryGapService::KEY_IN_PROGRESS_AT, now()->subMinutes(20)->toIso8601String());

        $syncLog = Mockery::mock(SyncLogService::class);
        $syncLog->shouldReceive('latestRunSummary')
            ->once()
            ->andReturn([
                'status' => 'running',
                'started_at' => now()->subMinutes(20)->toIso8601String(),
            ]);

        $service = new PriceHistoryGapService(
            app(UniverseStockResolverService::class),
            app(StockPriceHistoryService::class),
            app(RelativeStrengthService::class),
            $syncLog,
            Mockery::mock(PortfolioLoggerService::class),
            app(\App\Services\IgnoredPriceGapService::class),
        );

        $this->assertFalse($service->isInProgress());
        $this->assertSame('0', Setting::getValue(PriceHistoryGapService::KEY_IN_PROGRESS, '0'));

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
        $logger->shouldReceive('scheduler')->atLeast()->times(3);

        $service = new PriceHistoryGapService(
            app(UniverseStockResolverService::class),
            $history,
            app(RelativeStrengthService::class),
            $syncLog,
            $logger,
            app(\App\Services\IgnoredPriceGapService::class),
        );

        $result = $service->fillCycle(resetCursor: true, maxBatches: 10);

        $this->assertTrue($result['cycle_completed']);
        $this->assertSame(3, $result['batches_run']);
        $this->assertSame('cycle_completed', $result['stopped_reason']);

        Carbon::setTestNow();
    }

    public function test_fill_all_records_failure_report_with_provider_details(): void
    {
        Carbon::setTestNow('2026-06-21 12:00:00');
        config([
            'portfolio.universe_price_sync.enabled' => true,
            'portfolio.universe_price_sync.delay_ms_between_stocks' => 0,
            'portfolio.universe_price_sync.gap_fill_all_batch_size' => 15,
        ]);

        $fillStock = Stock::query()->create([
            'symbol' => 'FAILME',
            'exchange' => 'NSE',
            'name' => 'Fail Me',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        Setting::setValue(PriceHistoryGapService::KEY_LAST_SCAN_JSON, json_encode([
            'scope' => 'all_equities',
            'scan_completed' => true,
            'scanned' => 1,
            'with_gaps' => 1,
            'completed_at' => now()->toIso8601String(),
        ]));
        Setting::setValue(PriceHistoryGapService::KEY_GAP_INVENTORY_JSON, json_encode([
            'scope' => 'all_equities',
            'stock_ids' => [$fillStock->id],
            'scanned_at' => now()->toIso8601String(),
            'universe_count' => 1,
            'with_gaps' => 1,
        ]));

        $history = Mockery::mock(StockPriceHistoryService::class);
        $history->shouldReceive('getMissingHistoryRanges')->andReturn([]);
        $history->shouldReceive('fetchMissingHistory')->once()->andReturn([
            'success' => false,
            'stored_rows' => 0,
            'fetched_rows' => 0,
            'errors' => ['nse(attempt 1): returned 0 rows'],
            'providers_tried' => ['nse', 'yahoo', 'alpha_vantage'],
            'attempted_ranges' => [['from' => '2025-01-01', 'to' => '2025-01-31']],
            'remaining_ranges' => [['from' => '2025-01-01', 'to' => '2025-01-31']],
            'range_results' => [[
                'from' => '2025-01-01',
                'to' => '2025-01-31',
                'provider' => 'none',
                'providers_tried' => ['nse', 'yahoo', 'alpha_vantage'],
                'errors' => ['nse(attempt 1): returned 0 rows'],
                'stored_rows' => 0,
            ]],
        ]);

        $syncLog = Mockery::mock(SyncLogService::class);
        $syncLog->shouldReceive('beginRun')->once()->andReturn('gap-run');
        $syncLog->shouldReceive('log')->atLeast()->once();
        $syncLog->shouldReceive('completeRun')->once();

        $logger = Mockery::mock(PortfolioLoggerService::class);
        $logger->shouldReceive('scheduler')->atLeast()->once();

        $service = new PriceHistoryGapService(
            app(UniverseStockResolverService::class),
            $history,
            app(RelativeStrengthService::class),
            $syncLog,
            $logger,
            app(\App\Services\IgnoredPriceGapService::class),
        );

        $result = $service->fillAll(rescanFirst: false);

        $this->assertTrue((bool) ($result['completed'] ?? false));
        $this->assertSame(1, $result['failed']);

        $report = json_decode(
            (string) Setting::getValue(PriceHistoryGapService::KEY_FILL_FAILURE_REPORT_JSON),
            true,
        );
        $this->assertIsArray($report);
        $this->assertSame(1, $report['failure_count']);
        $this->assertSame('FAILME', $report['failures'][0]['symbol']);
        $this->assertSame(['nse', 'yahoo', 'alpha_vantage'], $report['failures'][0]['providers_tried']);

        Carbon::setTestNow();
    }

    public function test_gaps_for_stock_does_not_require_todays_session_before_nightly_sync(): void
    {
        Carbon::setTestNow('2026-07-10 13:39:00');
        config([
            'portfolio.universe_price_sync.history_days' => 30,
            'portfolio.history.analytics_buffer_days.6m' => 30,
            'portfolio.history.max_internal_gap_days' => 7,
        ]);

        $stock = Stock::query()->create([
            'symbol' => 'CURRENT',
            'exchange' => 'NSE',
            'name' => 'Current Session',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        $requiredTo = TradingCalendar::lastRequiredPriceSession();
        $requiredFrom = $requiredTo->copy()->subDays(30);

        for ($cursor = $requiredFrom->copy(); $cursor->lte($requiredTo); $cursor->addDay()) {
            if (! TradingCalendar::isEquitySessionDate($cursor)) {
                continue;
            }

            StockPrice::query()->create([
                'stock_id' => $stock->id,
                'price_date' => $cursor->toDateString(),
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
            app(\App\Services\IgnoredPriceGapService::class),
        );

        $result = $service->gapsForStock($stock);

        $this->assertFalse($result['has_gaps']);
        $this->assertSame(0, $result['gap_count']);
        $this->assertSame([], $result['ranges']);

        Carbon::setTestNow();
    }

    public function test_gaps_for_stock_reports_short_suffix_gap_at_latest_session(): void
    {
        Carbon::setTestNow('2026-07-10 13:39:00');
        config([
            'portfolio.universe_price_sync.history_days' => 30,
            'portfolio.history.analytics_buffer_days.6m' => 30,
            'portfolio.history.max_internal_gap_days' => 7,
        ]);

        $stock = Stock::query()->create([
            'symbol' => 'MISSING',
            'exchange' => 'NSE',
            'name' => 'Missing Prior',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        $requiredTo = TradingCalendar::lastRequiredPriceSession();
        $requiredFrom = $requiredTo->copy()->subDays(30);

        for ($cursor = $requiredFrom->copy(); $cursor->lte($requiredTo->copy()->subDay()); $cursor->addDay()) {
            if (! TradingCalendar::isEquitySessionDate($cursor)) {
                continue;
            }

            StockPrice::query()->create([
                'stock_id' => $stock->id,
                'price_date' => $cursor->toDateString(),
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
            app(\App\Services\IgnoredPriceGapService::class),
        );

        $result = $service->gapsForStock($stock);

        $this->assertTrue($result['has_gaps']);
        $this->assertGreaterThan(0, $result['gap_count']);
        $this->assertSame($requiredTo->toDateString(), $result['ranges'][0]['to']);

        Carbon::setTestNow();
    }

    public function test_clear_gap_reports_clears_scan_and_failure_data(): void
    {
        Setting::setValue(PriceHistoryGapService::KEY_LAST_SCAN_JSON, json_encode([
            'scan_completed' => true,
            'with_gaps' => 5,
        ]));
        Setting::setValue(PriceHistoryGapService::KEY_GAP_INVENTORY_JSON, json_encode([
            'scope' => 'all_equities',
            'stock_ids' => [1, 2, 3],
        ]));
        Setting::setValue(PriceHistoryGapService::KEY_FILL_FAILURE_REPORT_JSON, json_encode([
            'failure_count' => 2,
            'failures' => [['symbol' => 'X']],
        ]));

        $service = new PriceHistoryGapService(
            app(UniverseStockResolverService::class),
            app(StockPriceHistoryService::class),
            app(RelativeStrengthService::class),
            Mockery::mock(SyncLogService::class),
            Mockery::mock(PortfolioLoggerService::class),
            app(\App\Services\IgnoredPriceGapService::class),
        );

        $result = $service->clearReports();

        $this->assertTrue($result['cleared']);
        $this->assertNull(Setting::getValue(PriceHistoryGapService::KEY_LAST_SCAN_JSON));
        $this->assertNull(Setting::getValue(PriceHistoryGapService::KEY_GAP_INVENTORY_JSON));
        $this->assertNull(Setting::getValue(PriceHistoryGapService::KEY_FILL_FAILURE_REPORT_JSON));
    }
}
