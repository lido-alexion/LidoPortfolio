<?php

namespace Tests\Unit;

use App\Models\Setting;
use App\Models\Stock;
use App\Services\PortfolioLoggerService;
use App\Services\PriceFetchService;
use App\Services\SyncLogService;
use App\Services\UniversePriceSyncService;
use App\Services\UniverseStockResolverService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class UniversePriceSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_sync_processes_batch_and_advances_cursor(): void
    {
        config([
            'portfolio.universe_price_sync.enabled' => true,
            'portfolio.universe_price_sync.batch_size' => 2,
            'portfolio.universe_price_sync.delay_ms_between_stocks' => 0,
            'portfolio.universe_price_sync.daily_lookback_days' => 5,
        ]);

        $first = Stock::query()->create([
            'symbol' => 'AAA',
            'exchange' => 'NSE',
            'name' => 'AAA',
            'is_active' => true,
            'is_benchmark' => false,
        ]);
        $second = Stock::query()->create([
            'symbol' => 'BBB',
            'exchange' => 'NSE',
            'name' => 'BBB',
            'is_active' => true,
            'is_benchmark' => false,
        ]);
        Stock::query()->create([
            'symbol' => 'CCC',
            'exchange' => 'NSE',
            'name' => 'CCC',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        $priceFetch = Mockery::mock(PriceFetchService::class);
        $priceFetch->shouldReceive('syncStock')
            ->twice()
            ->andReturn([
                'success' => true,
                'stored_rows' => 1,
                'cache_hit' => false,
                'errors' => [],
            ]);

        $syncLog = Mockery::mock(SyncLogService::class);
        $syncLog->shouldReceive('beginRun')->once()->andReturn('run-1');
        $syncLog->shouldReceive('log')->atLeast()->once();
        $syncLog->shouldReceive('completeRun')->once();

        $logger = Mockery::mock(PortfolioLoggerService::class);
        $logger->shouldReceive('scheduler')->atLeast()->once();

        $service = new UniversePriceSyncService(
            app(UniverseStockResolverService::class),
            $priceFetch,
            $syncLog,
            $logger,
        );

        $result = $service->sync(mode: 'daily', processAll: false, batchSize: 2);

        $this->assertSame(2, $result['processed']);
        $this->assertSame(2, $result['succeeded']);
        $this->assertSame($second->id, (int) Setting::getValue(UniversePriceSyncService::KEY_CURSOR_STOCK_ID));
        $this->assertFalse($result['cycle_completed']);
    }

    public function test_backfill_all_resets_cursor_and_marks_cycle_complete(): void
    {
        config([
            'portfolio.universe_price_sync.enabled' => true,
            'portfolio.universe_price_sync.history_days' => 30,
            'portfolio.universe_price_sync.delay_ms_between_stocks' => 0,
        ]);

        Setting::setValue(UniversePriceSyncService::KEY_CURSOR_STOCK_ID, '999');

        $stock = Stock::query()->create([
            'symbol' => 'ZZZ',
            'exchange' => 'NSE',
            'name' => 'ZZZ',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        $priceFetch = Mockery::mock(PriceFetchService::class);
        $priceFetch->shouldReceive('syncStock')
            ->once()
            ->withArgs(function ($s, Carbon $from, Carbon $to) use ($stock) {
                return $s->id === $stock->id
                    && $from->lte(now()->subDays(30))
                    && $to->isToday();
            })
            ->andReturn([
                'success' => true,
                'stored_rows' => 20,
                'cache_hit' => false,
                'errors' => [],
            ]);

        $syncLog = Mockery::mock(SyncLogService::class);
        $syncLog->shouldReceive('beginRun')->once()->andReturn('run-2');
        $syncLog->shouldReceive('log')->atLeast()->once();
        $syncLog->shouldReceive('completeRun')->once();

        $logger = Mockery::mock(PortfolioLoggerService::class);
        $logger->shouldReceive('scheduler')->atLeast()->once();

        $service = new UniversePriceSyncService(
            app(UniverseStockResolverService::class),
            $priceFetch,
            $syncLog,
            $logger,
        );

        $result = $service->sync(mode: 'backfill', processAll: true);

        $this->assertTrue($result['cycle_completed']);
        $this->assertSame('0', Setting::getValue(UniversePriceSyncService::KEY_CURSOR_STOCK_ID));
        $this->assertNotNull(Setting::getValue(UniversePriceSyncService::KEY_LAST_CYCLE_COMPLETED_AT));
    }
}
