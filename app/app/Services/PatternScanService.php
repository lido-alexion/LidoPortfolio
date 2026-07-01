<?php

namespace App\Services;

use App\Models\PortfolioProfile;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\WatchlistItem;
use Illuminate\Support\Collection;

class PatternScanService
{
    public function __construct(
        protected PatternDetectionService $detection,
    ) {}

    /**
     * @return array{
     *     scope: string,
     *     actionable_only: bool,
     *     results: list<array{
     *         stock_id: int,
     *         symbol: string,
     *         name: ?string,
     *         exchange: ?string,
     *         matches: list<array<string, mixed>>
     *     }>
     * }
     */
    public function scan(PortfolioProfile $profile, string $scope, bool $actionableOnly = true): array
    {
        $stocks = match ($scope) {
            'watchlist' => $this->stocksFromWatchlist($profile),
            'holdings' => $this->stocksFromHoldings($profile),
            default => collect(),
        };

        $results = [];

        foreach ($stocks as $stock) {
            $bars = $this->loadBars($stock);
            if ($bars === []) {
                continue;
            }

            $matches = $this->detection->scanBars($bars, $actionableOnly);
            if ($matches === []) {
                continue;
            }

            $results[] = [
                'stock_id' => $stock->id,
                'symbol' => $stock->symbol,
                'name' => $stock->name,
                'exchange' => $stock->exchange,
                'matches' => $matches,
            ];
        }

        return [
            'scope' => $scope,
            'actionable_only' => $actionableOnly,
            'results' => $results,
        ];
    }

    /** @return Collection<int, Stock> */
    protected function stocksFromHoldings(PortfolioProfile $profile): Collection
    {
        return $profile->holdings()
            ->where('quantity', '>', 0)
            ->with('stock')
            ->get()
            ->pluck('stock')
            ->filter()
            ->unique('id')
            ->values();
    }

    /** @return Collection<int, Stock> */
    protected function stocksFromWatchlist(PortfolioProfile $profile): Collection
    {
        return WatchlistItem::query()
            ->where('profile_id', $profile->id)
            ->with('stock')
            ->get()
            ->pluck('stock')
            ->filter()
            ->unique('id')
            ->values();
    }

    /**
     * @return list<array{date: string, open: float, high: float, low: float, close: float}>
     */
    protected function loadBars(Stock $stock): array
    {
        $rows = StockPrice::query()
            ->where('stock_id', $stock->id)
            ->orderBy('price_date')
            ->get(['price_date', 'open_price', 'high_price', 'low_price', 'close_price']);

        $bars = [];

        foreach ($rows as $row) {
            $close = (float) $row->close_price;
            $open = $row->open_price !== null ? (float) $row->open_price : $close;
            $high = $row->high_price !== null ? (float) $row->high_price : max($open, $close);
            $low = $row->low_price !== null ? (float) $row->low_price : min($open, $close);

            $bars[] = [
                'date' => $row->price_date->toDateString(),
                'open' => $open,
                'high' => $high,
                'low' => $low,
                'close' => $close,
            ];
        }

        return $bars;
    }
}
