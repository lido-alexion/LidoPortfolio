<?php

namespace App\Services\ML;

use App\Models\StockPrice;

/**
 * FEAT-057 market-context feature — cross-sectional % of active equities above 20-day SMA.
 */
class MlMarketBreadthFeatureService
{
    public function __construct(private readonly MlHistoricalUniverseMembershipService $memberships) {}

    /** @var array<string, float|null> */
    private array $memo = [];

    public function resetMemo(): void
    {
        $this->memo = [];
        $this->memberships->resetMemo();
    }

    public function pctAboveSma20(string $asOfDate): ?float
    {
        if (array_key_exists($asOfDate, $this->memo)) {
            return $this->memo[$asOfDate];
        }

        $stockIds = $this->memberships->stockIdsForDate($asOfDate);
        if ($stockIds === []) {
            return $this->memo[$asOfDate] = null;
        }

        $eligible = 0;
        $above = 0;
        foreach ($stockIds as $stockId) {
            $rows = StockPrice::query()
                ->where('stock_id', $stockId)
                ->whereDate('price_date', '<=', $asOfDate)
                ->orderByDesc('price_date')
                ->limit(21)
                ->get(['close_price']);

            if ($rows->count() < 20) {
                continue;
            }

            $closes = $rows->pluck('close_price')->map(fn ($v) => (float) $v)->all();
            $latest = $closes[0];
            $sma = array_sum(array_slice($closes, 0, 20)) / 20;
            $eligible++;
            if ($latest > $sma) {
                $above++;
            }
        }

        $value = $eligible >= 5 ? round(100 * $above / $eligible, 4) : null;
        $this->memo[$asOfDate] = $value;

        return $value;
    }
}
