<?php

namespace Tests\Unit;

use App\Models\Setting;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Services\BenchmarkPriceSyncService;
use App\Services\PortfolioLoggerService;
use App\Services\PriceFetchService;
use App\Services\RelativeStrengthService;
use App\Services\SettingsService;
use App\Services\StockPriceHistoryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class BenchmarkPriceSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_skips_when_already_synced_today(): void
    {
        Setting::setValue(BenchmarkPriceSyncService::KEY_LAST_SYNC_DATE, now()->toDateString());

        $priceFetch = Mockery::mock(PriceFetchService::class);
        $priceFetch->shouldNotReceive('syncStock');

        $service = new BenchmarkPriceSyncService(
            $priceFetch,
            app(RelativeStrengthService::class),
            app(StockPriceHistoryService::class),
            app(SettingsService::class),
            Mockery::mock(PortfolioLoggerService::class),
        );

        $result = $service->syncIfNeeded();

        $this->assertTrue($result['skipped']);
        $this->assertTrue($result['success']);
    }

    public function test_full_history_sync_when_cache_insufficient(): void
    {
        Carbon::setTestNow('2026-06-21 12:00:00');

        $benchmark = app(RelativeStrengthService::class)->benchmarkStock();

        $priceFetch = Mockery::mock(PriceFetchService::class);
        $priceFetch->shouldReceive('syncStock')
            ->once()
            ->withArgs(function (Stock $stock, Carbon $from, Carbon $to) use ($benchmark) {
                return $stock->id === $benchmark->id
                    && $from->lte(now()->subMonths(12))
                    && $to->isSameDay(now());
            })
            ->andReturn([
                'success' => true,
                'stored_rows' => 250,
                'fetched_rows' => 250,
                'errors' => [],
            ]);

        $logger = Mockery::mock(PortfolioLoggerService::class);
        $logger->shouldReceive('scheduler')->once();

        $service = new BenchmarkPriceSyncService(
            $priceFetch,
            app(RelativeStrengthService::class),
            app(StockPriceHistoryService::class),
            app(SettingsService::class),
            $logger,
        );

        $result = $service->syncIfNeeded(force: true);

        $this->assertFalse($result['skipped']);
        $this->assertTrue($result['success']);
        $this->assertTrue($result['full_history']);
        $this->assertSame('2026-06-21', Setting::getValue(BenchmarkPriceSyncService::KEY_LAST_SYNC_DATE));

        Carbon::setTestNow();
    }

    public function test_incremental_sync_when_cache_sufficient(): void
    {
        Carbon::setTestNow('2026-06-21 12:00:00');
        config(['portfolio.universe_price_sync.daily_lookback_days' => 10]);

        $benchmark = app(RelativeStrengthService::class)->benchmarkStock();
        $start = now()->subMonths(7)->toDateString();
        foreach (range(0, 220) as $offset) {
            StockPrice::query()->create([
                'stock_id' => $benchmark->id,
                'price_date' => Carbon::parse($start)->addDays($offset)->toDateString(),
                'close_price' => 100 + $offset * 0.1,
                'adjusted_close_price' => 100 + $offset * 0.1,
                'provider_source' => 'test',
                'data_source' => 'test',
                'created_at' => now(),
            ]);
        }

        $priceFetch = Mockery::mock(PriceFetchService::class);
        $priceFetch->shouldReceive('syncStock')
            ->once()
            ->withArgs(function (Stock $stock, Carbon $from) use ($benchmark) {
                return $stock->id === $benchmark->id
                    && $from->lte(now()->subDays(14));
            })
            ->andReturn([
                'success' => true,
                'stored_rows' => 2,
                'fetched_rows' => 2,
                'errors' => [],
            ]);

        $logger = Mockery::mock(PortfolioLoggerService::class);
        $logger->shouldReceive('scheduler')->once();

        $service = new BenchmarkPriceSyncService(
            $priceFetch,
            app(RelativeStrengthService::class),
            app(StockPriceHistoryService::class),
            app(SettingsService::class),
            $logger,
        );

        $result = $service->syncIfNeeded(force: true);

        $this->assertFalse($result['full_history']);
        $this->assertTrue($result['success']);

        Carbon::setTestNow();
    }
}
