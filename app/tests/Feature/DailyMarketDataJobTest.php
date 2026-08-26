<?php

namespace Tests\Feature;

use App\Jobs\DailyMarketDataJob;
use App\Services\AdminOperationalAlertService;
use App\Services\AlertExpirationService;
use App\Services\Alerts\AlertPolicyEvaluationService;
use App\Services\BenchmarkPriceSyncService;
use App\Services\DailyMarketSyncService;
use App\Services\MetricsUpdateService;
use App\Services\PortfolioCalculationService;
use App\Services\PriceFetchService;
use App\Services\SyncLogService;
use App\Services\SystemLogService;
use App\Services\TelegramNotificationService;
use Mockery;
use Tests\TestCase;

/**
 * Pure Mockery job failure path — no DB. RefreshDatabase was removed (V4-BUG-004)
 * to avoid full-suite `db.schema` container pollution.
 */
class DailyMarketDataJobTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_daily_job_logs_and_notifies_on_failure(): void
    {
        $priceFetch = Mockery::mock(PriceFetchService::class);
        $metrics = Mockery::mock(MetricsUpdateService::class);
        $portfolio = Mockery::mock(PortfolioCalculationService::class);
        $telegram = Mockery::mock(TelegramNotificationService::class);
        $logger = Mockery::mock(SystemLogService::class);
        $syncLog = Mockery::mock(SyncLogService::class);
        $dailySyncStatus = Mockery::mock(DailyMarketSyncService::class);
        $dailySyncStatus->shouldNotReceive('markSuccessful');
        $dailySyncStatus->shouldReceive('clearInProgress')->once();
        $alertExpiration = Mockery::mock(AlertExpirationService::class);
        $alertExpiration->shouldReceive('latestPortfolioPriceDate')->andReturn('2026-01-01');
        $alertPolicyEvaluation = Mockery::mock(AlertPolicyEvaluationService::class);
        $benchmarkSync = Mockery::mock(BenchmarkPriceSyncService::class);
        $adminAlerts = Mockery::mock(AdminOperationalAlertService::class);
        $adminAlerts->shouldReceive('syncAndNotify')->once();
        $this->app->instance(AdminOperationalAlertService::class, $adminAlerts);

        $benchmarkSync->shouldReceive('syncIfNeeded')
            ->once()
            ->with(true)
            ->andThrow(new \RuntimeException('sync failed'));
        $telegram->shouldReceive('sendSyncFailureAlert')->once();
        $logger->shouldReceive('log')->once();
        $syncLog->shouldReceive('beginRun')->once()->andReturn('run-test');
        $syncLog->shouldReceive('log')->atLeast()->once();
        $syncLog->shouldReceive('completeRun')->once();

        $job = new DailyMarketDataJob();
        $this->expectException(\RuntimeException::class);
        $job->handle(
            $priceFetch,
            $metrics,
            $portfolio,
            $telegram,
            $logger,
            $syncLog,
            $dailySyncStatus,
            $alertExpiration,
            $alertPolicyEvaluation,
            $benchmarkSync,
        );
    }
}
