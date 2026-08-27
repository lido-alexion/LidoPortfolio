<?php

namespace App\Repositories\Tos;

use App\Models\Stock;
use App\Models\StockPrice;
use App\Support\TradingOsPagination;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * V4-FEAT-032 — market-data read queries shared by DataEngine / Evaluation / dataset ledger.
 * Does not own sync, version identity, or scoring.
 */
class MarketDataRepository
{
    /**
     * @return array{securities_active: int, price_bars: int, latest_price_date: mixed}
     */
    public function inspectionCounts(): array
    {
        return [
            'securities_active' => (int) Stock::query()
                ->where('is_active', true)
                ->where('is_benchmark', false)
                ->count(),
            'price_bars' => (int) StockPrice::query()->count(),
            'latest_price_date' => StockPrice::query()->max('price_date'),
        ];
    }

    /**
     * @return LengthAwarePaginator<int, Stock>
     */
    public function paginateSecurities(?string $search = null, int $pageSize = 50, int $page = 1): LengthAwarePaginator
    {
        $pageSize = TradingOsPagination::clampPageSize($pageSize);
        $page = TradingOsPagination::clampPage($page);
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

        return $query->paginate($pageSize, ['*'], 'page', $page);
    }

    public function findSecurity(int $id): ?Stock
    {
        return Stock::query()->find($id);
    }

    /**
     * @return LengthAwarePaginator<int, StockPrice>
     */
    public function paginatePriceBars(
        int $securityId,
        ?string $from = null,
        ?string $to = null,
        int $pageSize = 100,
        int $page = 1,
    ): LengthAwarePaginator {
        $pageSize = TradingOsPagination::clampPageSize($pageSize, TradingOsPagination::PRICE_BARS_MAX_PAGE_SIZE);
        $page = TradingOsPagination::clampPage($page);
        $query = StockPrice::query()
            ->where('stock_id', $securityId)
            ->orderByDesc('price_date');

        if ($from) {
            $query->whereDate('price_date', '>=', $from);
        }
        if ($to) {
            $query->whereDate('price_date', '<=', $to);
        }

        return $query->paginate($pageSize, ['*'], 'page', $page);
    }

    /**
     * Newest-first fetch then chronological bars for indicator evaluation.
     *
     * @return Collection<int, StockPrice>
     */
    public function recentClosePriceRows(int $stockId, int $limit = 400): Collection
    {
        return StockPrice::query()
            ->where('stock_id', $stockId)
            ->whereNotNull('close_price')
            ->orderByDesc('price_date')
            ->limit($limit)
            ->get(['open_price', 'high_price', 'low_price', 'close_price', 'volume']);
    }
}
