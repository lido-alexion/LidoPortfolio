<?php

namespace App\Services\Strategy;

use App\Models\Holding;
use App\Models\TradingStrategy;

/**
 * One-time V3 identity backfill for existing V1 unique (profile, stock) holdings.
 *
 * Safely infers owner only when the portfolio has exactly one strategy.
 * Does not split lots, rewrite quantities/cost, or touch cash/recommendations.
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
        $strategyIds = TradingStrategy::query()
            ->where('profile_id', $profileId)
            ->orderBy('id')
            ->pluck('id');

        if ($strategyIds->count() !== 1) {
            return 0;
        }

        $strategyId = (int) $strategyIds->first();

        return Holding::query()
            ->where('profile_id', $profileId)
            ->where(function ($q) {
                $q->whereNull('strategy_id')
                    ->orWhere('owner_key', Holding::OWNER_UNMANAGED);
            })
            ->update([
                'strategy_id' => $strategyId,
                'owner_key' => Holding::ownerKeyFor($strategyId),
            ]);
    }
}
