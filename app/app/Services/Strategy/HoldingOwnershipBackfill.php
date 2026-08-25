<?php

namespace App\Services\Strategy;

use App\Models\Holding;
use App\Models\Transaction;
use Illuminate\Support\Collection;

/**
 * V3 §10.5 conservative ownership backfill.
 *
 * Safe inference: tag an unmanaged open position as strategy S only when every
 * remaining contributing buy lot is recommendation-linked to S (same strategy_version
 * family). Never infer from “the portfolio has exactly one strategy”.
 *
 * Does not split mixed lots, rewrite quantities/cost, or merge into an existing
 * strategy-owned row for the same stock (unspecified merge).
 */
class HoldingOwnershipBackfill
{
    public function inferAll(): int
    {
        $updated = 0;
        $profileIds = Holding::query()->distinct()->pluck('profile_id');
        foreach ($profileIds as $profileId) {
            $updated += $this->inferForProfileId((int) $profileId);
        }

        return $updated;
    }

    public function inferForProfileId(int $profileId): int
    {
        $updated = 0;
        $holdings = Holding::query()
            ->where('profile_id', $profileId)
            ->orderBy('id')
            ->get();

        $byStock = $holdings->groupBy(fn (Holding $h) => (int) $h->stock_id);
        foreach ($byStock as $stockId => $stockHoldings) {
            $inferredStrategyId = $this->inferredStrategyIdForOpenLots($profileId, (int) $stockId);
            foreach ($stockHoldings as $holding) {
                if ($this->assignIfSafe($holding, $stockHoldings, $inferredStrategyId)) {
                    $updated++;
                }
            }
        }

        return $updated;
    }

    /**
     * One-shot repair for the 2026-08-19 heuristic that tagged every unmanaged
     * holding with the profile’s only strategy. Only reverts a strategy-owned row
     * when that stock has no recommendation-linked buy for that strategy.
     *
     * Not used after unmanaged adoption (Phase 2): adoption may set owner without
     * attaching recommendation_id to historical manual fills.
     */
    public function revertUnattestedStrategyOwnershipForProfile(int $profileId): int
    {
        $reverted = 0;
        $holdings = Holding::query()
            ->where('profile_id', $profileId)
            ->whereNotNull('strategy_id')
            ->where('owner_key', '!=', Holding::OWNER_UNMANAGED)
            ->orderBy('id')
            ->get();

        $unmanagedKeys = Holding::query()
            ->where('profile_id', $profileId)
            ->where('owner_key', Holding::OWNER_UNMANAGED)
            ->get()
            ->mapWithKeys(fn (Holding $h) => [(int) $h->stock_id => true]);

        foreach ($holdings as $holding) {
            $strategyId = (int) $holding->strategy_id;
            if ($strategyId <= 0) {
                continue;
            }
            if ($this->stockHasRecommendationLinkedBuyForStrategy(
                $profileId,
                (int) $holding->stock_id,
                $strategyId,
            )) {
                continue;
            }
            if ($unmanagedKeys->has((int) $holding->stock_id)) {
                // Unique (profile, stock, unmanaged) already occupied — do not merge.
                continue;
            }

            $holding->forceFill([
                'strategy_id' => null,
                'owner_key' => Holding::OWNER_UNMANAGED,
            ])->save();
            $unmanagedKeys[(int) $holding->stock_id] = true;
            $reverted++;
        }

        return $reverted;
    }

    public function revertUnattestedStrategyOwnershipAll(): int
    {
        $reverted = 0;
        $profileIds = Holding::query()->distinct()->pluck('profile_id');
        foreach ($profileIds as $profileId) {
            $reverted += $this->revertUnattestedStrategyOwnershipForProfile((int) $profileId);
        }

        return $reverted;
    }

    /**
     * @param  Collection<int, Holding>  $stockHoldings
     */
    protected function assignIfSafe(
        Holding $holding,
        Collection $stockHoldings,
        ?int $inferredStrategyId,
    ): bool {
        if (! $holding->isUnmanaged()) {
            return false;
        }
        if ($inferredStrategyId === null || $inferredStrategyId <= 0) {
            return false;
        }

        $targetKey = Holding::ownerKeyFor($inferredStrategyId);
        $conflict = $stockHoldings->first(
            fn (Holding $other) => $other->id !== $holding->id
                && $other->owner_key === $targetKey
        );
        if ($conflict !== null) {
            return false;
        }

        $holding->forceFill([
            'strategy_id' => $inferredStrategyId,
            'owner_key' => $targetKey,
        ])->save();

        return true;
    }

    /**
     * Strategy S if every remaining contributing buy lot is rec-linked to S.
     * Null when there is no open qty, mixed owners, or any unmanaged contributing buy.
     */
    protected function inferredStrategyIdForOpenLots(int $profileId, int $stockId): ?int
    {
        $remaining = $this->contributingOpenBuyLots($profileId, $stockId);
        if ($remaining === []) {
            return null;
        }

        $strategyIds = [];
        foreach ($remaining as $lot) {
            if ($lot['strategy_id'] === null) {
                return null;
            }
            $strategyIds[$lot['strategy_id']] = true;
        }

        if (count($strategyIds) !== 1) {
            return null;
        }

        return (int) array_key_first($strategyIds);
    }

    /**
     * FIFO remaining buy lots that still contribute to open quantity.
     *
     * @return list<array{strategy_id: int|null, quantity: float}>
     */
    protected function contributingOpenBuyLots(int $profileId, int $stockId): array
    {
        $transactions = Transaction::query()
            ->where('profile_id', $profileId)
            ->where('stock_id', $stockId)
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        /** @var list<array{strategy_id: int|null, quantity: float}> $openBuys */
        $openBuys = [];

        foreach ($transactions as $transaction) {
            $qty = (float) $transaction->quantity;
            if ($qty <= 0.00001) {
                continue;
            }

            if ($transaction->type === 'buy') {
                $openBuys[] = [
                    'strategy_id' => $transaction->owningStrategyId(),
                    'quantity' => $qty,
                ];

                continue;
            }

            $toSell = $qty;
            while ($toSell > 0.00001 && $openBuys !== []) {
                $headQty = $openBuys[0]['quantity'];
                if ($headQty <= $toSell + 0.00001) {
                    $toSell -= $headQty;
                    array_shift($openBuys);
                } else {
                    $openBuys[0]['quantity'] = $headQty - $toSell;
                    $toSell = 0.0;
                }
            }
        }

        return $openBuys;
    }

    protected function stockHasRecommendationLinkedBuyForStrategy(
        int $profileId,
        int $stockId,
        int $strategyId,
    ): bool {
        $buys = Transaction::query()
            ->where('profile_id', $profileId)
            ->where('stock_id', $stockId)
            ->where('type', 'buy')
            ->whereNotNull('recommendation_id')
            ->orderBy('id')
            ->get();

        foreach ($buys as $buy) {
            if ((int) $buy->owningStrategyId() === $strategyId) {
                return true;
            }
        }

        return false;
    }
}
