<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\Setting;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\SyncRun;
use App\Services\BenchmarkPriceSyncService;
use App\Services\Bhavcopy\UdiffBhavcopyParser;
use App\Services\BseBhavcopyService;
use App\Services\NseBhavcopyService;
use App\Services\PriceFetchService;
use App\Services\SyncLogService;
use App\Services\UniversePrice\UniverseDailyBhavcopySyncService;
use App\Services\UniversePriceSyncService;
use App\Support\TradingCalendar;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\Concerns\BhavcopyFixtures;
use Tests\TestCase;

class UniverseDailyBhavcopySyncTest extends TestCase
{
    use BhavcopyFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-26 20:00:00');
        TradingCalendar::clearHolidayCache();
        config([
            'portfolio.universe_price_sync.enabled' => true,
            'portfolio.universe_price_sync.batch_size' => 1,
            'portfolio.universe_price_sync.daily_lookback_days' => 1,
            'portfolio.universe_price_sync.delay_ms_between_stocks' => 0,
        ]);
        Http::preventStrayRequests();
        $prices = Mockery::mock(PriceFetchService::class)->makePartial();
        $prices->shouldNotReceive('syncStock');
        $prices->shouldNotReceive('fetchHistoricalWithFallback');
        $this->app->instance(PriceFetchService::class, $prices);
        $benchmark = Mockery::mock(BenchmarkPriceSyncService::class);
        $benchmark->shouldReceive('syncIfNeeded')->andReturn(['skipped' => true, 'success' => true]);
        $this->app->instance(BenchmarkPriceSyncService::class, $benchmark);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        TradingCalendar::clearHolidayCache();
        Mockery::close();
        parent::tearDown();
    }

    private function stock(string $symbol = 'AAA', array $fields = []): Stock
    {
        return Stock::query()->create($fields + [
            'symbol' => $symbol, 'name' => $symbol, 'exchange' => 'NSE', 'series' => 'EQ',
            'isin' => 'INE001A01010', 'is_active' => true, 'is_benchmark' => false,
        ]);
    }

    private function source(string $exchange, array $rows, int $calls = 1): void
    {
        $class = $exchange === 'NSE' ? NseBhavcopyService::class : BseBhavcopyService::class;
        $source = Mockery::mock($class);
        $source->shouldReceive('fetchDailyRows')->times($calls)
            ->andReturnUsing(fn (Carbon $day) => (new UdiffBhavcopyParser)
                ->parse($this->bhavcopyCsv($exchange, $day->toDateString(), $rows), $day, $exchange));
        $this->app->instance($class, $source);
    }

    public function test_daily_sync_covers_full_canonical_scope_reuses_files_and_keeps_historical_cursor(): void
    {
        $a = $this->stock();
        $b = $this->stock('BBB', ['isin' => 'INE002A01010']);
        $c = $this->stock('CCC', ['exchange' => 'BSE', 'isin' => null, 'bse_scrip_code' => '500003']);
        $alias = $this->stock('AAA', ['exchange' => 'BSE', 'bse_scrip_code' => '500001']);
        $this->source('NSE', [[], ['FinInstrmId' => '1002', 'ISIN' => $b->isin, 'TckrSymb' => 'BBB']]);
        $this->source('BSE', [
            ['FinInstrmId' => '500003', 'TckrSymb' => 'CCC', 'ISIN' => 'INE003A01010', 'SctySrs' => 'MT'],
            ['FinInstrmId' => '500001', 'TckrSymb' => 'AAA'],
        ]);
        Setting::setValue(UniversePriceSyncService::KEY_CURSOR_STOCK_ID, (string) $b->id);
        $sync = app(UniversePriceSyncService::class);

        $first = $sync->sync(batchSize: 1);
        $ids = StockPrice::query()->orderBy('id')->pluck('id')->all();
        $second = $sync->sync(batchSize: 1);
        $status = $sync->status();

        $this->assertSame(3, $first['processed']);
        $this->assertSame(3, $first['succeeded']);
        $this->assertSame(3, $first['stored_rows']);
        $this->assertSame(2, $first['bulk']['files_downloaded']);
        $this->assertSame(0, $second['stored_rows']);
        $this->assertSame(3, $second['cache_hits']);
        $this->assertSame(2, $second['bulk']['file_cache_hits']);
        $this->assertSame(0, $second['bulk']['files_downloaded']);
        $this->assertSame($ids, StockPrice::query()->orderBy('id')->pluck('id')->all());
        $this->assertDatabaseMissing('portfolio_stock_prices', ['stock_id' => $alias->id]);
        $this->assertSame('nse_bhavcopy', StockPrice::query()->where('stock_id', $a->id)->value('provider_source'));
        $this->assertSame('bse_bhavcopy', StockPrice::query()->where('stock_id', $c->id)->value('provider_source'));
        $this->assertSame((string) $b->id, Setting::getValue(UniversePriceSyncService::KEY_CURSOR_STOCK_ID));
        $this->assertSame(100.0, (float) $status['progress_percent']);
        $this->assertSame(0, $status['remaining_in_cycle']);
        $this->assertSame('exchange_bhavcopy', $status['last_run']['collection_method']);
        Http::assertNothingSent();
    }

    public function test_new_membership_invalidates_receipts_and_restores_deleted_cached_rows(): void
    {
        $a = $this->stock();
        $this->source('NSE', [[], ['FinInstrmId' => '1002', 'ISIN' => 'INE002A01010', 'TckrSymb' => 'BBB']], 3);
        $sync = app(UniversePriceSyncService::class);
        $sync->sync();
        $b = $this->stock('BBB', ['isin' => 'INE002A01010']);
        $second = $sync->sync();
        StockPrice::query()->where('stock_id', $a->id)->delete();
        $third = $sync->sync();

        $this->assertSame(2, $second['processed']);
        $this->assertSame(1, $second['stored_rows']);
        $this->assertSame(1, $third['stored_rows']);
        $this->assertSame(1, $third['bulk']['files_downloaded']);
        $this->assertSame(1, StockPrice::query()->where('stock_id', $a->id)->count());
        $this->assertSame(1, StockPrice::query()->where('stock_id', $b->id)->count());
    }

    public function test_unavailable_stock_is_partial_without_any_legacy_provider_fallback(): void
    {
        $this->stock();
        $this->stock('MISSING', ['isin' => 'INE002A01010']);
        $this->source('NSE', [[]]);
        $result = app(UniversePriceSyncService::class)->sync();

        $this->assertSame(2, $result['processed']);
        $this->assertSame(1, $result['succeeded']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame(1, $result['bulk']['unavailable_stocks']);
        $this->assertSame('MISSING', $result['bulk']['unavailable_samples'][0]['symbol']);
        $this->assertSame('partial', SyncRun::query()->latest('finished_at')->value('status'));
        Http::assertNothingSent();
    }

    public function test_file_failure_is_visible_even_when_a_cached_price_already_exists(): void
    {
        $stock = $this->stock();
        app(PriceFetchService::class)->storeHistoricalRows($stock, [[
            'price_date' => '2026-10-26', 'open_price' => 100, 'high_price' => 105,
            'low_price' => 95, 'close_price' => 102, 'volume' => 1000,
        ]], 'manual');
        $source = Mockery::mock(NseBhavcopyService::class);
        $source->shouldReceive('fetchDailyRows')->once()->andThrow(new \RuntimeException('NSE daily bhavcopy download failed: HTTP 503'));
        $this->app->instance(NseBhavcopyService::class, $source);

        $result = app(UniversePriceSyncService::class)->sync();

        $this->assertSame(1, $result['succeeded']);
        $this->assertSame(1, $result['bulk']['file_errors']);
        $this->assertSame('partial', SyncRun::query()->latest('finished_at')->value('status'));
        $this->assertNull(Setting::getValue(UniverseDailyBhavcopySyncService::KEY_RECEIPTS));
        $this->assertSame('manual', StockPrice::query()->value('provider_source'));
    }

    public function test_rate_limit_counts_require_an_explicit_http_throttle_response(): void
    {
        $this->stock();
        $source = Mockery::mock(NseBhavcopyService::class);
        $source->shouldReceive('fetchDailyRows')->twice()->andThrowExceptions([
            new \RuntimeException('NSE connection timeout (request 4290)'),
            new \RuntimeException('NSE daily bhavcopy download failed: HTTP 429'),
        ]);
        $this->app->instance(NseBhavcopyService::class, $source);
        $sync = app(UniversePriceSyncService::class);

        $connectionFailure = $sync->sync();
        $throttle = $sync->sync();

        $this->assertSame(1, $connectionFailure['bulk']['file_errors']);
        $this->assertSame(0, $connectionFailure['rate_limit_hits']);
        $this->assertSame(1, $throttle['bulk']['file_errors']);
        $this->assertSame(1, $throttle['rate_limit_hits']);
    }

    public function test_recovery_skips_weekends_and_a_marked_holiday(): void
    {
        $stock = $this->stock();
        CalendarEvent::query()->create([
            'profile_id' => null, 'category' => CalendarEvent::CATEGORY_TRADE_HOLIDAY,
            'title' => 'Closed Monday', 'color' => CalendarEvent::TRADE_HOLIDAY_DEFAULT_COLOR,
            'anchor_date' => '2026-10-26', 'recurrence_type' => CalendarEvent::RECURRENCE_NONE,
            'is_active' => true, 'reminder_enabled' => false,
        ]);
        TradingCalendar::clearHolidayCache();
        $this->source('NSE', [[]], 2);
        $runId = app(SyncLogService::class)->beginRun('universe-price-sync');
        $stats = app(UniverseDailyBhavcopySyncService::class)->run(collect([$stock]),
            Carbon::parse('2026-10-23'), Carbon::parse('2026-10-27'), $runId, 'universe-price-sync',
            $this->initialStats());

        $this->assertSame(2, $stats['stats']['bulk']['files_downloaded']);
        $this->assertSame(['2026-10-23', '2026-10-27'], StockPrice::query()->orderBy('price_date')->get()
            ->map(fn ($row) => $row->price_date->toDateString())->all());
    }

    public function test_preview_is_read_only_and_does_not_create_applied_receipts(): void
    {
        $stock = $this->stock();
        $this->source('NSE', [[]]);
        $preview = app(UniverseDailyBhavcopySyncService::class)
            ->preview(collect([$stock]), Carbon::parse('2026-10-26'), Carbon::parse('2026-10-26'));

        $this->assertTrue($preview['bulk']['dry_run']);
        $this->assertSame(1, $preview['bulk']['projected_rows']);
        $this->assertSame(0, $preview['bulk']['file_errors']);
        $this->assertDatabaseCount('portfolio_stock_prices', 0);
        $this->assertNull(Setting::getValue(UniverseDailyBhavcopySyncService::KEY_RECEIPTS));
        $this->assertDatabaseCount('portfolio_sync_runs', 0);
    }

    public function test_busy_collection_lock_skips_without_clearing_another_owner(): void
    {
        $lock = Cache::store('database')->lock('portfolio-universe-price-sync', 60);
        $this->assertTrue($lock->get());
        try {
            $result = app(UniversePriceSyncService::class)->sync();
            $this->assertSame(1, $result['skipped']);
            $this->assertSame('exchange_bhavcopy', $result['collection_method']);
            $this->assertSame(0, $result['processed']);
            $this->assertDatabaseCount('portfolio_sync_runs', 0);
            $this->assertSame([], app(UniversePriceSyncService::class)->lastRunStats() ?? []);
        } finally {
            $lock->release();
        }
    }


    public function test_dated_isin_acknowledgement_preserves_cached_adjusted_prices_and_reuses_receipt(): void
    {
        $stocks = collect();
        $rows = [];
        foreach ([
            ['AZADIND', 'INE566M01025', 'INE566M01017'],
            ['BANSALWIRE', 'INE0B9K01033', 'INE0B9K01025'],
            ['JSWDULUX', 'INE133A01029', 'INE133A01011'],
        ] as $i => [$symbol, $current, $old]) {
            $stock = $this->stock($symbol, ['isin' => $current]);
            $stocks->push($stock);
            app(PriceFetchService::class)->storeHistoricalRows($stock, [[
                'price_date' => '2026-10-15', 'open_price' => 10, 'high_price' => 11,
                'low_price' => 9, 'close_price' => 10.5, 'adjusted_close' => 10.25, 'volume' => 5000,
            ]], 'adjusted_cache');
            $rows[] = ['FinInstrmId' => (string) (1001 + $i), 'TckrSymb' => $symbol, 'ISIN' => $old];
        }
        $before = StockPrice::query()->orderBy('id')->get()->map->getAttributes()->all();
        $this->source('NSE', $rows, 2);
        $service = app(UniverseDailyBhavcopySyncService::class);
        $day = Carbon::parse('2026-10-15');
        $runId = app(SyncLogService::class)->beginRun('universe-price-sync');
        $run = fn () => $service->run($stocks, $day, $day, $runId, 'universe-price-sync', $this->initialStats())['stats'];

        $first = $run();
        $second = $run();
        $this->assertSame(3, $first['bulk']['historical_identity_rows']);
        $this->assertSame(0, $first['bulk']['identity_conflicts']);
        $this->assertSame(0, $first['stored_rows']);
        $this->assertSame(1, $second['bulk']['file_cache_hits']);
        $this->assertSame(3, $second['bulk']['historical_identity_rows']);
        $this->assertSame(0, $second['bulk']['files_downloaded']);

        // Even a catalogue revision must revalidate cached file receipts.
        config(['universe_nse_price_identities.version' => 2]);
        $third = $run();
        $this->assertSame(1, $third['bulk']['files_downloaded']);
        $this->assertSame(0, $third['bulk']['file_cache_hits']);
        $this->assertSame($before, StockPrice::query()->orderBy('id')->get()->map->getAttributes()->all());
    }

    public function test_missing_pre_split_bar_remains_blocked_instead_of_inserting_raw_ohlcv(): void
    {
        $stock = $this->stock('AZADIND', ['isin' => 'INE566M01025']);
        $this->source('NSE', [['TckrSymb' => 'AZADIND', 'ISIN' => 'INE566M01017']]);
        $day = Carbon::parse('2026-10-15');
        $runId = app(SyncLogService::class)->beginRun('universe-price-sync');
        $result = app(UniverseDailyBhavcopySyncService::class)->run(
            collect([$stock]), $day, $day, $runId, 'universe-price-sync', $this->initialStats(),
        )['stats'];

        $this->assertSame(1, $result['bulk']['identity_conflicts']);
        $this->assertSame(1, $result['bulk']['historical_adjustment_required']);
        $this->assertSame(0, $result['bulk']['historical_identity_rows']);
        $this->assertSame(0, $result['stored_rows']);
        $this->assertSame(0, $result['bulk']['projected_rows']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame('historical_adjustment_required', $result['bulk']['unavailable_samples'][0]['reason']);
        $this->assertDatabaseCount('portfolio_stock_prices', 0);
        $this->assertNull(Setting::getValue(UniverseDailyBhavcopySyncService::KEY_RECEIPTS));
    }

    private function initialStats(): array
    {
        return ['processed' => 0, 'succeeded' => 0, 'failed' => 0, 'skipped' => 0,
            'stored_rows' => 0, 'cache_hits' => 0, 'rate_limit_hits' => 0, 'errors' => []];
    }
}
