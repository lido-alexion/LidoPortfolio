<?php

namespace App\Services;

use App\Models\StockPrice;
use Carbon\Carbon;

class StockQuoteService
{
    /**
     * Latest close on or before $asOf (global history, not scoped to buy date).
     */
    public function latestClose(int $stockId, ?Carbon $asOf = null): float
    {
        $query = StockPrice::query()->where('stock_id', $stockId);

        if ($asOf) {
            $query->where('price_date', '<=', $asOf->toDateString());
        }

        return (float) ($query->orderByDesc('price_date')->value('close_price') ?? 0);
    }

    /**
     * Latest close on or after $since (used for stoploss / since-buy displays).
     */
    public function latestCloseSince(int $stockId, Carbon $since, ?Carbon $asOf = null): ?float
    {
        $query = StockPrice::query()
            ->where('stock_id', $stockId)
            ->where('price_date', '>=', $since->toDateString());

        if ($asOf) {
            $query->where('price_date', '<=', $asOf->toDateString());
        }

        $close = $query->orderByDesc('price_date')->value('close_price');

        return $close !== null ? (float) $close : null;
    }
}
