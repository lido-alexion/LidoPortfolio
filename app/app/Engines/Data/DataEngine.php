<?php

namespace App\Engines\Data;

use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\SyncRun;
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
    ) {}

    public function datasetStatus(): array
    {
        $sync = $this->dailySync->status();
        $securityCount = Stock::query()->where('is_active', true)->where('is_benchmark', false)->count();
        $priceCount = StockPrice::query()->count();
        $latestPriceDate = StockPrice::query()->max('price_date');

        return [
            'published' => (bool) ($sync['synced_today'] ?? false),
            'dataset_version' => $this->currentDatasetVersion(),
            'securities_active' => $securityCount,
            'price_bars' => $priceCount,
            'latest_price_date' => $latestPriceDate,
            'daily_sync' => $sync,
        ];
    }

    public function currentDatasetVersion(): string
    {
        $date = StockPrice::query()->max('price_date');

        return $date ? 'ohlcv-'.$date : 'ohlcv-none';
    }

    /**
     * @return LengthAwarePaginator<int, Stock>
     */
    public function listSecurities(?string $search = null, int $pageSize = 50): LengthAwarePaginator
    {
        $pageSize = max(1, min($pageSize, 200));
        $query = Stock::query()
            ->where('is_benchmark', false)
            ->orderBy('symbol');

        if ($search !== null && trim($search) !== '') {
            $like = '%'.addcslashes(trim($search), '%_\\').'%';
            $query->where(function ($q) use ($like) {
                $q->where('symbol', 'like', $like)
                    ->orWhere('name', 'like', $like);
            });
        }

        return $query->paginate($pageSize);
    }

    public function securityDetails(int $id): ?Stock
    {
        return Stock::query()->find($id);
    }

    /**
     * @return LengthAwarePaginator<int, StockPrice>
     */
    public function queryPriceBars(int $securityId, ?string $from = null, ?string $to = null, int $pageSize = 100): LengthAwarePaginator
    {
        $pageSize = max(1, min($pageSize, 500));
        $query = StockPrice::query()
            ->where('stock_id', $securityId)
            ->orderByDesc('price_date');

        if ($from) {
            $query->whereDate('price_date', '>=', $from);
        }
        if ($to) {
            $query->whereDate('price_date', '<=', $to);
        }

        return $query->paginate($pageSize);
    }

    /**
     * Trigger daily import (wraps existing DailyMarketSyncService).
     *
     * @return array{accepted: bool, message: string, status: array}
     */
    public function triggerImport(bool $force = false): array
    {
        $this->logger->log('daily', 'DataEngine', 'info', 'Import trigger requested', [
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
