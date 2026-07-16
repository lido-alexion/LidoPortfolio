<?php

namespace Tests\Unit;

use App\Models\Setting;
use App\Models\Stock;
use App\Services\IndexCatalogService;
use App\Services\IndexPriceSyncService;
use App\Services\PortfolioLoggerService;
use App\Services\PriceFetchService;
use App\Services\PriceHistoryGapService;
use App\Services\SettingsService;
use App\Services\StockPriceHistoryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class IndexPriceSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_sync_batch_advances_cursor(): void
    {
        config(['portfolio.indexes.batch_size' => 2]);
        config(['portfolio.indexes.delay_ms_between_indexes' => 0]);

        $priceFetch = Mockery::mock(PriceFetchService::class);
        $priceFetch->shouldReceive('syncStock')
            ->twice()
            ->andReturn([
                'success' => true,
                'stored_rows' => 5,
                'fetched_rows' => 5,
                'errors' => [],
            ]);

        $history = Mockery::mock(StockPriceHistoryService::class);
        $history->shouldReceive('getCachedAnalyticsHistoryStatus')
            ->andReturn(['cache_hit' => true]);

        $gaps = Mockery::mock(PriceHistoryGapService::class);
        $logger = Mockery::mock(PortfolioLoggerService::class);
        $logger->shouldReceive('scheduler')->once();

        $service = new IndexPriceSyncService(
            app(IndexCatalogService::class),
            $priceFetch,
            $history,
            $gaps,
            app(SettingsService::class),
            $logger,
        );

        $result = $service->syncBatch(mode: 'daily', resetCursor: true);

        $this->assertSame(2, $result['processed']);
        $this->assertSame(2, $result['succeeded']);
        $this->assertNotNull($result['cursor_after']);
        $this->assertSame($result['cursor_after'], Setting::getValue(IndexPriceSyncService::KEY_CURSOR_SYMBOL));
    }

    public function test_backfill_uses_history_days_window(): void
    {
        Carbon::setTestNow('2026-07-15 12:00:00');
        config(['portfolio.indexes.history_days' => 365]);

        $catalog = app(IndexCatalogService::class);
        $stock = $catalog->primaryBenchmarkStock();

        $priceFetch = Mockery::mock(PriceFetchService::class);
        $priceFetch->shouldReceive('syncStock')
            ->once()
            ->withArgs(function (Stock $synced, Carbon $from, Carbon $to) use ($stock) {
                return $synced->id === $stock->id
                    && $from->diffInDays($to) >= 360
                    && $to->lte(now());
            })
            ->andReturn([
                'success' => true,
                'stored_rows' => 200,
                'fetched_rows' => 200,
                'errors' => [],
            ]);

        $history = Mockery::mock(StockPriceHistoryService::class);
        $gaps = Mockery::mock(PriceHistoryGapService::class);
        $logger = Mockery::mock(PortfolioLoggerService::class);

        $service = new IndexPriceSyncService(
            $catalog,
            $priceFetch,
            $history,
            $gaps,
            app(SettingsService::class),
            $logger,
        );

        $result = $service->syncOneSymbol('NIFTY50', 'backfill');

        $this->assertTrue($result['success']);
        $this->assertTrue($result['full_history']);
        $this->assertSame(200, $result['stored_rows']);

        Carbon::setTestNow();
    }

    public function test_status_lists_indexes(): void
    {
        $gaps = Mockery::mock(PriceHistoryGapService::class);
        $gaps->shouldReceive('gapsForStock')->andReturn([
            'has_gaps' => false,
            'gap_count' => 0,
            'ranges' => [],
        ]);

        $service = new IndexPriceSyncService(
            app(IndexCatalogService::class),
            Mockery::mock(PriceFetchService::class),
            Mockery::mock(StockPriceHistoryService::class),
            $gaps,
            app(SettingsService::class),
            Mockery::mock(PortfolioLoggerService::class),
        );

        $status = $service->status();

        $this->assertTrue($status['enabled']);
        $this->assertGreaterThanOrEqual(25, $status['index_count']);
        $this->assertSame('NIFTY50', $status['primary_symbol']);
        $this->assertNotEmpty($status['indexes']);
    }
}
