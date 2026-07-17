<?php

namespace App\Services;

use App\Models\Stock;
use App\Models\StockPrice;
use Carbon\Carbon;

class MarketPriceService
{
    /**
     * Cached OHLCV from portfolio_stock_prices (no holding required).
     *
     * @return array{
     *     stock: array<string, mixed>,
     *     data: \Illuminate\Support\Collection,
     *     price_count: int,
     *     has_price_history: bool,
     *     from_date: ?string,
     *     to_date: ?string,
     *     latest_close: ?float,
     *     latest_price_date: ?string
     * }
     */
    public function historyForStock(Stock $stock): array
    {
        $prices = StockPrice::query()
            ->where('stock_id', $stock->id)
            ->orderByDesc('price_date')
            ->get();

        $latest = $prices->first();

        return [
            'stock' => $stock->only(['id', 'symbol', 'name', 'exchange']),
            'data' => $prices,
            'price_count' => $prices->count(),
            'has_price_history' => $prices->isNotEmpty(),
            'from_date' => $prices->last()?->price_date?->toDateString(),
            'to_date' => $latest?->price_date?->toDateString(),
            'latest_close' => $latest !== null ? round((float) $latest->close_price, 4) : null,
            'latest_price_date' => $latest?->price_date?->toDateString(),
        ];
    }

    /**
     * @return array{
     *     price_count: int,
     *     has_price_history: bool,
     *     latest_close: ?float,
     *     latest_price_date: ?string,
     *     previous_close: ?float,
     *     daily_change: ?float,
     *     daily_change_percent: ?float,
     *     is_price_fresh: bool
     * }
     */
    public function summaryForStock(Stock $stock): array
    {
        $rows = StockPrice::query()
            ->where('stock_id', $stock->id)
            ->orderByDesc('price_date')
            ->limit(2)
            ->get(['price_date', 'close_price']);

        $latest = $rows->get(0);
        $previous = $rows->get(1);
        $priceCount = StockPrice::query()
            ->where('stock_id', $stock->id)
            ->count();

        $latestClose = $latest !== null ? round((float) $latest->close_price, 4) : null;
        $previousClose = $previous !== null ? round((float) $previous->close_price, 4) : null;
        $latestDate = $latest?->price_date?->toDateString();

        $dailyChange = null;
        $dailyChangePercent = null;
        if ($latestClose !== null && $previousClose !== null && $previousClose > 0) {
            $dailyChange = round($latestClose - $previousClose, 4);
            $dailyChangePercent = round(($dailyChange / $previousClose) * 100, 2);
        }

        return [
            'price_count' => $priceCount,
            'has_price_history' => $priceCount > 0,
            'latest_close' => $latestClose,
            'latest_price_date' => $latestDate,
            'previous_close' => $previousClose,
            'daily_change' => $dailyChange,
            'daily_change_percent' => $dailyChangePercent,
            'is_price_fresh' => $this->isPriceFresh($latestDate),
        ];
    }

    protected function isPriceFresh(?string $latestPriceDate): bool
    {
        if ($latestPriceDate === null || $latestPriceDate === '') {
            return false;
        }

        $tz = config('app.timezone', 'Asia/Kolkata');
        $today = now($tz)->toDateString();

        return Carbon::parse($latestPriceDate, $tz)->toDateString() === $today;
    }
}
