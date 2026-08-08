<?php

namespace Tests\Unit;

use App\Models\Setting;
use App\Models\Stock;
use App\Services\BenchmarkPriceSyncService;
use App\Services\PortfolioLoggerService;
use App\Services\PriceFetchService;
use App\Services\SyncLogService;
use App\Services\UniversePriceSyncService;
use App\Services\UniverseStockResolverService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Concerns\CreatesPortfolioProfiles;
use Tests\TestCase;

class UniversePriceSyncServiceTest extends TestCase
{
    use CreatesPortfolioProfiles;
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
        $priceFetch->shouldReceive('syncStock')
            ->once()
            ->withArgs(function ($s, Carbon $from, Carbon $to) use ($stock) {
                return $s->id === $stock->id
                    && $from->lte(now()->subDays(10))
                    && $from->gte(now()->subDays(11))
                    && $to->isToday();
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

        $service->sync(mode: 'daily', processAll: false, batchSize: 1);
    }

    public function test_sync_batch_prioritizes_holdings_before_other_stocks(): void
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

        $result = $service->sync(mode: 'daily', processAll: false, batchSize: 1);

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
