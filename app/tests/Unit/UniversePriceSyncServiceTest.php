<?php

namespace Tests\Unit;

use App\Models\Setting;
use App\Models\Stock;
use App\Models\CalendarEvent;
use App\Services\BenchmarkPriceSyncService;
use App\Services\PortfolioLoggerService;
use App\Services\PriceFetchService;
use App\Services\SyncLogService;
use App\Services\UniversePriceSyncService;
use App\Services\UniversePrice\UniverseDailyBhavcopySyncService;
use App\Services\UniverseStockResolverService;
use App\Support\TradingCalendar;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\Concerns\CreatesPortfolioProfiles;
use Tests\TestCase;

class UniversePriceSyncServiceTest extends TestCase
{
    use CreatesPortfolioProfiles;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        TradingCalendar::clearHolidayCache();
        Mockery::close();
        parent::tearDown();
    }

    private function fakeBulk(callable $assertDates, int $calls = 1, int $stored = 0): void
    {
        $bulk = Mockery::mock(UniverseDailyBhavcopySyncService::class);
        $bulk->shouldReceive('run')->times($calls)
            ->andReturnUsing(function ($stocks, Carbon $from, Carbon $to, $runId, $jobName, array $stats) use ($assertDates, $stored) {
                $assertDates($stocks, $from, $to);
                return ['stats' => array_merge($stats, [
                    'processed' => $stocks->count(), 'succeeded' => $stocks->count(),
                    'stored_rows' => $stored, 'cache_hits' => $stored === 0 ? $stocks->count() : 0,
                    'collection_method' => 'exchange_bhavcopy', 'bulk' => ['file_errors' => 0, 'identity_conflicts' => 0],
                ]), 'last_stock_id' => 0];
            });
        $this->app->instance(UniverseDailyBhavcopySyncService::class, $bulk);
    }

    public function test_backfill_processes_batch_and_advances_cursor(): void
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

        $benchmarkSync = Mockery::mock(BenchmarkPriceSyncService::class);
        $benchmarkSync->shouldReceive('syncIfNeeded')
            ->once()
            ->andReturn(['skipped' => true, 'success' => true]);

        $syncLog = Mockery::mock(SyncLogService::class);
        $syncLog->shouldReceive('beginRun')->once()->andReturn('run-1');
        $syncLog->shouldReceive('log')->atLeast()->once();
        $syncLog->shouldReceive('completeRun')->once();

        $logger = Mockery::mock(PortfolioLoggerService::class);
        $logger->shouldReceive('scheduler')->atLeast()->once();

        $service = new UniversePriceSyncService(
            app(UniverseStockResolverService::class),
            $priceFetch,
            $benchmarkSync,
            $syncLog,
            $logger,
        );

        $result = $service->sync(mode: 'backfill', processAll: false, batchSize: 2);

        $this->assertSame(2, $result['processed']);
        $this->assertSame(2, $result['succeeded']);
        $this->assertSame($second->id, (int) Setting::getValue(UniversePriceSyncService::KEY_CURSOR_STOCK_ID));
        $this->assertFalse($result['cycle_completed']);
    }

    public function test_backfill_all_resets_cursor_and_marks_cycle_complete(): void
    {
        Carbon::setTestNow('2026-10-23 16:00:00');
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

        $benchmarkSync = Mockery::mock(BenchmarkPriceSyncService::class);
        $benchmarkSync->shouldReceive('syncIfNeeded')
            ->once()
            ->andReturn(['skipped' => true, 'success' => true]);

        $syncLog = Mockery::mock(SyncLogService::class);
        $syncLog->shouldReceive('beginRun')->once()->andReturn('run-2');
        $syncLog->shouldReceive('log')->atLeast()->once();
        $syncLog->shouldReceive('completeRun')->once();

        $logger = Mockery::mock(PortfolioLoggerService::class);
        $logger->shouldReceive('scheduler')->atLeast()->once();

        $service = new UniversePriceSyncService(
            app(UniverseStockResolverService::class),
            $priceFetch,
            $benchmarkSync,
            $syncLog,
            $logger,
        );

        $result = $service->sync(mode: 'backfill', processAll: true);

        $this->assertTrue($result['cycle_completed']);
        $this->assertSame('0', Setting::getValue(UniversePriceSyncService::KEY_CURSOR_STOCK_ID));
        $this->assertNotNull(Setting::getValue(UniversePriceSyncService::KEY_LAST_CYCLE_COMPLETED_AT));
    }

    public function test_daily_sync_uses_fixed_daily_lookback_config(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-26 20:00:00', 'UTC'));
        config([
            'portfolio.universe_price_sync.enabled' => true,
            'portfolio.universe_price_sync.batch_size' => 1,
            'portfolio.universe_price_sync.delay_ms_between_stocks' => 0,
            'portfolio.universe_price_sync.daily_lookback_days' => 10,
        ]);

        $stock = Stock::query()->create([
            'symbol' => 'BBB',
            'exchange' => 'NSE',
            'name' => 'BBB',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        $priceFetch = Mockery::mock(PriceFetchService::class);
        $priceFetch->shouldNotReceive('syncStock');
        $this->fakeBulk(function ($stocks, Carbon $from, Carbon $to) use ($stock): void {
            $this->assertSame([$stock->id], $stocks->pluck('id')->all());
            $this->assertSame('2026-10-17', $from->toDateString());
            $this->assertSame('2026-10-27', $to->toDateString());
        }, stored: 1);

        $benchmarkSync = Mockery::mock(BenchmarkPriceSyncService::class);
        $benchmarkSync->shouldReceive('syncIfNeeded')->once()->andReturn(['skipped' => true, 'success' => true]);

        $syncLog = Mockery::mock(SyncLogService::class);
        $syncLog->shouldReceive('beginRun')->once()->andReturn('run-3');
        $syncLog->shouldReceive('log')->atLeast()->once();
        $syncLog->shouldReceive('completeRun')->once();

        $logger = Mockery::mock(PortfolioLoggerService::class);
        $logger->shouldReceive('scheduler')->atLeast()->once();

        $service = new UniversePriceSyncService(
            app(UniverseStockResolverService::class),
            $priceFetch,
            $benchmarkSync,
            $syncLog,
            $logger,
        );

        $result = $service->sync(mode: 'daily', processAll: false, batchSize: 1);

        $this->assertSame(1, $result['processed']);
        $this->assertSame(1, $result['succeeded']);
    }

    public function test_sync_keeps_the_current_session_but_skips_closed_calendar_tails(): void
    {
        config([
            'portfolio.universe_price_sync.enabled' => true,
            'portfolio.universe_price_sync.delay_ms_between_stocks' => 0,
        ]);
        // Simulate a long-lived worker's negative lookup before a calendar edit.
        $this->assertTrue(TradingCalendar::isEquitySessionDate(Carbon::parse('2026-10-26')));
        CalendarEvent::query()->create([
            'profile_id' => null, 'category' => CalendarEvent::CATEGORY_TRADE_HOLIDAY,
            'title' => 'Test trade holiday', 'color' => CalendarEvent::TRADE_HOLIDAY_DEFAULT_COLOR,
            'anchor_date' => '2026-10-26', 'recurrence_type' => CalendarEvent::RECURRENCE_NONE,
            'reminder_enabled' => false, 'is_active' => true,
        ]);
        Stock::query()->create([
            'symbol' => 'SESSION', 'exchange' => 'NSE', 'name' => 'SESSION',
            'is_active' => true, 'is_benchmark' => false,
        ]);
        $requestedDates = [];
        $priceFetch = Mockery::mock(PriceFetchService::class);
        $priceFetch->shouldNotReceive('syncStock');
        $this->fakeBulk(function ($stocks, Carbon $from, Carbon $to) use (&$requestedDates): void {
            $requestedDates[] = $to->toDateString();
        }, calls: 4);
        $benchmark = Mockery::mock(BenchmarkPriceSyncService::class);
        $benchmark->shouldReceive('syncIfNeeded')->times(4)->andReturn(['skipped' => true, 'success' => true]);
        $syncLog = Mockery::mock(SyncLogService::class);
        $syncLog->shouldReceive('beginRun')->times(4)->andReturn('session-test');
        $syncLog->shouldReceive('log')->andReturnNull();
        $syncLog->shouldReceive('completeRun')->times(4);
        $logger = Mockery::mock(PortfolioLoggerService::class);
        $logger->shouldReceive('scheduler')->andReturnNull();
        $service = new UniversePriceSyncService(app(UniverseStockResolverService::class), $priceFetch, $benchmark, $syncLog, $logger);

        foreach (['2026-10-23', '2026-10-24', '2026-10-25', '2026-10-26'] as $day) {
            Carbon::setTestNow($day.' 16:00:00');
            $result = $service->sync(mode: 'daily', batchSize: 1);
            $this->assertSame(1, $result['succeeded']);
            $this->assertSame(1, $result['cache_hits']);
            $this->assertSame(0, $result['failed']);
            $this->assertSame(0, $result['stored_rows']);
        }

        $this->assertSame(['2026-10-23', '2026-10-23', '2026-10-23', '2026-10-23'], $requestedDates);
    }

    public function test_backfill_batch_prioritizes_holdings_before_other_stocks(): void
    {
        config([
            'portfolio.universe_price_sync.enabled' => true,
            'portfolio.universe_price_sync.batch_size' => 1,
            'portfolio.universe_price_sync.delay_ms_between_stocks' => 0,
            'portfolio.universe_price_sync.daily_lookback_days' => 5,
        ]);

        $user = \App\Models\User::query()->create([
            'name' => 'Sync Priority',
            'email' => 'sync-prio-'.uniqid().'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);

        $other = Stock::query()->create([
            'symbol' => 'OTHERZ',
            'exchange' => 'NSE',
            'name' => 'Other',
            'is_active' => true,
            'is_benchmark' => false,
        ]);
        $holding = Stock::query()->create([
            'symbol' => 'HOLDZ',
            'exchange' => 'NSE',
            'name' => 'Holding',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        \App\Models\Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $holding->id,
            'quantity' => 5,
            'avg_buy_price' => 10,
            'invested_amount' => 50,
            'total_fees' => 0,
            'realized_profit' => 0,
            'updated_at' => now(),
        ]);

        $syncedIds = [];
        $priceFetch = Mockery::mock(PriceFetchService::class);
        $priceFetch->shouldReceive('syncStock')
            ->once()
            ->withArgs(function ($s) use (&$syncedIds) {
                $syncedIds[] = $s->id;

                return true;
            })
            ->andReturn([
                'success' => true,
                'stored_rows' => 1,
                'cache_hit' => false,
                'errors' => [],
            ]);

        $benchmarkSync = Mockery::mock(BenchmarkPriceSyncService::class);
        $benchmarkSync->shouldReceive('syncIfNeeded')->once()->andReturn(['skipped' => true, 'success' => true]);

        $syncLog = Mockery::mock(SyncLogService::class);
        $syncLog->shouldReceive('beginRun')->once()->andReturn('run-prio');
        $syncLog->shouldReceive('log')->atLeast()->once();
        $syncLog->shouldReceive('completeRun')->once();

        $logger = Mockery::mock(PortfolioLoggerService::class);
        $logger->shouldReceive('scheduler')->atLeast()->once();

        $service = new UniversePriceSyncService(
            app(UniverseStockResolverService::class),
            $priceFetch,
            $benchmarkSync,
            $syncLog,
            $logger,
        );

        $result = $service->sync(mode: 'backfill', processAll: false, batchSize: 1);

        $this->assertSame([$holding->id], $syncedIds);
        $this->assertSame($holding->id, (int) Setting::getValue(UniversePriceSyncService::KEY_CURSOR_STOCK_ID));
        $this->assertFalse($result['cycle_completed']);
        $this->assertNotSame($other->id, $syncedIds[0] ?? null);
    }

    public function test_high_failure_rate_without_rate_limit_hits_is_not_rate_limited(): void
    {
        $service = app(UniversePriceSyncService::class);

        $this->assertFalse($service->isLikelyRateLimitedPublic([
            'processed' => 125,
            'failed' => 125,
            'rate_limit_hits' => 0,
            'failure_rate_percent' => 100,
        ], [
            [
                'likely_rate_limit' => false,
                'message' => 'Universe stock sync returned no rows',
                'context' => ['errors' => ['nse: returned 0 rows in requested range']],
            ],
            [
                'likely_rate_limit' => false,
                'message' => 'Universe stock sync returned no rows',
                'context' => ['errors' => ['yahoo: returned 0 rows in requested range']],
            ],
            [
                'likely_rate_limit' => false,
                'message' => 'Universe stock sync returned no rows',
                'context' => ['errors' => ['alpha_vantage: returned 0 rows in requested range']],
            ],
        ]));
    }

    public function test_rate_limit_hits_still_flag_likely_rate_limited(): void
    {
        $service = app(UniversePriceSyncService::class);

        $this->assertTrue($service->isLikelyRateLimitedPublic([
            'processed' => 125,
            'failed' => 10,
            'rate_limit_hits' => 3,
        ], []));
    }

    public function test_weekend_maintenance_skipped_when_prior_session_succeeded(): void
    {
        config([
            'portfolio.universe_price_sync.skip_weekends' => true,
            'portfolio.universe_price_sync.weekend_retry_on_prior_session_failures' => true,
        ]);
        Setting::setValue('cron_timezone', 'Asia/Kolkata');

        // Friday evening success only.
        \App\Models\SyncRun::query()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'job_name' => SyncLogService::JOB_UNIVERSE_PRICE_SYNC,
            'status' => 'success',
            'started_at' => Carbon::parse('2026-07-31 19:05:00', 'Asia/Kolkata')->utc(),
            'finished_at' => Carbon::parse('2026-07-31 19:06:00', 'Asia/Kolkata')->utc(),
            'stocks_processed' => 125,
            'failures' => 0,
        ]);

        $service = app(UniversePriceSyncService::class);
        $saturday = Carbon::parse('2026-08-01 19:20:00', 'Asia/Kolkata');

        $this->assertTrue($service->isWithinMaintenanceHours($saturday));
        $this->assertFalse($service->priorEquitySessionHadFailures($saturday));
        $this->assertFalse($service->allowsMaintenanceOnCalendarDay($saturday));
        $this->assertFalse($service->isMaintenanceWindowDue($saturday));
    }

    private function insertCoverageOnlyRun(array $overrides = [], string $status = 'partial'): string
    {
        $id = (string) \Illuminate\Support\Str::uuid();
        $started = Carbon::parse('2026-11-23 19:00:00', 'Asia/Kolkata')->utc();
        $finished = Carbon::parse('2026-11-23 19:01:00', 'Asia/Kolkata')->utc();
        $run = \App\Models\SyncRun::query()->create([
            'id' => $id, 'job_name' => SyncLogService::JOB_UNIVERSE_PRICE_SYNC, 'status' => $status,
            'operational_outcome' => ((int) (($overrides['bulk']['file_errors'] ?? 0)) === 0
                && (int) (($overrides['bulk']['identity_conflicts'] ?? 0)) === 0
                && count($overrides['bulk']['files'] ?? [[ 'status' => 'applied' ]]) === 1)
                    ? 'SUCCESS' : null,
            'started_at' => $started, 'finished_at' => $finished, 'stocks_processed' => 2, 'failures' => 2,
        ]);
        $file = [
            'exchange' => 'NSE', 'date' => '2026-11-23', 'status' => 'applied',
            'source_revision_sha256' => str_repeat('a', 64), 'coverage_hash' => str_repeat('b', 64),
            'checked_at' => '2026-11-23T19:00:30+05:30',
        ];
        $bulk = [
            'from_date' => '2026-11-23', 'to_date' => '2026-11-23', 'expected_exchanges' => ['NSE'],
            'expected_files' => [['exchange' => 'NSE', 'date' => '2026-11-23']], 'files' => [$file],
            'file_errors' => 0, 'identity_conflicts' => 0, 'unavailable_stocks' => 2,
            'outcomes' => [
                'covered' => 0, 'no_data' => 2, 'skipped' => 0, 'ignored' => 0,
                'provider_error' => 0, 'systemic_error' => 0, 'not_expected' => 0,
            ],
        ];
        $stats = array_merge([
            'processed' => 2, 'failed' => 2, 'completed_at' => '2026-11-23T19:01:00+05:30', 'bulk' => $bulk,
        ], $overrides);
        $stats['bulk'] = array_merge($bulk, $overrides['bulk'] ?? []);
        $stockIds = [];
        $fixtureSuffix = \Illuminate\Support\Str::random(6);
        for ($index = 0; $index < 2; $index++) {
            $stockIds[] = Stock::query()->create([
                'symbol' => 'COV'.$fixtureSuffix.$index, 'name' => 'Coverage fixture '.$index,
                'exchange' => 'NSE', 'is_active' => true,
            ])->id;
        }
        foreach ($stockIds as $stockId) {
            DB::table('portfolio_universe_price_session_outcomes')->insert([
                'stock_id' => $stockId, 'session_date' => '2026-11-23', 'exchange' => 'NSE',
                'outcome' => 'no_data', 'price_present' => false, 'run_id' => $id,
                'source_revision_sha256' => str_repeat('a', 64),
                'observed_at' => $finished, 'created_at' => $finished, 'updated_at' => $finished,
            ]);
        }
        \App\Models\SyncLog::query()->create([
            'run_id' => $id, 'job_name' => SyncLogService::JOB_UNIVERSE_PRICE_SYNC,
            'level' => 'info', 'message' => 'Universe price sync completed', 'context' => $stats,
            'logged_at' => $finished,
        ]);

        return $run->id;
    }

    public function test_holiday_retry_skips_only_when_partial_run_has_complete_healthy_file_evidence(): void
    {
        config([
            'portfolio.universe_price_sync.skip_weekends' => true,
            'portfolio.universe_price_sync.weekend_retry_on_prior_session_failures' => true,
        ]);
        Setting::setValue('cron_timezone', 'Asia/Kolkata');
        CalendarEvent::query()->create([
            'profile_id' => null, 'category' => CalendarEvent::CATEGORY_TRADE_HOLIDAY,
            'title' => 'Recovery holiday', 'color' => CalendarEvent::TRADE_HOLIDAY_DEFAULT_COLOR,
            'anchor_date' => '2026-11-24', 'recurrence_type' => CalendarEvent::RECURRENCE_NONE,
            'reminder_enabled' => false, 'is_active' => true,
        ]);
        TradingCalendar::clearHolidayCache();
        $this->insertCoverageOnlyRun();
        $holidayEvening = Carbon::parse('2026-11-24 19:00:00', 'Asia/Kolkata');

        $this->assertTrue(TradingCalendar::isEquitySessionDate(Carbon::parse('2026-11-23')));
        $this->assertFalse(TradingCalendar::isEquitySessionDate($holidayEvening));
        $this->assertFalse(app(UniversePriceSyncService::class)->priorEquitySessionHadFailures($holidayEvening));
        $this->assertFalse(app(UniversePriceSyncService::class)->allowsMaintenanceOnCalendarDay($holidayEvening));
    }

    public function test_terminal_evidence_counts_stock_outcomes_once_per_session_across_both_exchanges(): void
    {
        config([
            'portfolio.universe_price_sync.skip_weekends' => true,
            'portfolio.universe_price_sync.weekend_retry_on_prior_session_failures' => true,
        ]);
        Setting::setValue('cron_timezone', 'Asia/Kolkata');
        $id = $this->insertCoverageOnlyRun(['bulk' => [
            'expected_exchanges' => ['NSE', 'BSE'],
            'expected_files' => [
                ['exchange' => 'NSE', 'date' => '2026-11-23'],
                ['exchange' => 'BSE', 'date' => '2026-11-23'],
            ],
            'files' => [
                ['exchange' => 'NSE', 'date' => '2026-11-23', 'status' => 'applied',
                    'source_revision_sha256' => str_repeat('a', 64), 'coverage_hash' => str_repeat('b', 64),
                    'checked_at' => '2026-11-23T19:00:30+05:30'],
                ['exchange' => 'BSE', 'date' => '2026-11-23', 'status' => 'applied',
                    'source_revision_sha256' => str_repeat('c', 64), 'coverage_hash' => str_repeat('d', 64),
                    'checked_at' => '2026-11-23T19:00:30+05:30'],
            ],
        ]]);
        \App\Models\SyncRun::query()->whereKey($id)->update(['operational_outcome' => 'SUCCESS']);

        $holidayEvening = Carbon::parse('2026-11-24 19:00:00', 'Asia/Kolkata');
        $this->assertFalse(app(UniversePriceSyncService::class)->priorEquitySessionHadFailures($holidayEvening));
    }

    public function test_all_no_data_run_stays_failed_for_coverage_but_does_not_trigger_holiday_source_retry(): void
    {
        config(['portfolio.universe_price_sync.weekend_retry_on_prior_session_failures' => true]);
        Setting::setValue('cron_timezone', 'Asia/Kolkata');
        CalendarEvent::query()->create([
            'profile_id' => null, 'category' => CalendarEvent::CATEGORY_TRADE_HOLIDAY,
            'title' => 'Recovery holiday', 'color' => CalendarEvent::TRADE_HOLIDAY_DEFAULT_COLOR,
            'anchor_date' => '2026-11-24', 'recurrence_type' => CalendarEvent::RECURRENCE_NONE,
            'reminder_enabled' => false, 'is_active' => true,
        ]);
        TradingCalendar::clearHolidayCache();
        $this->insertCoverageOnlyRun([], 'failed');
        $holidayEvening = Carbon::parse('2026-11-24 19:00:00', 'Asia/Kolkata');

        $this->assertFalse(app(UniversePriceSyncService::class)->priorEquitySessionHadFailures($holidayEvening));
    }

    public function test_incomplete_evidence_or_real_file_failures_keep_holiday_retry_enabled(): void
    {
        config(['portfolio.universe_price_sync.weekend_retry_on_prior_session_failures' => true]);
        Setting::setValue('cron_timezone', 'Asia/Kolkata');
        $holidayEvening = Carbon::parse('2026-11-24 19:00:00', 'Asia/Kolkata');
        CalendarEvent::query()->create([
            'profile_id' => null, 'category' => CalendarEvent::CATEGORY_TRADE_HOLIDAY,
            'title' => 'Recovery holiday', 'color' => CalendarEvent::TRADE_HOLIDAY_DEFAULT_COLOR,
            'anchor_date' => '2026-11-24', 'recurrence_type' => CalendarEvent::RECURRENCE_NONE,
            'reminder_enabled' => false, 'is_active' => true,
        ]);
        TradingCalendar::clearHolidayCache();

        $this->insertCoverageOnlyRun(['bulk' => ['files' => []]]);
        $this->assertTrue(app(UniversePriceSyncService::class)->priorEquitySessionHadFailures($holidayEvening));

        \App\Models\SyncRun::query()->delete();
        \App\Models\SyncLog::query()->delete();
        $this->insertCoverageOnlyRun(['bulk' => ['file_errors' => 1]]);
        $this->assertTrue(app(UniversePriceSyncService::class)->priorEquitySessionHadFailures($holidayEvening));

        \App\Models\SyncRun::query()->delete();
        \App\Models\SyncLog::query()->delete();
        $this->insertCoverageOnlyRun(['bulk' => ['identity_conflicts' => 1]]);
        $this->assertTrue(app(UniversePriceSyncService::class)->priorEquitySessionHadFailures($holidayEvening));

        \App\Models\SyncRun::query()->delete();
        \App\Models\SyncLog::query()->delete();
        $runId = $this->insertCoverageOnlyRun();
        DB::table('portfolio_universe_price_session_outcomes')->where('run_id', $runId)
            ->update(['source_revision_sha256' => str_repeat('c', 64)]);
        $this->assertTrue(app(UniversePriceSyncService::class)->priorEquitySessionHadFailures($holidayEvening));
    }

    public function test_historical_systemic_stock_outcome_is_not_operational_success(): void
    {
        config(['portfolio.universe_price_sync.weekend_retry_on_prior_session_failures' => true]);
        Setting::setValue('cron_timezone', 'Asia/Kolkata');
        $this->insertCoverageOnlyRun(['bulk' => ['outcomes' => [
            'covered' => 0, 'no_data' => 1, 'skipped' => 0, 'ignored' => 0,
            'provider_error' => 0, 'systemic_error' => 1, 'not_expected' => 0,
        ]]]);

        $this->assertTrue(app(UniversePriceSyncService::class)->priorEquitySessionHadFailures(
            Carbon::parse('2026-11-24 19:00:00', 'Asia/Kolkata'),
        ));
    }

    public function test_november_24_scale_run_requires_all_twelve_matching_receipts_and_31068_outcomes(): void
    {
        $finished = Carbon::parse('2026-11-24 14:30:00', 'UTC');
        $runId = (string) \Illuminate\Support\Str::uuid();
        $run = \App\Models\SyncRun::query()->create([
            'id' => $runId, 'job_name' => SyncLogService::JOB_UNIVERSE_PRICE_SYNC,
            'status' => 'partial', 'started_at' => $finished->copy()->subMinutes(2),
            'finished_at' => $finished, 'stocks_processed' => 5178, 'failures' => 546,
        ]);
        $stamp = $finished->toIso8601String();
        $sessions = ['2026-11-16', '2026-11-17', '2026-11-18', '2026-11-19', '2026-11-20', '2026-11-23'];
        $files = $expectedFiles = $sourceHashes = [];
        foreach ($sessions as $date) {
            foreach (['NSE', 'BSE'] as $exchange) {
                $sourceHash = hash('sha256', $exchange.':'.$date);
                $key = $exchange.':'.$date;
                $sourceHashes[$key] = $sourceHash;
                $files[] = [
                    'exchange' => $exchange, 'date' => $date, 'status' => 'receipt_cached',
                    'source_revision_sha256' => $sourceHash, 'coverage_hash' => hash('sha256', 'coverage:'.$key),
                    'checked_at' => $stamp,
                ];
                $expectedFiles[] = ['exchange' => $exchange, 'date' => $date];
            }
        }

        $stockRows = [];
        for ($index = 0; $index < 5178; $index++) {
            $stockRows[] = [
                'symbol' => 'SCALE'.$index, 'name' => 'Scale fixture '.$index,
                'exchange' => $index < 5000 ? 'NSE' : 'BSE',
                'is_active' => true, 'is_benchmark' => false,
                'created_at' => $stamp, 'updated_at' => $stamp,
            ];
        }
        foreach (array_chunk($stockRows, 500) as $chunk) DB::table('portfolio_stocks')->insert($chunk);
        $stocks = DB::table('portfolio_stocks')->orderBy('id')->get(['id', 'exchange']);
        $outcomeRows = [];
        $counts = ['not_expected' => 0, 'no_data' => 0, 'covered' => 0];
        foreach ($sessions as $sessionIndex => $date) {
            foreach ($stocks as $index => $stock) {
                $outcomeIndex = $sessionIndex * 5178 + $index;
                $outcome = $outcomeIndex < 2 ? 'not_expected' : ($outcomeIndex < 3184 ? 'no_data' : 'covered');
                $counts[$outcome]++;
                $outcomeRows[] = [
                    'stock_id' => $stock->id, 'session_date' => $date, 'exchange' => $stock->exchange,
                    'outcome' => $outcome, 'price_present' => $outcome === 'covered',
                    'source_revision_sha256' => $sourceHashes[$stock->exchange.':'.$date],
                    'run_id' => $runId, 'observed_at' => $stamp, 'created_at' => $stamp, 'updated_at' => $stamp,
                ];
            }
        }
        foreach (array_chunk($outcomeRows, 1000) as $chunk) DB::table('portfolio_universe_price_session_outcomes')->insert($chunk);
        $this->assertSame(['not_expected' => 2, 'no_data' => 3182, 'covered' => 27884], $counts);

        $stats = [
            'run_id' => $runId, 'processed' => 5178, 'succeeded' => 4632, 'failed' => 546,
            'completed_at' => $stamp, 'bulk' => [
                'from_date' => '2026-11-16', 'to_date' => '2026-11-23',
                'expected_exchanges' => ['NSE', 'BSE'], 'expected_files' => $expectedFiles, 'files' => $files,
                'file_errors' => 0, 'identity_conflicts' => 0, 'unavailable_stocks' => 546,
                'outcomes' => [
                    'covered' => 27884, 'no_data' => 3182, 'skipped' => 0, 'ignored' => 0,
                    'provider_error' => 0, 'systemic_error' => 0, 'not_expected' => 2,
                ],
            ],
        ];
        \App\Models\SyncLog::query()->create([
            'run_id' => $runId, 'job_name' => SyncLogService::JOB_UNIVERSE_PRICE_SYNC,
            'level' => 'info', 'message' => 'Universe price sync completed', 'context' => $stats,
            'logged_at' => $finished,
        ]);

        $method = new \ReflectionMethod(UniversePriceSyncService::class, 'hasCompleteOperationalSourceEvidence');
        $this->assertTrue($method->invoke(app(UniversePriceSyncService::class), $run));
    }

    public function test_weekend_maintenance_runs_when_prior_session_had_failures(): void
    {
        config([
            'portfolio.universe_price_sync.skip_weekends' => true,
            'portfolio.universe_price_sync.weekend_retry_on_prior_session_failures' => true,
        ]);
        Setting::setValue('cron_timezone', 'Asia/Kolkata');

        \App\Models\SyncRun::query()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'job_name' => SyncLogService::JOB_UNIVERSE_PRICE_SYNC,
            'status' => 'failed',
            'started_at' => Carbon::parse('2026-07-31 20:00:00', 'Asia/Kolkata')->utc(),
            'finished_at' => Carbon::parse('2026-07-31 20:05:00', 'Asia/Kolkata')->utc(),
            'stocks_processed' => 125,
            'failures' => 125,
            'summary' => 'ok=0 fail=125',
        ]);

        $service = app(UniversePriceSyncService::class);
        $saturday = Carbon::parse('2026-08-01 19:20:00', 'Asia/Kolkata');

        $this->assertTrue($service->priorEquitySessionHadFailures($saturday));
        $this->assertTrue($service->allowsMaintenanceOnCalendarDay($saturday));
        $this->assertTrue($service->isMaintenanceWindowDue($saturday));
    }

    public function test_weekend_maintenance_skipped_when_prior_session_healed_after_partials(): void
    {
        config([
            'portfolio.universe_price_sync.skip_weekends' => true,
            'portfolio.universe_price_sync.weekend_retry_on_prior_session_failures' => true,
        ]);
        Setting::setValue('cron_timezone', 'Asia/Kolkata');

        // Friday: partial batch, then a later success in the same maintenance window.
        \App\Models\SyncRun::query()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'job_name' => SyncLogService::JOB_UNIVERSE_PRICE_SYNC,
            'status' => 'partial',
            'started_at' => Carbon::parse('2026-08-07 22:30:00', 'Asia/Kolkata')->utc(),
            'finished_at' => Carbon::parse('2026-08-07 22:35:00', 'Asia/Kolkata')->utc(),
            'stocks_processed' => 125,
            'failures' => 10,
        ]);
        \App\Models\SyncRun::query()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'job_name' => SyncLogService::JOB_UNIVERSE_PRICE_SYNC,
            'status' => 'success',
            'started_at' => Carbon::parse('2026-08-07 22:40:00', 'Asia/Kolkata')->utc(),
            'finished_at' => Carbon::parse('2026-08-07 22:41:00', 'Asia/Kolkata')->utc(),
            'stocks_processed' => 125,
            'failures' => 0,
        ]);

        $service = app(UniversePriceSyncService::class);
        $saturday = Carbon::parse('2026-08-08 19:20:00', 'Asia/Kolkata');

        $this->assertFalse($service->priorEquitySessionHadFailures($saturday));
        $this->assertFalse($service->allowsMaintenanceOnCalendarDay($saturday));
        $this->assertFalse($service->isMaintenanceWindowDue($saturday));
    }

    public function test_stale_in_progress_lock_fails_orphan_running_sync_runs(): void
    {
        config(['portfolio.universe_price_sync.stale_lock_minutes' => 30]);

        $runId = (string) \Illuminate\Support\Str::uuid();
        \App\Models\SyncRun::query()->create([
            'id' => $runId,
            'job_name' => SyncLogService::JOB_UNIVERSE_PRICE_SYNC,
            'status' => 'running',
            'started_at' => now()->subMinutes(45),
            'finished_at' => null,
        ]);

        Setting::setValue(UniversePriceSyncService::KEY_IN_PROGRESS, '1');
        Setting::setValue(UniversePriceSyncService::KEY_IN_PROGRESS_AT, now()->subMinutes(45)->toIso8601String());

        $service = app(UniversePriceSyncService::class);
        $this->assertFalse($service->isSyncInProgress());

        $run = \App\Models\SyncRun::query()->find($runId);
        $this->assertSame('failed', $run->status);
        $this->assertNotNull($run->finished_at);
        $this->assertStringContainsString('Abandoned', (string) $run->summary);
        $this->assertSame('0', Setting::getValue(UniversePriceSyncService::KEY_IN_PROGRESS, '0'));
    }
}
