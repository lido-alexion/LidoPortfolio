<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\DataQualityIssue;
use App\Models\User;
use App\Models\PriceAdjustmentFactor;
use App\Models\Setting;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\SyncRun;
use App\Services\BenchmarkPriceSyncService;
use App\Services\Bhavcopy\UdiffBhavcopyParser;
use App\Services\BseBhavcopyService;
use App\Services\CorporateActionPriceRepairService;
use App\Services\DataQualityResolutionService;
use App\Services\NseBhavcopyService;
use App\Services\PriceFetchService;
use App\Services\SyncLogService;
use App\Services\UniversePrice\UniverseDailyBhavcopySyncService;
use App\Services\UniversePrice\UniversePriceOutcomeService;
use App\Services\UniversePriceSyncService;
use App\Support\TradingCalendar;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
        Carbon::setTestNow(Carbon::parse('2026-10-26 14:30:00', 'UTC'));
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
            ->andReturnUsing(fn (Carbon $day) => $this->parsedSource($exchange, $day, $rows));
        $this->app->instance($class, $source);
    }

    private function parsedSource(string $exchange, Carbon $day, array $rows): array
    {
        $body = $this->bhavcopyCsv($exchange, $day->toDateString(), $rows);

        return (new UdiffBhavcopyParser)->parse($body, $day, $exchange)
            + ['source_revision_sha256' => hash('sha256', $body)];
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
        $this->assertSame(['NSE', 'BSE'], $first['bulk']['expected_exchanges']);
        $this->assertSame(['NSE', 'BSE'], array_column($first['bulk']['files'], 'exchange'));
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
        $this->assertDatabaseHas('portfolio_universe_price_session_outcomes', [
            'stock_id' => Stock::query()->where('symbol', 'MISSING')->value('id'),
            'session_date' => '2026-10-26', 'outcome' => 'no_data', 'price_present' => false,
        ]);
        $this->assertSame('partial', SyncRun::query()->latest('finished_at')->value('status'));
        $this->assertSame('SUCCESS', SyncRun::query()->latest('finished_at')->value('operational_outcome'), json_encode($result['bulk'], JSON_THROW_ON_ERROR));
        $this->assertSame(SyncRun::query()->latest('finished_at')->value('id'), json_decode(Setting::getValue(UniversePriceSyncService::KEY_LAST_RUN_JSON), true)['run_id'] ?? null);
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
        $saved = json_decode((string) Setting::getValue(UniverseDailyBhavcopySyncService::KEY_RECEIPTS), true);
        $this->assertSame(1, $saved['entries']['NSE:2026-10-26']['retry_attempt']);
        $this->assertDatabaseHas('portfolio_universe_price_session_outcomes', [
            'stock_id' => $stock->id, 'session_date' => '2026-10-26', 'outcome' => 'provider_error',
            'price_present' => true, 'failure_stage' => 'exchange_fetch',
        ]);
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
        Carbon::setTestNow(now()->addMinutes(5));
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
                'low_price' => 9, 'close_price' => 10.5, 'adjusted_close_price' => 10.25, 'volume' => 5000,
            ]], 'adjusted_cache');
            $rows[] = ['FinInstrmId' => (string) (1001 + $i), 'TckrSymb' => $symbol, 'ISIN' => $old];
        }
        $this->assertSame([10.25, 10.25, 10.25], StockPrice::query()->orderBy('id')->pluck('adjusted_close_price')->map(fn ($v) => (float) $v)->all());
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
        $saved = json_decode((string) Setting::getValue(UniverseDailyBhavcopySyncService::KEY_RECEIPTS), true);
        $this->assertSame(1, $saved['entries']['NSE:2026-10-15']['retry_attempt']);
    }

    public function test_bnagrochem_verified_historical_identity_preserves_adjusted_price_and_receipt(): void
    {
        $stock = $this->stock('BNAGROCHEM', ['isin' => 'INE00HZ01029']);
        $this->seedBnaGrochemRawPriceHistory($stock);
        $this->assertSame(3, StockPrice::query()->where('stock_id', $stock->id)->whereDate('price_date', '<', '2026-11-16')->count(), 'Raw bars remain available as repair evidence.');
        $writerCount = app(PriceFetchService::class)->storeHistoricalRows($stock, [[
            'price_date' => '2026-11-13', 'open_price' => 159, 'high_price' => 162.66,
            'low_price' => 158.68, 'close_price' => 999, 'adjusted_close_price' => 999, 'volume' => 101471,
        ]], 'yahoo');
        $this->assertSame(0, $writerCount);
        $this->assertEquals(162.66, (float) StockPrice::query()
            ->where('stock_id', $stock->id)->whereDate('price_date', '2026-11-13')->value('close_price'));
        $this->assertArrayHasKey($stock->id, app(\App\Services\DataQualityGuardService::class)
            ->blockedStockIdMap([$stock->id]));
        $universeQuery = (new \ReflectionMethod(\App\Services\ML\MlTrainingDatasetBuilder::class, 'universeQuery'))
            ->invoke(app(\App\Services\ML\MlTrainingDatasetBuilder::class));
        $this->assertNotContains($stock->id, $universeQuery->pluck('id')->all());
        $aliasedRows = StockPrice::query()->from('portfolio_stock_prices as p')
            ->where('p.stock_id', $stock->id)->whereDate('p.price_date', '<', '2026-11-16')->count();
        $this->assertSame(3, $aliasedRows, 'Aliased model reads preserve physical repair evidence.');
        $this->assertSame([17.08, 16.23], StockPrice::query()->where('stock_id', $stock->id)->whereDate('price_date', '>=', '2026-11-16')
            ->orderBy('price_date')->pluck('close_price')->map(fn ($value) => (float) $value)->all());
        $directRows = app(\App\Services\Bhavcopy\NseHistoricalPriceAdjustmentGate::class)
            ->apply(DB::table('portfolio_stock_prices'), 'portfolio_stock_prices')
            ->where('stock_id', $stock->id)->whereDate('price_date', '<', '2026-11-16')->count();
        $this->assertSame(0, $directRows);
        $screenerBars = app(\App\Services\Screener\ScreenerBacktestService::class)
            ->loadBarsWithDates($stock->id, '2026-11-17', 20);
        $this->assertSame(['2026-11-16', '2026-11-17'], array_column($screenerBars, 'date'));
        $factor = $this->bnagrochemPendingSplitFactor($stock);
        $repair = app(CorporateActionPriceRepairService::class)->repairPendingFactors(factorId: $factor->id, dryRun: false);
        $this->assertSame(1, $repair['repaired']);
        $this->assertSame(PriceAdjustmentFactor::REPAIR_STATUS_COMPLETED, $factor->fresh()->metadata['ohlcv_repair_status']);
        $proof = app(\App\Services\Bhavcopy\NseHistoricalPriceAdjustmentGate::class)->historyEvidence($stock->id, '2026-11-16');
        $this->assertSame($factor->fresh()->metadata['ohlcv_repair']['rows_sha256'], $proof['rows_sha256']);
        $this->assertSame(3, $proof['rows_adjusted']);
        $this->assertArrayNotHasKey($stock->id, app(\App\Services\DataQualityGuardService::class)
            ->blockedStockIdMap([$stock->id]), json_encode($factor->fresh()->metadata, JSON_THROW_ON_ERROR));
        $universeQuery = (new \ReflectionMethod(\App\Services\ML\MlTrainingDatasetBuilder::class, 'universeQuery'))
            ->invoke(app(\App\Services\ML\MlTrainingDatasetBuilder::class));
        $this->assertContains($stock->id, $universeQuery->pluck('id')->all());
        $this->assertSame(3, StockPrice::query()->where('stock_id', $stock->id)->whereDate('price_date', '<', '2026-11-16')->count());
        $this->assertSame([14.755, 15.492, 16.266], StockPrice::query()->where('stock_id', $stock->id)
            ->whereDate('price_date', '<', '2026-11-16')->orderBy('price_date')
            ->pluck('close_price')->map(fn ($value) => (float) $value)->all());
        $overwriteCount = app(PriceFetchService::class)->storeHistoricalRows($stock, [[
            'price_date' => '2026-11-13', 'open_price' => 159, 'high_price' => 162.66,
            'low_price' => 158.68, 'close_price' => 162.66, 'adjusted_close_price' => 162.66, 'volume' => 101471,
        ]], 'yahoo');
        $this->assertSame(0, $overwriteCount);
        $this->assertEquals(16.266, (float) StockPrice::query()->where('stock_id', $stock->id)
            ->whereDate('price_date', '2026-11-13')->value('close_price'));
        $screenerBars = app(\App\Services\Screener\ScreenerBacktestService::class)
            ->loadBarsWithDates($stock->id, '2026-11-17', 20);
        $this->assertSame(['2026-11-11', '2026-11-12', '2026-11-13', '2026-11-16', '2026-11-17'], array_column($screenerBars, 'date'));
        $this->assertSame([14.755, 15.492, 16.266], array_slice(array_column($screenerBars, 'close'), 0, 3));
        $this->assertSame([17.08, 16.23], StockPrice::query()->where('stock_id', $stock->id)
            ->whereDate('price_date', '>=', '2026-11-16')->orderBy('price_date')
            ->pluck('close_price')->map(fn ($value) => (float) $value)->all());
        $before = StockPrice::query()->where('stock_id', $stock->id)->whereDate('price_date', '2026-11-13')->first()->getAttributes();
        $this->assertEquals(16.266, (float) $before['close_price']);
        $this->source('NSE', [[
            'FinInstrmId' => '762608', 'TckrSymb' => 'BNAGROCHEM', 'ISIN' => 'INE00HZ01011',
            'SctySrs' => 'EQ', 'OpnPric' => '159.00', 'HghPric' => '162.66',
            'LwPric' => '158.68', 'ClsPric' => '162.66', 'TtlTradgVol' => '101471',
        ]], calls: 2);
        $service = app(UniverseDailyBhavcopySyncService::class);
        $day = Carbon::parse('2026-11-13');
        $runId = app(SyncLogService::class)->beginRun('universe-price-sync');
        $run = fn () => $service->run(collect([$stock]), $day, $day, $runId, 'universe-price-sync', $this->initialStats())['stats'];

        $first = $run();
        $second = $run();

        $this->assertSame(1, $first['bulk']['historical_identity_rows'], json_encode($first['bulk'], JSON_THROW_ON_ERROR));
        $this->assertSame(0, $first['bulk']['identity_conflicts']);
        $this->assertSame(0, $first['stored_rows']);
        $this->assertSame(1, $second['bulk']['file_cache_hits']);
        $this->assertSame($before, StockPrice::query()->where('stock_id', $stock->id)
            ->whereDate('price_date', '2026-11-13')->first()->getAttributes());
        $receipt = json_decode((string) Setting::getValue(UniverseDailyBhavcopySyncService::KEY_RECEIPTS), true);
        $this->assertSame($first['bulk']['files'][0]['source_revision_sha256'], $receipt['entries']['NSE:2026-11-13']['source_revision_sha256']);
        $this->assertSame(1, $receipt['entries']['NSE:2026-11-13']['historical_identity_rows']);

        // A same-count content change must fail the live fingerprint comparison
        // even if the persisted repair marker was not manually cleared.
        DB::table('portfolio_stock_prices')->where('stock_id', $stock->id)->whereDate('price_date', '2026-11-13')
            ->update(['close_price' => 162.66, 'adjusted_close_price' => 162.66]);
        $this->assertSame(3, StockPrice::query()->where('stock_id', $stock->id)
            ->whereDate('price_date', '<', '2026-11-16')->count());
        $afterTrustChange = $run();
        $this->assertSame(1, $afterTrustChange['bulk']['files_downloaded']);
        $this->assertSame(1, $afterTrustChange['bulk']['historical_adjustment_required']);
        $this->assertSame(0, $afterTrustChange['stored_rows']);
        $this->assertArrayHasKey($stock->id, app(\App\Services\DataQualityGuardService::class)
            ->blockedStockIdMap([$stock->id]));
    }

    public function test_later_split_restatement_refreshes_overlapping_proof_without_double_adjustment(): void
    {
        $stock = $this->stock('BNAGROCHEM', ['isin' => 'INE00HZ01029']);
        $this->seedBnaGrochemRawPriceHistory($stock);
        $first = $this->bnagrochemPendingSplitFactor($stock);
        $repair = app(CorporateActionPriceRepairService::class);

        $firstResult = $repair->repairPendingFactors(factorId: $first->id, dryRun: false);
        $this->assertSame(1, $firstResult['repaired'], json_encode($firstResult, JSON_THROW_ON_ERROR));
        $this->assertSame(16.266, (float) StockPrice::query()->where('stock_id', $stock->id)
            ->whereDate('price_date', '2026-11-13')->value('close_price'));

        $duplicate = PriceAdjustmentFactor::query()->create([
            'stock_id' => $stock->id, 'issue_id' => null, 'factor_type' => 'corporate_action',
            'action_type' => 'split', 'effective_ex_date' => '2026-11-16',
            'applied_ratio' => 10, 'price_divisor' => 10, 'volume_multiplier' => 10,
            'is_active' => true, 'applied_at' => now(),
            'metadata' => [
                'source' => 'duplicate_test_factor',
                'divisor_provenance' => 'legacy_stored_factors',
                'ohlcv_repair_status' => PriceAdjustmentFactor::REPAIR_STATUS_PENDING,
            ],
        ]);
        $duplicateResult = $repair->repairPendingFactors(factorId: $duplicate->id, dryRun: false);
        $this->assertSame(0, $duplicateResult['repaired']);
        $this->assertSame(CorporateActionPriceRepairService::STATUS_DEFERRED_TO_FACTOR, $duplicateResult['details'][0]['status']);
        $this->assertSame(16.266, (float) StockPrice::query()->where('stock_id', $stock->id)
            ->whereDate('price_date', '2026-11-13')->value('close_price'));

        $later = PriceAdjustmentFactor::query()->create([
            'stock_id' => $stock->id, 'issue_id' => null, 'factor_type' => 'corporate_action',
            'action_type' => 'split', 'effective_ex_date' => '2026-11-24',
            'applied_ratio' => 2, 'price_divisor' => 2, 'volume_multiplier' => 2,
            'is_active' => true, 'applied_at' => now(),
            'metadata' => [
                'source' => 'test_reviewed_evidence',
                'divisor_provenance' => 'legacy_stored_factors',
                'ohlcv_repair_status' => PriceAdjustmentFactor::REPAIR_STATUS_PENDING,
            ],
        ]);

        $laterResult = $repair->repairPendingFactors(factorId: $later->id, dryRun: false);
        $this->assertSame(1, $laterResult['repaired']);
        $this->assertEquals(8.133, (float) StockPrice::query()->where('stock_id', $stock->id)
            ->whereDate('price_date', '2026-11-13')->value('close_price'));
        $this->assertEquals(8.54, (float) StockPrice::query()->where('stock_id', $stock->id)
            ->whereDate('price_date', '2026-11-16')->value('close_price'));

        $gate = app(\App\Services\Bhavcopy\NseHistoricalPriceAdjustmentGate::class);
        $firstEvidence = $gate->historyEvidence((int) $stock->id, '2026-11-16');
        $this->assertSame($firstEvidence['rows_sha256'], $first->fresh()->metadata['ohlcv_repair']['rows_sha256']);
        $this->assertArrayNotHasKey($stock->id, app(\App\Services\DataQualityGuardService::class)
            ->blockedStockIdMap([$stock->id]));

        $again = $repair->repairPendingFactors(factorId: $first->id, dryRun: false);
        $this->assertSame(0, $again['repaired']);
        $this->assertEquals(8.133, (float) StockPrice::query()->where('stock_id', $stock->id)
            ->whereDate('price_date', '2026-11-13')->value('close_price'));
    }

    public function test_overlapping_restatement_refuses_a_stale_prior_content_proof(): void
    {
        $stock = $this->stock('BNAGROCHEM', ['isin' => 'INE00HZ01029']);
        $this->seedBnaGrochemRawPriceHistory($stock);
        $repair = app(CorporateActionPriceRepairService::class);
        $first = $this->bnagrochemPendingSplitFactor($stock);
        $this->assertSame(1, $repair->repairPendingFactors(factorId: $first->id, dryRun: false)['repaired']);

        DB::table('portfolio_stock_prices')->where('stock_id', $stock->id)->whereDate('price_date', '2026-11-13')
            ->update(['close_price' => 16.267]);
        $later = PriceAdjustmentFactor::query()->create([
            'stock_id' => $stock->id, 'issue_id' => null, 'factor_type' => 'corporate_action',
            'action_type' => 'split', 'effective_ex_date' => '2026-11-24',
            'applied_ratio' => 2, 'price_divisor' => 2, 'volume_multiplier' => 2,
            'is_active' => true, 'applied_at' => now(),
            'metadata' => [
                'source' => 'stale_overlap_test',
                'divisor_provenance' => 'legacy_stored_factors',
                'ohlcv_repair_status' => PriceAdjustmentFactor::REPAIR_STATUS_PENDING,
            ],
        ]);

        $result = $repair->repairPendingFactors(factorId: $later->id, dryRun: false);
        $this->assertSame(0, $result['repaired']);
        $this->assertSame(CorporateActionPriceRepairService::STATUS_INVALID_FACTOR, $result['details'][0]['status']);
        $this->assertSame(16.267, (float) StockPrice::query()->where('stock_id', $stock->id)
            ->whereDate('price_date', '2026-11-13')->value('close_price'));
        $this->assertSame(17.08, (float) StockPrice::query()->where('stock_id', $stock->id)
            ->whereDate('price_date', '2026-11-16')->value('close_price'));
        $this->assertSame(PriceAdjustmentFactor::REPAIR_STATUS_PENDING, $later->fresh()->metadata['ohlcv_repair_status']);
    }

    public function test_bnagrochem_missing_adjusted_history_blocks_raw_split_bar_and_keeps_retry_receipt(): void
    {
        $stock = $this->stock('BNAGROCHEM', ['isin' => 'INE00HZ01029']);
        $this->seedBnaGrochemRawPriceHistory($stock);
        $this->bnagrochemPendingSplitFactor($stock);
        $this->source('NSE', [[
            'FinInstrmId' => '762608', 'TckrSymb' => 'BNAGROCHEM', 'ISIN' => 'INE00HZ01011',
            'SctySrs' => 'EQ', 'OpnPric' => '159.00', 'HghPric' => '162.66',
            'LwPric' => '158.68', 'ClsPric' => '162.66', 'TtlTradgVol' => '101471',
        ]]);
        $day = Carbon::parse('2026-11-13');
        $runId = app(SyncLogService::class)->beginRun('universe-price-sync');
        $result = app(UniverseDailyBhavcopySyncService::class)->run(
            collect([$stock]), $day, $day, $runId, 'universe-price-sync', $this->initialStats(),
        )['stats'];

        $this->assertSame(0, $result['bulk']['historical_identity_rows']);
        $this->assertSame(1, $result['bulk']['historical_adjustment_required']);
        $this->assertSame(0, $result['stored_rows']);
        $this->assertSame(0, $result['bulk']['projected_rows']);
        $this->assertSame([147.55, 154.92, 162.66], StockPrice::query()
            ->where('stock_id', $stock->id)->whereDate('price_date', '<', '2026-11-16')
            ->orderBy('price_date')->pluck('close_price')->map(fn ($value) => (float) $value)->all());
        $this->assertSame(3, StockPrice::query()->where('stock_id', $stock->id)->whereDate('price_date', '<', '2026-11-16')->count());
        $receipt = json_decode((string) Setting::getValue(UniverseDailyBhavcopySyncService::KEY_RECEIPTS), true);
        $this->assertSame(1, $receipt['entries']['NSE:2026-11-13']['retry_attempt']);
        $this->assertSame('2026-10-26 14:35:00', $receipt['entries']['NSE:2026-11-13']['retry_after']);
        $this->assertSame('historical_adjustment_required', $receipt['entries']['NSE:2026-11-13']['identity_conflict_samples'][0]['reason']);
    }

    public function test_legacy_completed_factor_without_content_fingerprint_remains_quarantined(): void
    {
        $stock = $this->stock('BNAGROCHEM', ['isin' => 'INE00HZ01029']);
        $this->seedBnaGrochemRawPriceHistory($stock);
        $factor = $this->bnagrochemPendingSplitFactor($stock);
        $factor->update(['metadata' => [
            'source' => 'legacy_record', 'ohlcv_repair_status' => PriceAdjustmentFactor::REPAIR_STATUS_COMPLETED,
            'ohlcv_repair' => ['rows_adjusted' => 3, 'price_divisor' => 10],
        ]]);

        $this->assertSame(3, StockPrice::query()->where('stock_id', $stock->id)
            ->whereDate('price_date', '<', '2026-11-16')->count());
        $this->assertArrayHasKey($stock->id, app(\App\Services\DataQualityGuardService::class)
            ->blockedStockIdMap([$stock->id]));
    }

    public function test_corporate_action_detection_can_read_quarantined_physical_bars(): void
    {
        $stock = $this->stock('BNAGROCHEM', ['isin' => 'INE00HZ01029']);
        foreach ([
            ['2026-11-12', 160.0, 162.66],
            ['2026-11-13', 16.0, 16.26],
        ] as [$date, $open, $close]) {
            DB::table('portfolio_stock_prices')->insert([
                'stock_id' => $stock->id, 'price_date' => $date.' 00:00:00', 'open_price' => $open,
                'high_price' => max($open, $close), 'low_price' => min($open, $close), 'close_price' => $close,
                'adjusted_close_price' => $close, 'volume' => 100, 'provider_source' => 'test_raw',
                'data_source' => 'test_raw', 'created_at' => now(),
            ]);
        }

        $this->assertSame(2, StockPrice::query()->where('stock_id', $stock->id)
            ->whereDate('price_date', '<', '2026-11-16')->count());
        $detected = app(\App\Services\DataQualityCorporateActionHeuristicService::class)
            ->scanStock($stock, minGapPercent: 25.0);

        // The detector bypasses only the read quarantine so its physical OHLCV
        // evidence remains available for independent human review.
        $this->assertTrue($detected);
    }

    public function test_retry_stays_deferred_with_unrelated_coverage_then_revalidates_after_split_repair(): void
    {
        $stock = $this->stock('BNAGROCHEM', ['isin' => 'INE00HZ01029']);
        $unrelated = $this->stock('OTHER');
        $this->seedBnaGrochemRawPriceHistory($stock);
        app(PriceFetchService::class)->storeHistoricalRows($unrelated, [[
            'price_date' => '2026-11-13', 'open_price' => 100, 'high_price' => 101,
            'low_price' => 99, 'close_price' => 100, 'volume' => 100,
        ]], 'manual');
        $factor = $this->bnagrochemPendingSplitFactor($stock);
        $source = Mockery::mock(NseBhavcopyService::class);
        $source->shouldReceive('fetchDailyRows')->twice()->andReturnUsing(fn (Carbon $day) => $this->parsedSource('NSE', $day, [[
            'FinInstrmId' => '762608', 'TckrSymb' => 'BNAGROCHEM', 'ISIN' => 'INE00HZ01011',
            'SctySrs' => 'EQ', 'OpnPric' => '159.00', 'HghPric' => '162.66',
            'LwPric' => '158.68', 'ClsPric' => '162.66', 'TtlTradgVol' => '101471',
        ]]));
        $this->app->instance(NseBhavcopyService::class, $source);
        $day = Carbon::parse('2026-11-13');
        $runId = app(SyncLogService::class)->beginRun('universe-price-sync');
        $service = app(UniverseDailyBhavcopySyncService::class);
        $stocks = collect([$stock, $unrelated]);
        $run = fn () => $service->run($stocks, $day, $day, $runId, 'universe-price-sync', $this->initialStats())['stats'];

        $first = $run();
        $receipts = json_decode((string) Setting::getValue(UniverseDailyBhavcopySyncService::KEY_RECEIPTS), true);
        $receipts['entries']['NSE:2026-11-13']['retry_after'] = '2026-11-30T20:00:00+05:30';
        Setting::setValue(UniverseDailyBhavcopySyncService::KEY_RECEIPTS, json_encode($receipts, JSON_THROW_ON_ERROR));
        $stillRaw = $run();
        $this->assertSame(1, $stillRaw['bulk']['backoff_skips']);
        $this->assertSame('2026-11-30T20:00:00+05:30', $stillRaw['bulk']['files'][0]['retry_after']);
        $this->assertSame(0, $stillRaw['bulk']['files_downloaded']);

        $repair = app(CorporateActionPriceRepairService::class)->repairPendingFactors(factorId: $factor->id, dryRun: false);
        $this->assertSame(1, $repair['repaired']);
        $adjusted = $run();

        $this->assertSame(0, $adjusted['bulk']['backoff_skips']);
        $this->assertSame(1, $adjusted['bulk']['files_downloaded']);
        $this->assertSame(0, $adjusted['bulk']['identity_conflicts']);
        $this->assertSame(1, $first['bulk']['historical_adjustment_required']);
        $this->assertSame([14.755, 15.492, 16.266], StockPrice::query()->where('stock_id', $stock->id)
            ->whereDate('price_date', '<', '2026-11-16')->orderBy('price_date')
            ->pluck('close_price')->map(fn ($value) => (float) $value)->all());
        app(PriceFetchService::class)->storeHistoricalRows($stock, [[
            'price_date' => '2026-11-10', 'open_price' => 140, 'high_price' => 141,
            'low_price' => 139, 'close_price' => 140, 'volume' => 100,
        ]], 'late_raw_backfill');
        $this->assertDatabaseMissing('portfolio_stock_prices', ['stock_id' => $stock->id, 'price_date' => '2026-11-10']);
    }

    private function seedBnaGrochemRawPriceHistory(Stock $stock): void
    {
        $rows = [
            ['price_date' => '2026-11-11', 'open_price' => 147.55, 'high_price' => 147.55, 'low_price' => 147.55, 'close_price' => 147.55, 'adjusted_close_price' => 147.55, 'volume' => 100],
            ['price_date' => '2026-11-12', 'open_price' => 154.92, 'high_price' => 154.92, 'low_price' => 154.92, 'close_price' => 154.92, 'adjusted_close_price' => 154.92, 'volume' => 100],
            ['price_date' => '2026-11-13', 'open_price' => 162.66, 'high_price' => 162.66, 'low_price' => 162.66, 'close_price' => 162.66, 'adjusted_close_price' => 162.66, 'volume' => 101471],
            ['price_date' => '2026-11-16', 'open_price' => 17.08, 'high_price' => 17.08, 'low_price' => 17.08, 'close_price' => 17.08, 'adjusted_close_price' => 17.08, 'volume' => 1000],
            ['price_date' => '2026-11-17', 'open_price' => 16.23, 'high_price' => 16.23, 'low_price' => 16.23, 'close_price' => 16.23, 'adjusted_close_price' => 16.23, 'volume' => 1000],
        ];
        foreach ($rows as $row) {
            $row['price_date'] .= ' 00:00:00';
            DB::table('portfolio_stock_prices')->insert($row + [
                'stock_id' => $stock->id, 'provider_source' => 'nse_bhavcopy',
                'data_source' => 'nse_bhavcopy', 'created_at' => now(),
            ]);
        }
    }

    private function bnagrochemPendingSplitFactor(Stock $stock): PriceAdjustmentFactor
    {
        return PriceAdjustmentFactor::query()->create([
            'stock_id' => $stock->id, 'issue_id' => null, 'factor_type' => 'corporate_action',
            'action_type' => 'face_value_split', 'effective_ex_date' => '2026-11-16',
            'applied_ratio' => 10, 'price_divisor' => 10, 'volume_multiplier' => 10,
            'is_active' => true, 'applied_at' => now(),
            // This unlinked factor deliberately exercises the explicitly
            // supported legacy stored-divisor path. Production-approved
            // factors must be linked to a human accepted issue instead.
            'metadata' => [
                'source' => 'test_reviewed_evidence',
                'divisor_provenance' => 'legacy_stored_factors',
                'ohlcv_repair_status' => PriceAdjustmentFactor::REPAIR_STATUS_PENDING,
            ],
        ]);
    }

    public function test_current_vgl_conflict_receipt_retries_after_approved_isin_transition(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-27 14:30:00', 'UTC'));
        $fixture = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/vgl-nse-bhavcopy-2026-11-27.json'), true, flags: JSON_THROW_ON_ERROR);
        $sourceRow = $fixture['row'];
        $stock = Stock::query()->forceCreate([
            'id' => 2030, 'symbol' => 'VGL', 'name' => 'VARVEE GLOBAL LIMITED', 'exchange' => 'NSE',
            'series' => 'EQ', 'isin' => 'INE273D01035', 'is_active' => true, 'is_benchmark' => false,
        ]);
        $this->source('NSE', [[
            'TradDt' => '2026-11-27', 'FinInstrmId' => $sourceRow['instrument_id'], 'ISIN' => $sourceRow['isin'],
            'TckrSymb' => $sourceRow['symbol'], 'SctySrs' => $sourceRow['series'],
            'OpnPric' => $sourceRow['open'], 'HghPric' => $sourceRow['high'], 'LwPric' => $sourceRow['low'],
            'ClsPric' => $sourceRow['close'], 'TtlTradgVol' => $sourceRow['volume'],
        ]], calls: 2);
        $runId = app(SyncLogService::class)->beginRun('universe-price-sync');
        $service = app(UniverseDailyBhavcopySyncService::class);
        $first = $service->run(collect([$stock]), Carbon::parse('2026-11-27'), Carbon::parse('2026-11-27'), $runId, 'universe-price-sync', $this->initialStats())['stats'];
        $this->assertSame(0, $first['bulk']['identity_conflicts']);
        $saved = json_decode((string) Setting::getValue(UniverseDailyBhavcopySyncService::KEY_RECEIPTS), true);
        $receipt = $saved['entries']['NSE:2026-11-27'];
        $receipt['identity_conflicts'] = 1;
        $receipt['identity_conflict_samples'] = [['stock_id' => 2030, 'reason' => 'conflicting_identity_or_rows']];
        $receipt['retry_attempt'] = 4;
        $receipt['retry_after'] = '2026-12-04T20:00:00+05:30';
        $saved['entries']['NSE:2026-11-27'] = $receipt;
        Setting::setValue(UniverseDailyBhavcopySyncService::KEY_RECEIPTS, json_encode($saved, JSON_THROW_ON_ERROR));

        $issue = DataQualityIssue::query()->create([
            'stock_id' => 2030, 'symbol' => 'VGL', 'issue_type' => DataQualityIssue::TYPE_CORPORATE_ACTION,
            'issue_status' => DataQualityIssue::STATUS_PENDING_REVIEW, 'detection_method' => DataQualityIssue::DETECTION_METHOD_LEGACY_MANUAL,
            'detection_source' => 'test_admin_review', 'suggested_ratio' => 2.5, 'corporate_action_type' => 'split',
            'ex_date' => '2026-11-16', 'detected_at' => now(),
        ]);
        $admin = User::factory()->create(['is_admin' => true]);
        app(DataQualityResolutionService::class)->accept(
            $issue, 2.5, 'Independent review test', $admin->id, adminManualOverride: true,
            manualOverrideContext: ['historical_isin' => 'INE273D01027', 'historical_valid_from' => '2026-01-01'],
        );

        $second = $service->run(collect([$stock]), Carbon::parse('2026-11-27'), Carbon::parse('2026-11-27'), $runId, 'universe-price-sync', $this->initialStats())['stats'];
        $this->assertSame(0, $second['bulk']['backoff_skips']);
        $this->assertSame(1, $second['bulk']['files_downloaded']);
        $this->assertSame(0, $second['bulk']['identity_conflicts']);
        $this->assertSame('applied', $second['bulk']['files'][0]['status']);
        $this->assertSame(1, $second['bulk']['files'][0]['matched_rows']);
    }

    public function test_identity_conflicts_are_logged_as_bounded_stock_id_reasons_and_never_assigned(): void
    {
        $stock = $this->stock('AAA', ['isin' => 'INE001A01010']);
        // Symbol/series matches, but the source ISIN identifies a different
        // security. The matcher must fail closed and report why.
        $this->source('NSE', [['TckrSymb' => 'AAA', 'ISIN' => 'INE002A01010']]);
        $day = Carbon::parse('2026-10-26');
        $runId = app(SyncLogService::class)->beginRun('universe-price-sync');

        $result = app(UniverseDailyBhavcopySyncService::class)->run(
            collect([$stock]), $day, $day, $runId, 'universe-price-sync', $this->initialStats(),
        )['stats'];

        $file = $result['bulk']['files'][0];
        $this->assertSame('identity_conflict', $file['status']);
        $this->assertSame(1, $file['identity_conflicts']);
        $this->assertSame([['stock_id' => $stock->id, 'reason' => 'conflicting_identity_or_rows']], $file['identity_conflict_samples']);
        $this->assertFalse($file['identity_conflict_samples_truncated']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $file['source_revision_sha256']);
        $this->assertSame(0, $file['stored_rows']);
        $this->assertDatabaseMissing('portfolio_stock_prices', ['stock_id' => $stock->id]);
        $saved = json_decode((string) Setting::getValue(UniverseDailyBhavcopySyncService::KEY_RECEIPTS), true);
        $this->assertSame($file['source_revision_sha256'], $saved['entries']['NSE:2026-10-26']['source_revision_sha256']);
        $this->assertSame(1, $saved['entries']['NSE:2026-10-26']['retry_attempt']);
    }

    public function test_file_diagnostics_preserve_parser_and_matcher_row_accounting(): void
    {
        $this->stock('AAA');
        $this->source('NSE', [
            [],
            ['FinInstrmId' => '1002', 'TckrSymb' => 'OTHER', 'ISIN' => 'INE002A01010'],
            ['FinInstrmId' => '1003', 'TckrSymb' => 'INVALID', 'ISIN' => 'INE003A01010', 'OpnPric' => '0'],
            ['FinInstrmId' => '1004', 'TckrSymb' => 'FUTURE', 'FinInstrmTp' => 'FUT'],
        ]);
        $day = Carbon::parse('2026-10-26');
        $runId = app(SyncLogService::class)->beginRun('universe-price-sync');

        $result = app(UniverseDailyBhavcopySyncService::class)->run(
            Stock::query()->get(), $day, $day, $runId, 'universe-price-sync', $this->initialStats(),
        )['stats'];

        $file = $result['bulk']['files'][0];
        $this->assertSame(4, $file['source_rows']);
        $this->assertSame(1, $file['invalid_rows']);
        $this->assertSame(1, $file['excluded_rows']);
        $this->assertSame(1, $file['unmatched_source_rows']);
        $this->assertSame(1, $file['matched_rows']);
    }

    public function test_unchanged_conflict_is_deferred_but_revised_source_is_revalidated_and_applied(): void
    {
        $stock = $this->stock();
        $service = Mockery::mock(NseBhavcopyService::class);
        $service->shouldReceive('fetchDailyRows')->twice()->andReturnUsing(function (Carbon $day) {
            static $calls = 0;
            $calls++;
            return $this->parsedSource('NSE', $day, $calls === 1
                ? [['TckrSymb' => 'AAA', 'ISIN' => 'INE999A01010']]
                : [['TckrSymb' => 'AAA', 'ISIN' => 'INE001A01010']]);
        });
        $this->app->instance(NseBhavcopyService::class, $service);
        $day = Carbon::parse('2026-10-26');
        $runId = app(SyncLogService::class)->beginRun('universe-price-sync');
        $sync = app(UniverseDailyBhavcopySyncService::class);
        $run = fn () => $sync->run(collect([$stock]), $day, $day, $runId, 'universe-price-sync', $this->initialStats())['stats'];

        $first = $run();
        $second = $run();
        $this->assertSame(1, $first['bulk']['identity_conflicts']);
        $this->assertSame(1, $second['bulk']['backoff_skips']);
        $this->assertSame('retry_deferred', $second['bulk']['files'][0]['status']);
        $this->assertDatabaseMissing('portfolio_stock_prices', ['stock_id' => $stock->id]);

        Carbon::setTestNow(now()->addMinutes(5));
        $third = $run();
        $this->assertSame(0, $third['failed']);
        $this->assertSame(1, $third['bulk']['files_downloaded']);
        $this->assertDatabaseHas('portfolio_stock_prices', ['stock_id' => $stock->id, 'price_date' => '2026-10-26 00:00:00']);
    }

    public function test_identity_catalogue_revision_preserves_pending_retry_for_unchanged_scope(): void
    {
        $stock = $this->stock();
        $this->source('NSE', [['TckrSymb' => 'AAA', 'ISIN' => 'INE999A01010']]);
        $day = Carbon::parse('2026-10-26');
        $runId = app(SyncLogService::class)->beginRun('universe-price-sync');
        $service = app(UniverseDailyBhavcopySyncService::class);
        $first = $service->run(collect([$stock]), $day, $day, $runId, 'universe-price-sync', $this->initialStats())['stats'];
        $before = json_decode((string) Setting::getValue(UniverseDailyBhavcopySyncService::KEY_RECEIPTS), true);
        $savedRetry = Carbon::parse('2026-11-30 20:00:00', 'Asia/Kolkata')->toIso8601String();
        $before['entries']['NSE:2026-10-26']['retry_after'] = $savedRetry;
        Setting::setValue(UniverseDailyBhavcopySyncService::KEY_RECEIPTS, json_encode($before, JSON_THROW_ON_ERROR));

        config(['universe_nse_price_identities.version' => 2]);
        $second = $service->run(collect([$stock]), $day, $day, $runId, 'universe-price-sync', $this->initialStats())['stats'];
        $after = json_decode((string) Setting::getValue(UniverseDailyBhavcopySyncService::KEY_RECEIPTS), true);

        $this->assertSame(1, $second['bulk']['backoff_skips']);
        $this->assertSame('retry_deferred', $second['bulk']['files'][0]['status']);
        $this->assertSame($savedRetry, $second['bulk']['files'][0]['retry_after']);
        $this->assertSame(1, $after['entries']['NSE:2026-10-26']['retry_attempt']);
        $this->assertSame($savedRetry, $after['entries']['NSE:2026-10-26']['retry_after']);
        $this->assertSame(1, $first['bulk']['identity_conflicts']);
    }

    public function test_legacy_receipt_keeps_retry_only_when_previous_identity_fingerprint_proves_same_scope(): void
    {
        $stock = $this->stock();
        $currentDocument = config('universe_nse_price_identities');
        $oldDocument = $currentDocument;
        unset($oldDocument['transitions']['CML77412']);
        $oldDocument['identities'] = array_values(array_filter($oldDocument['identities'], fn ($identity) => $identity['symbol'] !== 'BNAGROCHEM'));
        config(['universe_nse_price_identities' => $oldDocument]);
        $this->source('NSE', [['TckrSymb' => 'AAA', 'ISIN' => 'INE999A01010']]);
        $day = Carbon::parse('2026-10-26');
        $runId = app(SyncLogService::class)->beginRun('universe-price-sync');
        $service = app(UniverseDailyBhavcopySyncService::class);
        $service->run(collect([$stock]), $day, $day, $runId, 'universe-price-sync', $this->initialStats());

        $legacy = json_decode((string) Setting::getValue(UniverseDailyBhavcopySyncService::KEY_RECEIPTS), true);
        unset($legacy['scope_fingerprint']);
        $legacy['entries']['NSE:2026-10-26']['retry_after'] = Carbon::parse('2026-11-30 20:00:00', 'Asia/Kolkata')->toIso8601String();
        Setting::setValue(UniverseDailyBhavcopySyncService::KEY_RECEIPTS, json_encode($legacy, JSON_THROW_ON_ERROR));
        config(['universe_nse_price_identities' => $currentDocument]);

        $result = $service->run(collect([$stock]), $day, $day, $runId, 'universe-price-sync', $this->initialStats())['stats'];
        $saved = json_decode((string) Setting::getValue(UniverseDailyBhavcopySyncService::KEY_RECEIPTS), true);

        $this->assertSame(1, $result['bulk']['backoff_skips']);
        $this->assertSame(0, $result['bulk']['files_downloaded']);
        $this->assertSame('2026-11-30T20:00:00+05:30', $result['bulk']['files'][0]['retry_after']);
        $this->assertSame(1, $saved['entries']['NSE:2026-10-26']['retry_attempt']);
    }

    public function test_membership_change_discards_pending_retry_classification_and_rechecks_source(): void
    {
        $stock = $this->stock();
        $source = Mockery::mock(NseBhavcopyService::class);
        $source->shouldReceive('fetchDailyRows')->twice()->andReturnUsing(fn (Carbon $day) => $this->parsedSource('NSE', $day, [
            ['TckrSymb' => 'AAA', 'ISIN' => 'INE999A01010'],
        ]));
        $this->app->instance(NseBhavcopyService::class, $source);
        $day = Carbon::parse('2026-10-26');
        $runId = app(SyncLogService::class)->beginRun('universe-price-sync');
        $service = app(UniverseDailyBhavcopySyncService::class);
        $service->run(collect([$stock]), $day, $day, $runId, 'universe-price-sync', $this->initialStats());
        $stock->update(['isin' => 'INE003A01010']);

        $result = $service->run(collect([$stock->fresh()]), $day, $day, $runId, 'universe-price-sync', $this->initialStats())['stats'];

        $this->assertSame(0, $result['bulk']['backoff_skips']);
        $this->assertSame(1, $result['bulk']['files_downloaded']);
        $this->assertSame(1, $result['bulk']['identity_conflicts']);
    }

    public function test_coverage_change_revalidates_a_pending_conflict_even_after_catalogue_revision(): void
    {
        $stock = $this->stock();
        $source = Mockery::mock(NseBhavcopyService::class);
        $source->shouldReceive('fetchDailyRows')->twice()->andReturnUsing(fn (Carbon $day) => $this->parsedSource('NSE', $day, [
            ['TckrSymb' => 'AAA', 'ISIN' => 'INE999A01010'],
        ]));
        $this->app->instance(NseBhavcopyService::class, $source);
        $day = Carbon::parse('2026-10-26');
        $runId = app(SyncLogService::class)->beginRun('universe-price-sync');
        $service = app(UniverseDailyBhavcopySyncService::class);
        $service->run(collect([$stock]), $day, $day, $runId, 'universe-price-sync', $this->initialStats());
        app(PriceFetchService::class)->storeHistoricalRows($stock, [[
            'price_date' => '2026-10-26', 'open_price' => 100, 'high_price' => 101,
            'low_price' => 99, 'close_price' => 100, 'volume' => 100,
        ]], 'manual');
        config(['universe_nse_price_identities.version' => 2]);

        $result = $service->run(collect([$stock]), $day, $day, $runId, 'universe-price-sync', $this->initialStats())['stats'];

        $this->assertSame(0, $result['bulk']['backoff_skips']);
        $this->assertSame(1, $result['bulk']['files_downloaded']);
        $this->assertSame(1, $result['bulk']['identity_conflicts']);
        $this->assertSame('conflicting_identity_or_rows', $result['bulk']['files'][0]['identity_conflict_samples'][0]['reason']);
    }

    public function test_bse_conflict_backoff_keeps_failure_and_warning_evidence(): void
    {
        $stock = $this->stock('BBB', ['exchange' => 'BSE', 'isin' => null, 'bse_scrip_code' => '500001']);
        $duplicate = $this->stock('BBB2', ['exchange' => 'BSE', 'isin' => null, 'bse_scrip_code' => '500001']);
        $source = Mockery::mock(BseBhavcopyService::class);
        $source->shouldReceive('fetchDailyRows')->once()->andReturnUsing(fn (Carbon $day) => $this->parsedSource('BSE', $day, [
            ['FinInstrmId' => '500001', 'TckrSymb' => 'BBB', 'ISIN' => 'INE999A01010', 'SctySrs' => 'A'],
        ]));
        $this->app->instance(BseBhavcopyService::class, $source);
        $day = Carbon::parse('2026-10-26');
        $runId = app(SyncLogService::class)->beginRun('universe-price-sync');
        $service = app(UniverseDailyBhavcopySyncService::class);
        $run = fn () => $service->run(collect([$stock, $duplicate]), $day, $day, $runId, 'universe-price-sync', $this->initialStats())['stats'];

        $first = $run();
        $second = $run();
        $this->assertSame(2, $first['failed']);
        $this->assertSame('identity_conflict', $first['bulk']['files'][0]['status']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $first['bulk']['files'][0]['retry_after']);
        $this->assertDatabaseHas('portfolio_universe_price_session_outcomes', [
            'stock_id' => $stock->id,
            'session_date' => '2026-10-26',
            'retry_after' => $first['bulk']['files'][0]['retry_after'],
        ]);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first['bulk']['files'][0]['source_revision_sha256']);
        $this->assertSame(1, $second['bulk']['backoff_skips']);
        $this->assertDatabaseMissing('portfolio_stock_prices', ['stock_id' => $stock->id]);
        $this->assertDatabaseHas('portfolio_sync_logs', ['run_id' => $runId, 'level' => 'warning', 'message' => 'Universe daily bhavcopy retry deferred']);
    }

    public function test_legacy_cached_retry_deferred_receipt_persists_utc_outcome_timestamp(): void
    {
        $stock = $this->stock();
        $this->source('NSE', [['TckrSymb' => 'AAA', 'ISIN' => 'INE999A01010']]);
        $day = Carbon::parse('2026-10-26');
        $runId = app(SyncLogService::class)->beginRun('universe-price-sync');
        $sync = app(UniverseDailyBhavcopySyncService::class);
        $first = $sync->run(collect([$stock]), $day, $day, $runId, 'universe-price-sync', $this->initialStats())['stats'];
        $receipts = json_decode((string) Setting::getValue(UniverseDailyBhavcopySyncService::KEY_RECEIPTS), true);
        $receipts['entries']['NSE:2026-10-26']['failure_stage'] = 'exchange_fetch';
        $receipts['entries']['NSE:2026-10-26']['retry_after'] = '2026-10-26T20:35:00+05:30';
        Setting::setValue(UniverseDailyBhavcopySyncService::KEY_RECEIPTS, json_encode($receipts, JSON_THROW_ON_ERROR));

        $replay = $sync->run(collect([$stock]), $day, $day, $runId, 'universe-price-sync', $this->initialStats())['stats'];
        $this->assertSame('retry_deferred', $replay['bulk']['files'][0]['status']);
        $this->assertSame('2026-10-26T20:35:00+05:30', $replay['bulk']['files'][0]['retry_after']);

        app(UniversePriceOutcomeService::class)->recordRun($replay, collect([$stock]), 'legacy-cache-replay');
        $this->assertDatabaseHas('portfolio_universe_price_session_outcomes', [
            'stock_id' => $stock->id, 'session_date' => '2026-10-26',
            'outcome' => 'provider_error', 'retry_after' => '2026-10-26 15:05:00',
        ]);
        $this->assertSame(1, $first['bulk']['identity_conflicts']);
    }

    public function test_malformed_cached_retry_timestamp_becomes_terminal_receipt_failure(): void
    {
        $stock = $this->stock();
        $this->source('NSE', [['TckrSymb' => 'AAA', 'ISIN' => 'INE999A01010']]);
        $day = Carbon::parse('2026-10-26');
        $runId = app(SyncLogService::class)->beginRun('universe-price-sync');
        $sync = app(UniverseDailyBhavcopySyncService::class);
        $sync->run(collect([$stock]), $day, $day, $runId, 'universe-price-sync', $this->initialStats());
        $receipts = json_decode((string) Setting::getValue(UniverseDailyBhavcopySyncService::KEY_RECEIPTS), true);
        $receipts['entries']['NSE:2026-10-26']['retry_after'] = 'not-a-time';
        Setting::setValue(UniverseDailyBhavcopySyncService::KEY_RECEIPTS, json_encode($receipts, JSON_THROW_ON_ERROR));

        $replay = $sync->run(collect([$stock]), $day, $day, $runId, 'universe-price-sync', $this->initialStats())['stats'];
        $this->assertSame('failed', $replay['bulk']['files'][0]['status']);
        $this->assertSame('receipt_validation', $replay['bulk']['files'][0]['failure_stage']);
        $this->assertSame(1, $replay['bulk']['file_errors']);

        app(UniversePriceOutcomeService::class)->recordRun($replay, collect([$stock]), 'malformed-cache-replay');
        $this->assertDatabaseHas('portfolio_universe_price_session_outcomes', [
            'stock_id' => $stock->id, 'session_date' => '2026-10-26',
            'outcome' => 'systemic_error', 'failure_stage' => 'receipt_validation',
            'reason' => 'invalid_retry_after', 'retry_after' => null,
        ]);
    }

    public function test_conflicts_without_valid_revision_evidence_continue_backoff_progression(): void
    {
        $stock = $this->stock();
        $source = Mockery::mock(NseBhavcopyService::class);
        $source->shouldReceive('fetchDailyRows')->times(4)->andReturnUsing(function (Carbon $day) {
            $parsed = $this->parsedSource('NSE', $day, [['TckrSymb' => 'AAA', 'ISIN' => 'INE999A01010']]);
            unset($parsed['source_revision_sha256']);
            return $parsed;
        });
        $this->app->instance(NseBhavcopyService::class, $source);
        $day = Carbon::parse('2026-10-26');
        $runId = app(SyncLogService::class)->beginRun('universe-price-sync');
        $sync = app(UniverseDailyBhavcopySyncService::class);
        $run = fn () => $sync->run(collect([$stock]), $day, $day, $runId, 'universe-price-sync', $this->initialStats())['stats'];

        foreach ([1, 2, 3, 4] as $attempt) {
            $result = $run();
            $this->assertSame(1, $result['bulk']['identity_conflicts']);
            $saved = json_decode((string) Setting::getValue(UniverseDailyBhavcopySyncService::KEY_RECEIPTS), true);
            $entry = $saved['entries']['NSE:2026-10-26'];
            $this->assertSame($attempt, $entry['retry_attempt']);
            if ($attempt < 4) {
                Carbon::setTestNow(Carbon::parse($entry['retry_after']));
            }
        }

        $this->assertDatabaseMissing('portfolio_stock_prices', ['stock_id' => $stock->id]);
    }

    public function test_old_session_failures_schedule_reprobe_from_today_after_short_retries(): void
    {
        // This instant is still the prior UTC date after midnight in India.
        Carbon::setTestNow(Carbon::parse('2026-10-26 20:00:00', 'UTC'));
        $service = app(UniverseDailyBhavcopySyncService::class);
        $method = new \ReflectionMethod($service, 'nextRetrySchedule');
        $method->setAccessible(true);

        [$attempt, $retryAfter] = $method->invoke($service, [
            'retry_attempt' => 3,
            'retry_after' => '2026-10-26T19:00:00+05:30',
        ], Carbon::parse('2026-10-15'));

        $this->assertSame(4, $attempt);
        $this->assertTrue($retryAfter->isFuture());
        $this->assertSame('2026-11-03 20:00:00', $retryAfter->timezone('Asia/Kolkata')->toDateTimeString());
    }

    public function test_old_session_reprobe_uses_exchange_date_on_both_sides_of_ist_midnight(): void
    {
        $service = app(UniverseDailyBhavcopySyncService::class);
        $method = new \ReflectionMethod($service, 'nextRetrySchedule');
        $method->setAccessible(true);
        $receipt = ['retry_attempt' => 3, 'retry_after' => '2026-10-27T19:00:00+05:30'];

        Carbon::setTestNow(Carbon::parse('2026-10-26 18:29:59', 'UTC'));
        [, $beforeMidnight] = $method->invoke($service, $receipt, Carbon::parse('2026-10-15'));
        $this->assertSame('2026-11-02 20:00:00', $beforeMidnight->timezone('Asia/Kolkata')->toDateTimeString());

        Carbon::setTestNow(Carbon::parse('2026-10-26 18:30:00', 'UTC'));
        [, $afterMidnight] = $method->invoke($service, $receipt, Carbon::parse('2026-10-15'));
        $this->assertSame('2026-11-03 20:00:00', $afterMidnight->timezone('Asia/Kolkata')->toDateTimeString());
    }

    private function initialStats(): array
    {
        return ['processed' => 0, 'succeeded' => 0, 'failed' => 0, 'skipped' => 0,
            'stored_rows' => 0, 'cache_hits' => 0, 'rate_limit_hits' => 0, 'errors' => []];
    }
}
