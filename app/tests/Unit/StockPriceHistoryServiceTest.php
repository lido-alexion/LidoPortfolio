<?php

namespace Tests\Unit;

use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\CalendarEvent;
use App\Services\IgnoredPriceGapService;
use App\Services\PortfolioLoggerService;
use App\Services\PriceFetchService;
use App\Services\StockPriceHistoryService;
use App\Support\TradingCalendar;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class StockPriceHistoryServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        TradingCalendar::clearHolidayCache();
        Mockery::close();
        parent::tearDown();
    }

    public function test_missing_range_when_no_local_data(): void
    {
        $stock = $this->makeStock('GAP');
        $service = $this->makeService();

        $ranges = $service->getMissingHistoryRanges(
            $stock,
            Carbon::parse('2024-01-01'),
            Carbon::parse('2024-01-31'),
        );

        $this->assertCount(1, $ranges);
        $this->assertSame('2024-01-01', $ranges[0]['from']->toDateString());
        $this->assertSame('2024-01-31', $ranges[0]['to']->toDateString());
    }

    public function test_no_missing_range_when_local_covers_required_period(): void
    {
        $stock = $this->makeStock('FULL');
$dates = [];
        for ($d = Carbon::parse('2024-01-01'); $d->lte(Carbon::parse('2024-04-01')); $d->addDay()) {
            $dates[] = $d->toDateString();
        }
        $this->seedPrices($stock, $dates);

        $service = $this->makeService();
        $ranges = $service->getMissingHistoryRanges(
            $stock,
            Carbon::parse('2024-02-01'),
            Carbon::parse('2024-03-01'),
        );

        $this->assertSame([], $ranges);
    }

    public function test_close_on_or_before_uses_previous_trading_day(): void
    {
        $stock = $this->makeStock('WEEK');
        StockPrice::query()->create([
            'stock_id' => $stock->id,
            'price_date' => '2026-02-27',
            'close_price' => 100,
            'adjusted_close_price' => 100,
            'provider_source' => 'test',
            'data_source' => 'test',
            'created_at' => now(),
        ]);

        $service = $this->makeService();
        $close = $service->getCloseOnOrBeforeDate($stock, Carbon::parse('2026-02-28'));

        $this->assertSame(100.0, $close);
    }

    public function test_growth_and_relative_strength_calculation(): void
    {
        $stock = $this->makeStock('GRW');
        $benchmark = $this->makeStock('NIFTY50', true);

        // Anchor and latest rows must land on equity sessions — getCloseOnOrBeforeDate
        // skips weekend/holiday price_date rows.
        $anchorDate = TradingCalendar::normalizeToSessionDate(now()->subMonths(1)->subDays(5))->toDateString();
        $latestDate = TradingCalendar::normalizeToSessionDate(now()->subDay())->toDateString();

        StockPrice::query()->create([
            'stock_id' => $stock->id,
            'price_date' => $anchorDate,
            'close_price' => 100,
            'adjusted_close_price' => 100,
            'provider_source' => 'test',
            'data_source' => 'test',
            'created_at' => now(),
        ]);
        StockPrice::query()->create([
            'stock_id' => $stock->id,
            'price_date' => $latestDate,
            'close_price' => 110,
            'adjusted_close_price' => 110,
            'provider_source' => 'test',
            'data_source' => 'test',
            'created_at' => now(),
        ]);

        StockPrice::query()->create([
            'stock_id' => $benchmark->id,
            'price_date' => $anchorDate,
            'close_price' => 200,
            'adjusted_close_price' => 200,
            'provider_source' => 'test',
            'data_source' => 'test',
            'created_at' => now(),
        ]);
        StockPrice::query()->create([
            'stock_id' => $benchmark->id,
            'price_date' => $latestDate,
            'close_price' => 210,
            'adjusted_close_price' => 210,
            'provider_source' => 'test',
            'data_source' => 'test',
            'created_at' => now(),
        ]);

        $service = $this->makeService();
        $growth = $service->getGrowthPercentage($stock, 1);
        $rs = $service->getRelativeStrength($stock, $benchmark, 1);

        $this->assertSame(10.0, $growth);
        $this->assertSame(5.0, $rs);
    }

    public function test_normalized_gain_series_uses_one_year_anchor(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-02 12:00:00', 'Asia/Kolkata'));

        $stock = $this->makeStock('NORM');
        $benchmark = $this->makeStock('NIFTY50', true);

        StockPrice::query()->create([
            'stock_id' => $stock->id,
            'price_date' => '2025-07-02',
            'close_price' => 100,
            'adjusted_close_price' => 100,
            'provider_source' => 'test',
            'data_source' => 'test',
            'created_at' => now(),
        ]);
        StockPrice::query()->create([
            'stock_id' => $stock->id,
            'price_date' => '2026-07-01',
            'close_price' => 200,
            'adjusted_close_price' => 200,
            'provider_source' => 'test',
            'data_source' => 'test',
            'created_at' => now(),
        ]);
        StockPrice::query()->create([
            'stock_id' => $benchmark->id,
            'price_date' => '2025-07-02',
            'close_price' => 1000,
            'adjusted_close_price' => 1000,
            'provider_source' => 'test',
            'data_source' => 'test',
            'created_at' => now(),
        ]);
        StockPrice::query()->create([
            'stock_id' => $benchmark->id,
            'price_date' => '2026-07-01',
            'close_price' => 1500,
            'adjusted_close_price' => 1500,
            'provider_source' => 'test',
            'data_source' => 'test',
            'created_at' => now(),
        ]);

        $service = $this->makeService();
        $series = $service->getNormalizedGainSeries(
            $stock,
            $benchmark,
            12,
            Carbon::parse('2026-07-02'),
        );

        Carbon::setTestNow();

        $this->assertCount(2, $series);
        $this->assertSame(0.0, $series[0]['stock_gain_percent']);
        $this->assertSame(0.0, $series[0]['benchmark_gain_percent']);
        $this->assertSame(100.0, $series[1]['stock_gain_percent']);
        $this->assertSame(50.0, $series[1]['benchmark_gain_percent']);
    }

    public function test_fetch_missing_history_fails_when_provider_returns_no_rows(): void
    {
        $stock = $this->makeStock('NODATA');
        $fetch = Mockery::mock(PriceFetchService::class);
        $fetch->shouldReceive('providerChainForStock')->andReturn(['nse', 'yahoo', 'alpha_vantage']);
        $fetch->shouldReceive('fetchFromProvider')->andReturn([
            'rows' => [],
            'errors' => ['nse: no rows'],
        ]);
        $this->app->instance(PriceFetchService::class, $fetch);

        $service = $this->makeService();
        $result = $service->fetchMissingHistory(
            $stock,
            Carbon::parse('2024-01-01'),
            Carbon::parse('2024-01-31'),
        );

        $this->assertFalse($result['success']);
        $this->assertGreaterThan(0, $result['gaps_remaining']);
        $this->assertNotEmpty($result['errors']);
        $this->assertNotEmpty($result['providers_tried']);
    }

    public function test_include_prelisting_prefix_remains_unsatisfied_after_fetch_checks(): void
    {
        $stock = $this->makeStock('BENCH', true);
        $this->seedPrices($stock, ['2025-01-20', '2025-01-21']);
        $fetch = Mockery::mock(PriceFetchService::class);
        $fetch->shouldReceive('providerChainForStock')->andReturn(['nse']);
        $fetch->shouldReceive('fetchFromProvider')->twice()->andReturn([
            'rows' => [],
            'errors' => ['nse: no historical prefix'],
        ]);
        $this->app->instance(PriceFetchService::class, $fetch);

        $service = $this->makeService();
        $result = $service->fetchMissingHistory(
            $stock,
            Carbon::parse('2024-01-01'),
            Carbon::parse('2025-01-21'),
            notifyTelegramOnFailure: false,
            includePreListingPrefix: true,
        );

        $this->assertFalse($result['success']);
        $this->assertGreaterThan(0, $result['gaps_remaining']);
        $this->assertSame('2024-01-01', $result['remaining_ranges'][0]['from']);
    }

    public function test_fetch_missing_history_succeeds_when_rows_close_gaps(): void
    {
        Carbon::setTestNow('2024-02-01');
        $stock = $this->makeStock('CLOSE');
        $dates = [];
        for ($d = Carbon::parse('2024-01-01'); $d->lte(Carbon::parse('2024-01-31')); $d->addDay()) {
            if (! $d->isWeekend()) {
                $dates[] = $d->toDateString();
            }
        }

        $rows = array_map(fn (string $date) => [
            'price_date' => $date,
            'open_price' => 100,
            'high_price' => 101,
            'low_price' => 99,
            'close_price' => 100,
            'volume' => 1000,
        ], $dates);

        $fetch = Mockery::mock(PriceFetchService::class);
        $fetch->shouldReceive('providerChainForStock')->andReturn(['nse']);
        $fetch->shouldReceive('fetchFromProvider')
            ->once()
            ->andReturn([
                'rows' => $rows,
                'errors' => [],
            ]);
        $fetch->shouldReceive('storeHistoricalRows')
            ->once()
            ->andReturnUsing(function (Stock $storedStock, array $storedRows, string $provider) {
                $count = 0;
                foreach ($storedRows as $row) {
                    StockPrice::query()->create([
                        'stock_id' => $storedStock->id,
                        'price_date' => $row['price_date'],
                        'open_price' => $row['open_price'],
                        'high_price' => $row['high_price'],
                        'low_price' => $row['low_price'],
                        'close_price' => $row['close_price'],
                        'volume' => $row['volume'],
                        'adjusted_close_price' => $row['close_price'],
                        'provider_source' => $provider,
                        'data_source' => $provider,
                        'created_at' => now(),
                    ]);
                    $count++;
                }

                return $count;
            });
        $this->app->instance(PriceFetchService::class, $fetch);

        $service = $this->makeService();
        $result = $service->fetchMissingHistory(
            $stock,
            Carbon::parse('2024-01-01'),
            Carbon::parse('2024-01-31'),
        );

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['gaps_remaining']);
        $this->assertGreaterThan(0, $result['stored_rows']);

        Carbon::setTestNow();
    }

    public function test_edge_gap_shorter_than_threshold_is_ignored_for_prefix_only(): void
    {
        Carbon::setTestNow('2026-07-10 13:39:00');
        config(['portfolio.history.max_internal_gap_days' => 7]);

        $stock = $this->makeStock('EDGE');
        // Short trailing hole (1 calendar day) must still be reported so sync fetches it.
        $dates = [];
        for ($d = Carbon::parse('2026-06-01'); $d->lte(Carbon::parse('2026-07-08')); $d->addDay()) {
            $dates[] = $d->toDateString();
        }
        $this->seedPrices($stock, $dates);

        $service = $this->makeService();
        $ranges = $service->getMissingHistoryRanges(
            $stock,
            Carbon::parse('2026-06-01'),
            Carbon::parse('2026-07-09'),
        );

        $this->assertCount(1, $ranges);
        $this->assertSame('2026-07-09', $ranges[0]['from']->toDateString());
        $this->assertSame('2026-07-09', $ranges[0]['to']->toDateString());

        Carbon::setTestNow();
    }

    public function test_suffix_edge_gap_is_reported_for_fetch(): void
    {
        Carbon::setTestNow('2026-07-10 13:39:00');
        config(['portfolio.history.max_internal_gap_days' => 7]);

        $stock = $this->makeStock('EDGE');
        $dates = [];
        for ($d = Carbon::parse('2026-06-01'); $d->lte(Carbon::parse('2026-06-30')); $d->addDay()) {
            $dates[] = $d->toDateString();
        }
        $this->seedPrices($stock, $dates);

        $service = $this->makeService();
        $ranges = $service->getMissingHistoryRanges(
            $stock,
            Carbon::parse('2026-06-01'),
            Carbon::parse('2026-07-09'),
        );

        $this->assertCount(1, $ranges);
        $this->assertSame('2026-07-01', $ranges[0]['from']->toDateString());
        $this->assertSame('2026-07-09', $ranges[0]['to']->toDateString());

        Carbon::setTestNow();
    }

    public function test_pre_listing_prefix_gap_before_first_stored_session_is_ignored(): void
    {
        config(['portfolio.history.max_internal_gap_days' => 7]);

        $stock = $this->makeStock('ADVANCE');
        $stock->forceFill(['created_at' => Carbon::parse('2025-10-01')])->save();
        $this->seedPrices($stock, ['2025-10-08', '2025-10-09', '2025-10-10']);

        $service = $this->makeService();
        $ranges = $service->getMissingHistoryRanges(
            $stock,
            Carbon::parse('2025-07-09'),
            Carbon::parse('2025-10-10'),
        );

        $this->assertSame([], $ranges);
    }

    public function test_established_stock_prefix_gap_is_still_reported(): void
    {
        config(['portfolio.history.max_internal_gap_days' => 7]);

        $stock = $this->makeStock('OLDCO');
        $stock->forceFill(['created_at' => Carbon::parse('2020-01-01')])->save();
        $this->seedPrices($stock, ['2025-10-08', '2025-10-09', '2025-10-10']);

        $service = $this->makeService();
        $ranges = $service->getMissingHistoryRanges(
            $stock,
            Carbon::parse('2025-07-09'),
            Carbon::parse('2025-10-10'),
        );

        $this->assertCount(1, $ranges);
        $this->assertSame('2025-07-09', $ranges[0]['from']->toDateString());
        $this->assertSame('2025-10-07', $ranges[0]['to']->toDateString());
    }

    public function test_cached_friday_needs_no_weekend_or_holiday_fetch(): void
    {
        $this->addClosedMonday();
        $stock = $this->makeStock('CLOSEDTAIL');
        $this->seedPrices($stock, ['2026-10-23']);
        $fetch = Mockery::mock(PriceFetchService::class);
        $fetch->shouldNotReceive('fetchFromProvider');
        $this->app->instance(PriceFetchService::class, $fetch);

        foreach (['2026-10-24', '2026-10-25', '2026-10-26'] as $end) {
            $result = $this->makeService()->fetchMissingHistory(
                $stock, Carbon::parse('2026-10-23'), Carbon::parse($end), false,
            );
            $this->assertTrue($result['success']);
            $this->assertTrue($result['cache_hit']);
            $this->assertSame([], $result['providers_tried']);
            $this->assertSame([], $result['remaining_ranges']);
            $this->assertSame(0, $result['stored_rows']);
        }
        $this->assertDatabaseCount('portfolio_stock_prices', 1);
    }

    public function test_closed_only_request_without_prices_does_not_fetch_or_invent_rows(): void
    {
        $stock = $this->makeStock('CLOSEDONLY');
        $fetch = Mockery::mock(PriceFetchService::class);
        $fetch->shouldNotReceive('fetchFromProvider');
        $this->app->instance(PriceFetchService::class, $fetch);

        $result = $this->makeService()->fetchMissingHistory(
            $stock, Carbon::parse('2026-10-24'), Carbon::parse('2026-10-25'), false,
        );

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['attempted_ranges']);
        $this->assertSame(0, $result['stored_rows']);
        $this->assertDatabaseCount('portfolio_stock_prices', 0);
    }

    public function test_closed_tail_keeps_a_real_missing_friday_and_reports_provider_failure(): void
    {
        $this->addClosedMonday();
        $stock = $this->makeStock('REALSUFFIX');
        $this->seedPrices($stock, ['2026-10-22']);
        $fetch = Mockery::mock(PriceFetchService::class);
        $fetch->shouldReceive('providerChainForStock')->once()->andReturn(['nse']);
        $fetch->shouldReceive('fetchFromProvider')->twice()
            ->withArgs(fn ($provider, $symbol, Carbon $from, Carbon $to) => $provider === 'nse'
                && $symbol === 'REALSUFFIX' && $from->toDateString() === '2026-10-23'
                && $to->toDateString() === '2026-10-23')
            ->andReturn(['rows' => [], 'errors' => ['nse: no data for required session']]);
        $fetch->shouldNotReceive('storeHistoricalRows');
        $this->app->instance(PriceFetchService::class, $fetch);

        $result = $this->makeService()->fetchMissingHistory(
            $stock, Carbon::parse('2026-10-22'), Carbon::parse('2026-10-26'), false,
        );

        $this->assertFalse($result['success']);
        $this->assertFalse($result['cache_hit']);
        $this->assertSame([['from' => '2026-10-23', 'to' => '2026-10-23']], $result['remaining_ranges']);
        $this->assertSame(['nse'], $result['providers_tried']);
        $this->assertNotEmpty($result['errors']);
        $this->assertSame(0, $result['stored_rows']);
        $this->assertDatabaseCount('portfolio_stock_prices', 1);
    }

    public function test_empty_and_cached_ranges_fetch_only_the_missing_session_after_a_closed_weekend(): void
    {
        $this->addClosedMonday();
        foreach ([false, true] as $cached) {
            $symbol = $cached ? 'SESSIONTAIL' : 'EMPTYSESSION';
            $stock = $this->makeStock($symbol);
            if ($cached) {
                $this->seedPrices($stock, ['2026-10-23']);
            }
            $fetch = Mockery::mock(PriceFetchService::class);
            $fetch->shouldReceive('providerChainForStock')->once()->andReturn(['nse']);
            $fetch->shouldReceive('fetchFromProvider')->twice()
                ->withArgs(fn ($provider, $requestedSymbol, Carbon $from, Carbon $to) => $provider === 'nse'
                    && $requestedSymbol === $symbol && $from->toDateString() === '2026-10-27'
                    && $to->toDateString() === '2026-10-27')
                ->andReturn(['rows' => [], 'errors' => ['nse: required session unavailable']]);
            $fetch->shouldNotReceive('storeHistoricalRows');
            $this->app->instance(PriceFetchService::class, $fetch);

            $result = $this->makeService()->fetchMissingHistory(
                $stock, Carbon::parse($cached ? '2026-10-23' : '2026-10-24'), Carbon::parse('2026-10-27'), false,
            );

            $this->assertFalse($result['success']);
            $this->assertSame([['from' => '2026-10-27', 'to' => '2026-10-27']], $result['attempted_ranges']);
            $this->assertSame($result['attempted_ranges'], $result['remaining_ranges']);
            $this->assertSame(0, $result['stored_rows']);
        }
    }

    public function test_established_prefix_range_trims_weekend_start_and_calendar_holiday_end(): void
    {
        $this->addClosedMonday();
        $stock = $this->makeStock('SESSIONPREFIX');
        $stock->forceFill(['created_at' => Carbon::parse('2020-01-01')])->save();
        $this->seedPrices($stock, ['2026-10-27']);
        $from = Carbon::parse('2026-10-17');
        $to = Carbon::parse('2026-10-27');

        $ranges = $this->makeService()->getMissingHistoryRanges($stock, $from, $to);

        $this->assertCount(1, $ranges);
        $this->assertSame('2026-10-19', $ranges[0]['from']->toDateString());
        $this->assertSame('2026-10-23', $ranges[0]['to']->toDateString());
        $this->assertSame('2026-10-17', $from->toDateString());
        $this->assertSame('2026-10-27', $to->toDateString());
    }

    public function test_long_internal_closure_is_a_cache_hit_without_provider_requests(): void
    {
        foreach (['2026-10-26', '2026-10-27', '2026-10-28', '2026-10-29', '2026-10-30', '2026-11-02'] as $day) {
            $this->addTradeHoliday($day);
        }
        config(['portfolio.history.max_internal_gap_days' => 7]);
        $stock = $this->makeStock('INTERNALCLOSED');
        $this->seedPrices($stock, ['2026-10-23', '2026-11-03']);
        $fetch = Mockery::mock(PriceFetchService::class);
        $fetch->shouldNotReceive('fetchFromProvider');
        $this->app->instance(PriceFetchService::class, $fetch);

        $result = $this->makeService()->fetchMissingHistory(
            $stock, Carbon::parse('2026-10-23'), Carbon::parse('2026-11-03'), false,
        );

        $this->assertTrue($result['success']);
        $this->assertTrue($result['cache_hit']);
        $this->assertSame([], $result['attempted_ranges']);
        $this->assertSame(0, $result['stored_rows']);
        $this->assertDatabaseCount('portfolio_stock_prices', 2);
    }

    public function test_internal_closure_preserves_a_real_missing_session_and_its_failure(): void
    {
        foreach (['2026-10-26', '2026-10-27', '2026-10-28', '2026-10-29', '2026-11-02'] as $day) {
            $this->addTradeHoliday($day);
        }
        config(['portfolio.history.max_internal_gap_days' => 7]);
        $stock = $this->makeStock('INTERNALREAL');
        $this->seedPrices($stock, ['2026-10-23', '2026-11-03']);
        $fetch = Mockery::mock(PriceFetchService::class);
        $fetch->shouldReceive('providerChainForStock')->once()->andReturn(['nse']);
        $fetch->shouldReceive('fetchFromProvider')->twice()
            ->withArgs(fn ($provider, $symbol, Carbon $from, Carbon $to) => $provider === 'nse'
                && $symbol === 'INTERNALREAL' && $from->toDateString() === '2026-10-30'
                && $to->toDateString() === '2026-10-30')
            ->andReturn(['rows' => [], 'errors' => ['nse: required Friday unavailable']]);
        $fetch->shouldNotReceive('storeHistoricalRows');
        $this->app->instance(PriceFetchService::class, $fetch);

        $result = $this->makeService()->fetchMissingHistory(
            $stock, Carbon::parse('2026-10-23'), Carbon::parse('2026-11-03'), false,
        );

        $this->assertFalse($result['success']);
        $this->assertSame([['from' => '2026-10-30', 'to' => '2026-10-30']], $result['remaining_ranges']);
        $this->assertNotEmpty($result['errors']);
        $this->assertDatabaseCount('portfolio_stock_prices', 2);
    }

    public function test_raw_and_session_normalized_ignored_ranges_remain_effective(): void
    {
        $this->addClosedMonday();
        foreach (['2026-10-24', '2026-10-27'] as $ignoredFrom) {
            $stock = $this->makeStock('IGNORED'.str_replace('-', '', $ignoredFrom));
            $this->seedPrices($stock, ['2026-10-23']);
            app(IgnoredPriceGapService::class)->ignore($stock->id, $ignoredFrom, '2026-10-27');

            $ranges = $this->makeService()->getMissingHistoryRanges(
                $stock, Carbon::parse('2026-10-23'), Carbon::parse('2026-10-27'),
            );

            $this->assertSame([], $ranges);
        }
    }

    private function addClosedMonday(): void
    {
        $this->addTradeHoliday('2026-10-26');
    }

    private function addTradeHoliday(string $date): void
    {
        CalendarEvent::query()->create([
            'profile_id' => null, 'category' => CalendarEvent::CATEGORY_TRADE_HOLIDAY,
            'title' => 'Test trade holiday', 'color' => CalendarEvent::TRADE_HOLIDAY_DEFAULT_COLOR,
            'anchor_date' => $date, 'recurrence_type' => CalendarEvent::RECURRENCE_NONE,
            'reminder_enabled' => false, 'is_active' => true,
        ]);
        TradingCalendar::clearHolidayCache();
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

    /**
     * @param  array<int, string>  $dates
     */
    protected function seedPrices(Stock $stock, array $dates): void
    {
        foreach ($dates as $date) {
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
    }

    protected function makeService(): StockPriceHistoryService
    {
        $logger = Mockery::mock(PortfolioLoggerService::class);
        $logger->shouldReceive('api')->andReturnNull();

        return new StockPriceHistoryService($logger, app(IgnoredPriceGapService::class));
    }
}
