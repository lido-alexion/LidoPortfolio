<?php

namespace App\Services\ML;

use App\Models\V8\MlUniverseMembership;
use App\Models\Stock;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

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

    /**
     * Materialize a point-in-time eligible-universe snapshot from StoX's
     * current master. This is the controlled ingestion boundary; historical
     * backfills must provide their own dated source rather than pretending the
     * current master was true in the past.
     *
     * @return array{snapshot_key:string,effective_from:string,active_count:int,closed_count:int,inserted_count:int}
     */
    public function captureCurrentEligibleSnapshot(Carbon $effectiveFrom, string $source, ?string $snapshotKey = null): array
    {
        $date = $effectiveFrom->toDateString();
        $snapshotKey ??= $source.':'.$date;
        $stocks = Stock::query()
            ->where('is_active', true)
            ->where('admin_deactivated', false)
            ->where('is_benchmark', false)
            ->where('exchange', 'NSE')
            ->orderBy('id')
            ->get(['id', 'sector']);

        return DB::transaction(function () use ($date, $snapshotKey, $source, $stocks): array {
            $closedCount = MlUniverseMembership::query()
                ->where('universe_key', self::ACTIVE_ELIGIBLE_NSE)
                ->whereNull('effective_to')
                ->whereDate('effective_from', '<', $date)
                ->update(['effective_to' => Carbon::parse($date)->subDay()->toDateString(), 'updated_at' => now()]);

            $insertedCount = 0;
            foreach ($stocks as $stock) {
                $membership = MlUniverseMembership::query()
                    ->where('stock_id', $stock->id)
                    ->where('universe_key', self::ACTIVE_ELIGIBLE_NSE)
                    ->whereDate('effective_from', $date)
                    ->first();
                if ($membership === null) {
                    $membership = new MlUniverseMembership([
                        'stock_id' => $stock->id,
                        'universe_key' => self::ACTIVE_ELIGIBLE_NSE,
                        'effective_from' => $date,
                    ]);
                }
                if (! $membership->exists) {
                    $insertedCount++;
                }
                $membership->fill([
                    'effective_to' => null,
                    'sector_snapshot' => $stock->sector,
                    'source' => $source,
                    'snapshot_key' => $snapshotKey,
                ])->save();
            }

            $this->resetMemo();

            return [
                'snapshot_key' => $snapshotKey,
                'effective_from' => $date,
                'active_count' => $stocks->count(),
                'closed_count' => $closedCount,
                'inserted_count' => $insertedCount,
            ];
        });
    }
}
