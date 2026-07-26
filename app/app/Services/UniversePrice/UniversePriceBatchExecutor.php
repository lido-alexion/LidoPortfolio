<?php

namespace App\Services\UniversePrice;

use App\Models\Stock;
use App\Services\PriceFetchService;
use App\Services\PriceSyncNotificationContext;
use App\Services\SyncLogService;
use App\Services\UniversePriceSyncService;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Owns the per-stock provider fetch loop for a universe price sync batch:
 * calling PriceFetchService::syncStock per stock, the inter-stock delay,
 * stats accumulation, and per-stock sync-log messages.
 *
 * Orchestration (enable flags, in-progress lock, maintenance windows, cursor,
 * status, sync-run lifecycle) stays on UniversePriceSyncService, which
 * prepares the batch/stats and delegates the loop to this class.
 */
class UniversePriceBatchExecutor
{
    public function __construct(
        protected PriceFetchService $priceFetch,
        protected SyncLogService $syncLog,
    ) {}

    /**
     * Runs the fetch loop over the given stock batch, mutating and returning
     * the stats accumulator plus the id of the last stock processed.
     *
     * @param  Collection<int, Stock>  $stocks
     * @param  array<string, mixed>  $stats
     * @return array{stats: array<string, mixed>, last_stock_id: int}
     */
    public function run(
        Collection $stocks,
        Carbon $from,
        Carbon $to,
        int $delayMs,
        string $runId,
        string $jobName,
        array $stats,
    ): array {
        $lastStockId = 0;

        PriceSyncNotificationContext::withoutTelegram(function () use (
            $stocks,
            $from,
            $to,
            $delayMs,
            $runId,
            $jobName,
            &$stats,
            &$lastStockId,
        ) {
            foreach ($stocks as $index => $stock) {
                $lastStockId = $stock->id;
                $stats['processed']++;

                try {
                    $result = $this->priceFetch->syncStock(
                        $stock,
                        $from,
                        $to,
                        notifyTelegramOnFailure: false,
                    );

                    if ($result['success']) {
                        $stats['succeeded']++;
                        $stats['stored_rows'] += (int) ($result['stored_rows'] ?? 0);
                        if (! empty($result['cache_hit'])) {
                            $stats['cache_hits']++;
                        }
                    } else {
                        $stats['failed']++;
                        $error = $stock->symbol.': '.implode('; ', $result['errors'] ?? ['sync failed']);
                        if (UniversePriceSyncService::looksLikeRateLimit($error)) {
                            $stats['rate_limit_hits']++;
                        }
                        if (count($stats['errors']) < 20) {
                            $stats['errors'][] = $error;
                        }
                        $this->syncLog->log($runId, $jobName, 'warning', 'Universe stock sync returned no rows', [
                            'symbol' => $stock->symbol,
                            'errors' => $result['errors'] ?? [],
                        ]);
                    }
                } catch (\Throwable $e) {
                    $stats['failed']++;
                    if (UniversePriceSyncService::looksLikeRateLimit($e->getMessage())) {
                        $stats['rate_limit_hits']++;
                    }
                    if (count($stats['errors']) < 20) {
                        $stats['errors'][] = $stock->symbol.': '.$e->getMessage();
                    }
                    $this->syncLog->log($runId, $jobName, 'error', 'Universe stock sync failed', [
                        'symbol' => $stock->symbol,
                        'failure_reason' => $e->getMessage(),
                    ]);
                }

                if ($delayMs > 0 && $index < $stocks->count() - 1) {
                    usleep($delayMs * 1000);
                }
            }
        });

        return [
            'stats' => $stats,
            'last_stock_id' => $lastStockId,
        ];
    }
}
