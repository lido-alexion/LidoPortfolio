<?php

namespace App\Jobs;

use App\Models\Holding;
use App\Models\PortfolioProfile;
use App\Models\Stock;
use App\Services\AdminOperationalAlertService;
use App\Services\AlertExpirationService;
use App\Services\Alerts\AlertPolicyEvaluationService;
use App\Services\BenchmarkPriceSyncService;
use App\Services\DailyMarketSyncService;
use App\Services\MetricsUpdateService;
use App\Services\PortfolioCalculationService;
use App\Services\PriceFetchService;
use App\Services\PriceSyncNotificationContext;
use App\Services\SyncLogService;
use App\Services\SystemLogService;
use App\Services\TelegramNotificationService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DailyMarketDataJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public function handle(
        PriceFetchService $priceFetch,
        MetricsUpdateService $metricsUpdate,
        PortfolioCalculationService $portfolioCalculation,
        TelegramNotificationService $telegram,
        SystemLogService $logger,
        SyncLogService $syncLog,
        DailyMarketSyncService $dailySyncStatus,
        AlertExpirationService $alertExpiration,
        AlertPolicyEvaluationService $alertPolicyEvaluation,
        BenchmarkPriceSyncService $benchmarkSync,
    ): void {
        $jobName = SyncLogService::JOB_DAILY_MARKET_DATA;
        $runId = $syncLog->beginRun($jobName);
        $startedAt = now();
        $processed = 0;
        $failed = 0;
        $skipped = 0;
        $priceDateBefore = $alertExpiration->latestPortfolioPriceDate();

        $syncLog->log($runId, $jobName, 'info', 'Daily market data job started', [
            'start_time' => $startedAt->toIso8601String(),
        ]);

        try {
            PriceSyncNotificationContext::withoutTelegram(function () use (
                $priceFetch,
                $metricsUpdate,
                $portfolioCalculation,
                $telegram,
                $syncLog,
                $dailySyncStatus,
                $alertExpiration,
                $alertPolicyEvaluation,
                $benchmarkSync,
                $startedAt,
                $runId,
                $jobName,
                &$processed,
                &$failed,
                &$skipped,
                $priceDateBefore,
            ) {
                $benchmarkSync->syncIfNeeded(force: true);

                $heldStockIds = Holding::query()
                    ->where('quantity', '>', 0)
                    ->distinct()
                    ->pluck('stock_id');

                $stocks = Stock::query()
                    ->where('is_active', true)
                    ->where('is_benchmark', false)
                    ->whereIn('id', $heldStockIds)
                    ->get();

                foreach ($stocks as $stock) {
                    if (! $stock->is_active) {
                        $skipped++;

                        continue;
                    }

                    try {
                        $sync = $priceFetch->syncStock($stock, now()->subDays(10), now(), notifyTelegramOnFailure: false);
                        $processed++;
                        if (! $sync['success']) {
                            $failed++;
                            $syncLog->log($runId, $jobName, 'warning', 'Stock sync returned no rows', [
                                'symbol' => $stock->symbol,
                                'errors' => $sync['errors'] ?? [],
                            ]);
                        }
                    } catch (\Throwable $e) {
                        $failed++;
                        $syncLog->log($runId, $jobName, 'error', 'Stock sync failed', [
                            'symbol' => $stock->symbol,
                            'failure_reason' => $e->getMessage(),
                        ]);
                    }
                }

                $metricsUpdate->updateAllTrackedStocks();

                $policyResult = $alertPolicyEvaluation->evaluateAllProfiles();
                $syncLog->log($runId, $jobName, 'info', 'Alert policies evaluated', $policyResult);

                $profileCount = PortfolioProfile::query()->count();
                PortfolioProfile::query()->each(function (PortfolioProfile $profile) use ($portfolioCalculation) {
                    $portfolioCalculation->storeSnapshot($profile);
                });
                $syncLog->log($runId, $jobName, 'info', 'Portfolio snapshots stored', [
                    'profile_count' => $profileCount,
                ]);

                $stats = [
                    'stocks_processed' => $processed,
                    'failures' => $failed,
                    'skipped' => $skipped,
                ];

                $syncLog->log($runId, $jobName, 'info', 'Daily market data job completed', array_merge($stats, [
                    'start_time' => $startedAt->toIso8601String(),
                    'end_time' => now()->toIso8601String(),
                ]));

                if ($failed === 0) {
                    $dailySyncStatus->markSuccessful();
                    $syncLog->completeRun($runId, 'success', $stats);

                    try {
                        app(\App\Services\Analytics\MarketDepthService::class)->refreshLatest(forceRefresh: true);
                        $syncLog->log($runId, $jobName, 'info', 'Market depth matrix refreshed');
                    } catch (\Throwable $e) {
                        $syncLog->log($runId, $jobName, 'warning', 'Market depth refresh failed', [
                            'failure_reason' => $e->getMessage(),
                        ]);
                    }

                    $priceDateAfter = $alertExpiration->latestPortfolioPriceDate();
                    if ($priceDateAfter && (! $priceDateBefore || $priceDateAfter > $priceDateBefore)) {
                        $expiredOnRefresh = $alertExpiration->expireBeforeTradingDay(
                            Carbon::parse($priceDateAfter)->startOfDay(),
                        );
                        $syncLog->log($runId, $jobName, 'info', 'Alerts expired after new trading day prices', [
                            'trading_day' => $priceDateAfter,
                            'expired_count' => $expiredOnRefresh,
                        ]);
                    }
                } else {
                    $dailySyncStatus->markIncomplete($processed, $failed);
                    $summary = "Daily sync finished with {$failed} failure(s) out of {$processed} held stock(s).";
                    $syncLog->completeRun($runId, 'partial', $stats, $summary);
                    $telegram->sendSyncFailureAlert($summary);
                }
            });
        } catch (\Throwable $e) {
            $logger->log('scheduler', 'Daily market data job failed: '.$e->getMessage());
            $syncLog->log($runId, $jobName, 'error', 'Daily market data job failed', [
                'start_time' => $startedAt->toIso8601String(),
                'end_time' => now()->toIso8601String(),
                'failure_reason' => $e->getMessage(),
            ]);
            $syncLog->completeRun($runId, 'failed', [
                'stocks_processed' => $processed,
                'failures' => $failed,
                'skipped' => $skipped,
            ], $e->getMessage());
            $telegram->sendSyncFailureAlert($e->getMessage());
            throw $e;
        } finally {
            $dailySyncStatus->clearInProgress();
            app(AdminOperationalAlertService::class)->syncAndNotify();
        }
    }
}
