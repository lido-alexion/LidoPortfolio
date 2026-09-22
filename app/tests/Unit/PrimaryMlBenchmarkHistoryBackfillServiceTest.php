<?php

namespace Tests\Unit;

use App\Models\Setting;
use App\Services\IndexCatalogService;
use App\Services\ML\MlBenchmarkHistoryPolicy;
use App\Services\ML\PrimaryMlBenchmarkHistoryBackfillService;
use App\Services\PortfolioLoggerService;
use App\Services\StockPriceHistoryService;
use App\Services\SyncLogService;
use App\Services\IgnoredPriceGapService;
use App\Services\PriceFetchService;
use App\Models\StockPrice;
use App\Support\TradingCalendar;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class PrimaryMlBenchmarkHistoryBackfillServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_primary_campaign_is_idempotent_and_reuses_missing_history_path(): void
    {
        $catalog = app(IndexCatalogService::class);
        $history = Mockery::mock(StockPriceHistoryService::class);
        $history->shouldReceive('fetchMissingHistory')
            ->twice()
            ->withArgs(function ($stock, $from, $to, $notify, $includePrefix): bool {
                return $stock->symbol === 'NIFTY50'
                    && $from->toDateString() === '2022-08-01'
                    && $to->toDateString() === '2026-09-21'
                    && $notify === false
                    && $includePrefix === true;
            })
            ->andReturn(
                [
                    'success' => true,
                    'cache_hit' => false,
                    'stored_rows' => 1000,
                    'fetched_rows' => 1000,
                    'gaps_remaining' => 0,
                    'errors' => [],
                ],
                [
                    'success' => true,
                    'cache_hit' => true,
                    'stored_rows' => 0,
                    'fetched_rows' => 0,
                    'gaps_remaining' => 0,
                    'errors' => [],
                ],
            );
        $syncLog = Mockery::mock(SyncLogService::class);
        $syncLog->shouldReceive('beginRun')->twice()->andReturn('run-1', 'run-2');
        $syncLog->shouldReceive('log')->twice();
        $syncLog->shouldReceive('completeRun')->twice();
        $logger = Mockery::mock(PortfolioLoggerService::class);
        $logger->shouldReceive('scheduler')->twice();

        $service = new PrimaryMlBenchmarkHistoryBackfillService(
            $catalog,
            $history,
            $syncLog,
            $logger,
            new MlBenchmarkHistoryPolicy(),
        );

        Carbon::setTestNow('2026-09-22 12:00:00');
        $first = $service->run();
        $second = $service->run();
        Carbon::setTestNow();

        $this->assertTrue($first['success']);
        $this->assertFalse($first['cache_hit']);
        $this->assertTrue($second['success']);
        $this->assertTrue($second['cache_hit']);
        $this->assertSame('2022-08-01', $first['requested_range']['from']);
        $this->assertSame('2026-09-21', $second['requested_range']['to']);
        $this->assertNotEmpty(Setting::getValue(PrimaryMlBenchmarkHistoryBackfillService::KEY_LAST_RUN_JSON));
    }

    public function test_dry_run_does_not_call_provider(): void
    {
        $history = Mockery::mock(StockPriceHistoryService::class);
        $history->shouldNotReceive('fetchMissingHistory');

        $service = new PrimaryMlBenchmarkHistoryBackfillService(
            app(IndexCatalogService::class),
            $history,
            Mockery::mock(SyncLogService::class),
            Mockery::mock(PortfolioLoggerService::class),
            new MlBenchmarkHistoryPolicy(),
        );

        $report = $service->run(Carbon::parse('2026-09-22'), null, dryRun: true);

        $this->assertTrue($report['dry_run']);
        $this->assertArrayHasKey('policy', $report);
        $this->assertArrayHasKey('stored_range', $report);
    }

    public function test_default_end_uses_last_required_trading_session_and_explicit_to_overrides(): void
    {
        Carbon::setTestNow('2026-09-26 10:00:00'); // Saturday
        $service = new PrimaryMlBenchmarkHistoryBackfillService(
            app(IndexCatalogService::class),
            Mockery::mock(StockPriceHistoryService::class),
            Mockery::mock(SyncLogService::class),
            Mockery::mock(PortfolioLoggerService::class),
            new MlBenchmarkHistoryPolicy(),
        );

        $expectedDefaultTo = TradingCalendar::lastRequiredPriceSession(Carbon::parse('2026-09-26'))->toDateString();
        $default = $service->plan();
        $explicit = $service->plan(Carbon::parse('2026-09-24'));
        Carbon::setTestNow();

        $this->assertSame($expectedDefaultTo, $default['requested_range']['to']);
        $this->assertSame('2026-09-24', $explicit['requested_range']['to']);
    }

    public function test_campaign_fails_and_audits_when_provider_cannot_fill_required_prefix(): void
    {
        Carbon::setTestNow('2026-09-22 12:00:00');
        $catalog = app(IndexCatalogService::class);
        $stock = $catalog->primaryBenchmarkStock();
        StockPrice::query()->create([
            'stock_id' => $stock->id,
            'price_date' => '2025-01-20',
            'close_price' => 100,
            'adjusted_close_price' => 100,
            'provider_source' => 'test',
            'data_source' => 'test',
            'created_at' => now(),
        ]);

        $fetch = Mockery::mock(PriceFetchService::class);
        $fetch->shouldReceive('providerChainForStock')->andReturn(['nse']);
        $fetch->shouldReceive('fetchFromProvider')->times(4)->andReturn([
            'rows' => [],
            'errors' => ['nse: prefix unavailable'],
        ]);
        $this->app->instance(PriceFetchService::class, $fetch);

        $historyLogger = Mockery::mock(PortfolioLoggerService::class);
        $historyLogger->shouldReceive('api')->andReturnNull();
        $history = new StockPriceHistoryService($historyLogger, app(IgnoredPriceGapService::class));
        $syncLog = Mockery::mock(SyncLogService::class);
        $syncLog->shouldReceive('beginRun')->once()->andReturn('failed-run');
        $syncLog->shouldReceive('log')->once()->withArgs(fn (...$args): bool => str_contains((string) ($args[3] ?? ''), 'Primary ML benchmark history failed'));
        $syncLog->shouldReceive('completeRun')->once()->withArgs(fn (...$args): bool => ($args[1] ?? null) === 'failed');
        $campaignLogger = Mockery::mock(PortfolioLoggerService::class);
        $campaignLogger->shouldReceive('scheduler')->once();

        $service = new PrimaryMlBenchmarkHistoryBackfillService(
            $catalog,
            $history,
            $syncLog,
            $campaignLogger,
            new MlBenchmarkHistoryPolicy(),
        );

        $report = $service->run();
        Carbon::setTestNow();

        $this->assertFalse($report['success']);
        $this->assertSame('2025-01-20', $report['stored_range']['from']);
        $this->assertGreaterThan(0, $report['result']['gaps_remaining']);
        $this->assertSame('2022-08-01', $report['result']['remaining_ranges'][0]['from']);
        $this->assertSame(1, StockPrice::query()->count());
    }

    public function test_cli_returns_nonzero_for_an_unsatisfied_campaign(): void
    {
        $service = Mockery::mock(PrimaryMlBenchmarkHistoryBackfillService::class);
        $service->shouldReceive('run')->once()->andReturn([
            'symbol' => 'NIFTY50',
            'requested_range' => ['from' => '2022-08-01', 'to' => '2026-09-21'],
            'policy' => ['version' => MlBenchmarkHistoryPolicy::VERSION, 'required_calendar_months' => 49],
            'stored_range' => ['from' => '2025-01-20', 'to' => '2026-09-21', 'rows' => 412],
            'dry_run' => false,
            'success' => false,
        ]);
        $this->app->instance(PrimaryMlBenchmarkHistoryBackfillService::class, $service);

        $this->artisan('portfolio:backfill-ml-benchmark-history')
            ->assertExitCode(1);
    }
}
