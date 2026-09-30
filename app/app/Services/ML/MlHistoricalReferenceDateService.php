<?php

namespace App\Services\ML;

use App\Models\StockPrice;
use App\Support\TradingCalendar;
use Carbon\Carbon;

/**
 * Resolves ML historical reference dates from dates actually present in
 * StoX market history. It never expands a range into calendar dates.
 */
class MlHistoricalReferenceDateService
{
    /** @return list<string> */
    public function datesBetween(Carbon $from, Carbon $to): array
    {
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();
        $dates = StockPrice::query()
            ->whereBetween('price_date', [$fromDate, $toDate])
            ->whereHas('stock', fn ($query) => $query->where('exchange', 'NSE')->where('is_benchmark', true))
            ->select('price_date')
            ->distinct()
            ->orderBy('price_date')
            ->pluck('price_date');

        if ($dates->isEmpty()) {
            $dates = StockPrice::query()
                ->whereBetween('price_date', [$fromDate, $toDate])
                ->whereHas('stock', fn ($query) => $query->where('exchange', 'NSE'))
                ->select('price_date')
                ->distinct()
                ->orderBy('price_date')
                ->pluck('price_date');
        }

        return $dates
            ->map(fn ($date): string => Carbon::parse($date)->toDateString())
            ->filter(fn (string $date): bool => TradingCalendar::isEquitySessionDate(Carbon::parse($date)))
            ->unique()
            ->values()
            ->all();
    }
}
