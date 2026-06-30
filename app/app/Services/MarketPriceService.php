<?php

namespace App\Services;

use App\Models\Stock;
use App\Models\StockPrice;

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
     *     latest_price_date: ?string
     * }
     */
    public function summaryForStock(Stock $stock): array
    {
        $latest = StockPrice::query()
            ->where('stock_id', $stock->id)
            ->orderByDesc('price_date')
            ->first(['price_date', 'close_price']);

        $priceCount = StockPrice::query()
            ->where('stock_id', $stock->id)
            ->count();

        return [
            'price_count' => $priceCount,
            'has_price_history' => $priceCount > 0,
            'latest_close' => $latest !== null ? round((float) $latest->close_price, 4) : null,
            'latest_price_date' => $latest?->price_date?->toDateString(),
        ];
    }
}
