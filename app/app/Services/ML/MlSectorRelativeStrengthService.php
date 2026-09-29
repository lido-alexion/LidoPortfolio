<?php

namespace App\Services\ML;

use App\Models\Stock;
use App\Models\StockPrice;

/**
 * FEAT-057 sector context — stock 3m return minus sector-peer median 3m return (%).
 */
class MlSectorRelativeStrengthService
{
    public function __construct(private readonly MlHistoricalUniverseMembershipService $memberships) {}

    /** @var array<string, float|null> */
    private array $memo = [];

    public function resetMemo(): void
    {
        $this->memo = [];
        $this->memberships->resetMemo();
    }

    public function sectorForDate(int $stockId, string $date): ?string
    {
        return $this->memberships->sectorForDate($stockId, $date);
    }

    public function relativeStrength3m(Stock $stock, string $asOfDate): ?float
    {
        $sector = $this->memberships->sectorForDate((int) $stock->id, $asOfDate);
        if ($sector === '') {
            return null;
        }
        if ($sector === null) {
            return null;
        }

        $key = $stock->id.':'.$asOfDate;
        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        $stockReturn = $this->returnOverDays($stock->id, $asOfDate, 63);
        if ($stockReturn === null) {
            $this->memo[$key] = null;

            return null;
        }

        $peerIds = array_values(array_filter(
            $this->memberships->stockIdsForDate($asOfDate),
            fn (int $id): bool => $id !== (int) $stock->id
                && $this->memberships->sectorForDate($id, $asOfDate) === $sector,
        ));
        $peerReturns = collect($peerIds)
            ->map(fn (int $id): ?float => $this->returnOverDays($id, $asOfDate, 63))
            ->filter(fn (?float $v): bool => $v !== null)
            ->values()
            ->all();

        if ($peerReturns === []) {
            $this->memo[$key] = null;

            return null;
        }

        sort($peerReturns);
        $median = $peerReturns[(int) floor(count($peerReturns) / 2)];
        $value = round($stockReturn - $median, 6);
        $this->memo[$key] = $value;

        return $value;
    }

    private function returnOverDays(int $stockId, string $asOfDate, int $lookback): ?float
    {
        $rows = StockPrice::query()
            ->where('stock_id', $stockId)
            ->whereDate('price_date', '<=', $asOfDate)
            ->orderByDesc('price_date')
            ->limit($lookback + 1)
            ->get(['close_price', 'price_date']);

        if ($rows->count() < $lookback + 1) {
            return null;
        }

        $latest = (float) $rows->first()->close_price;
        $past = (float) $rows->last()->close_price;
        if ($past <= 0.0) {
            return null;
        }

        return (($latest - $past) / $past) * 100;
    }
}
