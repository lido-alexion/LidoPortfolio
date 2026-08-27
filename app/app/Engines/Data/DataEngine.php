<?php

namespace App\Engines\Data;

use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\SyncRun;
use App\Repositories\Tos\MarketDataRepository;
use App\Services\DailyMarketSyncService;
use App\Services\PortfolioLoggerService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Schema;

/**
 * Data Engine — owns market data publication for downstream engines.
 * Wraps existing stock master + OHLCV + sync services (no duplicate SOS).
 */
class DataEngine
{
    public function __construct(
        protected DailyMarketSyncService $dailySync,
        protected PortfolioLoggerService $logger,
        protected DatasetVersionLedger $datasetVersions,
        protected MarketDataRepository $marketData,
    ) {}

    public function datasetStatus(): array
    {
        $sync = $this->dailySync->status();
        $counts = $this->marketData->inspectionCounts();

        return [
            'published' => (bool) ($sync['synced_today'] ?? false),
            'dataset_version' => $this->currentDatasetVersion(),
            'securities_active' => $counts['securities_active'],
            'price_bars' => $counts['price_bars'],
            'latest_price_date' => $counts['latest_price_date'],
            'daily_sync' => $sync,
        ];
    }

    public function currentDatasetVersion(): string
    {
        return $this->datasetVersions->currentVersionKey();
    }

    /**
     * @return LengthAwarePaginator<int, Stock>
     */
    public function listSecurities(?string $search = null, int $pageSize = 50, int $page = 1): LengthAwarePaginator
    {
        return $this->marketData->paginateSecurities($search, $pageSize, $page);
    }

    public function securityDetails(int $id): ?Stock
    {
        return $this->marketData->findSecurity($id);
    }

    /**
     * @return LengthAwarePaginator<int, StockPrice>
     */
    public function queryPriceBars(int $securityId, ?string $from = null, ?string $to = null, int $pageSize = 100, int $page = 1): LengthAwarePaginator
    {
        return $this->marketData->paginatePriceBars($securityId, $from, $to, $pageSize, $page);
    }

    /**
     * Trigger daily import (wraps existing DailyMarketSyncService).
     *
     * @return array{accepted: bool, message: string, status: array}
     */
    public function triggerImport(bool $force = false): array
    {
        $this->logger->event('DataEngine', 'data.import_requested', 'info', 'Import trigger requested', [
            'force' => $force,
        ]);

        $result = $this->dailySync->runDailySyncIfNeeded($force);

        return [
            'accepted' => ! ($result['skipped'] ?? false) && ($result['success'] ?? false),
            'message' => (string) ($result['message'] ?? 'Import finished.'),
            'status' => $this->dailySync->status(),
            'result' => $result,
        ];
    }

    public function importHistory(int $limit = 20): array
    {
        if (! Schema::hasTable('portfolio_sync_runs')) {
            return [];
        }

        return SyncRun::query()
            ->orderByDesc('started_at')
            ->limit($limit)
            ->get()
            ->map(fn (SyncRun $run) => [
                'id' => $run->id,
                'status' => $run->status,
                'started_at' => optional($run->started_at)?->toIso8601String(),
                'completed_at' => optional($run->finished_at)?->toIso8601String(),
                'job_name' => $run->job_name,
            ])
            ->all();
    }
}
