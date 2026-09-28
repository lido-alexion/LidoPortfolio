<?php

namespace App\Services\ML;

use App\Models\V8\MlUniverseMembership;

class MlHistoricalUniverseMembershipService
{
    public const ACTIVE_ELIGIBLE_NSE = 'active_eligible_nse';

    /** @var array<string, list<int>> */
    private array $idsMemo = [];

    /** @var array<string, string|null> */
    private array $sectorMemo = [];

    public function resetMemo(): void
    {
        $this->idsMemo = [];
        $this->sectorMemo = [];
    }

    /** @return list<int> */
    public function stockIdsForDate(string $date, string $universeKey = self::ACTIVE_ELIGIBLE_NSE): array
    {
        $key = $universeKey.':'.$date;
        if (array_key_exists($key, $this->idsMemo)) {
            return $this->idsMemo[$key];
        }

        $this->idsMemo[$key] = MlUniverseMembership::query()
            ->where('universe_key', $universeKey)
            ->whereDate('effective_from', '<=', $date)
            ->where(function ($query) use ($date): void {
                $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date);
            })
            ->orderBy('stock_id')
            ->pluck('stock_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        return $this->idsMemo[$key];
    }

    public function sectorForDate(int $stockId, string $date, string $universeKey = self::ACTIVE_ELIGIBLE_NSE): ?string
    {
        $key = $universeKey.':'.$stockId.':'.$date;
        if (array_key_exists($key, $this->sectorMemo)) {
            return $this->sectorMemo[$key];
        }

        $this->sectorMemo[$key] = MlUniverseMembership::query()
            ->where('stock_id', $stockId)
            ->where('universe_key', $universeKey)
            ->whereDate('effective_from', '<=', $date)
            ->where(function ($query) use ($date): void {
                $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date);
            })
            ->orderByDesc('effective_from')
            ->value('sector_snapshot');

        $sector = trim((string) $this->sectorMemo[$key]);
        $this->sectorMemo[$key] = $sector !== '' ? $sector : null;

        return $this->sectorMemo[$key];
    }
}
