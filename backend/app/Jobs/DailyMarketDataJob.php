<?php

namespace App\Jobs;

use App\Models\Holding;
use App\Models\Stock;
use App\Models\User;
use App\Services\AlertExpirationService;
use App\Services\DailyMarketSyncService;
use App\Services\MetricsUpdateService;
use App\Services\PortfolioCalculationService;
use App\Services\PortfolioLoggerService;
use App\Services\PriceFetchService;
use App\Services\PriceSyncNotificationContext;
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
        PortfolioLoggerService $portfolioLogger,
        DailyMarketSyncService $dailySyncStatus,
        AlertExpirationService $alertExpiration,
    ): void {
        $startedAt = now();
        $processed = 0;
        $failed = 0;
        $skipped = 0;
        $priceDateBefore = $alertExpiration->latestPortfolioPriceDate();

        $portfolioLogger->scheduler('info', 'Daily market data job started', [
            'start_time' => $startedAt->toIso8601String(),
        ]);

        try {
            PriceSyncNotificationContext::withoutTelegram(function () use (
                $priceFetch,
                $metricsUpdate,
                $portfolioCalculation,
                $telegram,
                $portfolioLogger,
                $dailySyncStatus,
                $alertExpiration,
                $startedAt,
                &$processed,
                &$failed,
                &$skipped,
                $priceDateBefore,
            ) {
                $priceFetch->syncBenchmark();

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
                        $portfolioLogger->scheduler('debug', 'Skipped inactive stock', [
                            'stock_id' => $stock->id,
                            'symbol' => $stock->symbol,
                        ]);
                        continue;
                    }

                    try {
                        $sync = $priceFetch->syncStock($stock, now()->subDays(10), now(), notifyTelegramOnFailure: false);
                        $processed++;
                        if (! $sync['success']) {
                            $failed++;
                            $portfolioLogger->scheduler('warning', 'Stock sync returned no rows', [
                                'symbol' => $stock->symbol,
                                'errors' => $sync['errors'] ?? [],
                            ]);
                        }
                    } catch (\Throwable $e) {
                        $failed++;
                        $portfolioLogger->scheduler('error', 'Stock sync failed', [
                            'symbol' => $stock->symbol,
                            'failure_reason' => $e->getMessage(),
                        ]);
                    }
                }

                $metricsUpdate->updateAllTrackedStocks();

                User::query()->each(function (User $user) use ($portfolioCalculation) {
                    $portfolioCalculation->storeSnapshot($user);
                });

                $portfolioLogger->scheduler('info', 'Daily market data job completed', [
                    'start_time' => $startedAt->toIso8601String(),
                    'end_time' => now()->toIso8601String(),
                    'stocks_processed' => $processed,
                    'failures' => $failed,
                    'skipped' => $skipped,
                ]);

                if ($failed === 0) {
                    $dailySyncStatus->markSuccessful();

                    $priceDateAfter = $alertExpiration->latestPortfolioPriceDate();
                    if ($priceDateAfter && (! $priceDateBefore || $priceDateAfter > $priceDateBefore)) {
                        $expiredOnRefresh = $alertExpiration->expireBeforeTradingDay(
                            Carbon::parse($priceDateAfter)->startOfDay(),
                        );
                        $portfolioLogger->scheduler('info', 'Alerts expired after new trading day prices', [
                            'trading_day' => $priceDateAfter,
                            'expired_count' => $expiredOnRefresh,
                        ]);
                    }
                } else {
                    $dailySyncStatus->markIncomplete($processed, $failed);
                    $telegram->sendSyncFailureAlert(
                        "Daily sync finished with {$failed} failure(s) out of {$processed} held stock(s).",
                    );
                }
            });
        } catch (\Throwable $e) {
            $logger->log('scheduler', 'Daily market data job failed: '.$e->getMessage());
            $portfolioLogger->scheduler('error', 'Daily market data job failed', [
                'start_time' => $startedAt->toIso8601String(),
                'end_time' => now()->toIso8601String(),
                'failure_reason' => $e->getMessage(),
            ]);
            $telegram->sendSyncFailureAlert($e->getMessage());
            throw $e;
        } finally {
            $dailySyncStatus->clearInProgress();
        }
    }
}
