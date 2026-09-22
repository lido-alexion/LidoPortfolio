<?php

namespace Tests\Unit;

use App\Models\Setting;
use App\Services\IndexCatalogService;
use App\Services\ML\MlBenchmarkHistoryPolicy;
use App\Services\ML\PrimaryMlBenchmarkHistoryBackfillService;
use App\Services\PortfolioLoggerService;
use App\Services\StockPriceHistoryService;
use App\Services\SyncLogService;
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
            ->withArgs(function ($stock, Carbon $from, Carbon $to): bool {
                return $stock->symbol === 'NIFTY50'
                    && $from->toDateString() === '2022-08-01'
                    && $to->toDateString() === '2026-09-22';
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
        $this->assertSame('2026-09-22', $second['requested_range']['to']);
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
}
