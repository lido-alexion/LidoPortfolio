<?php

namespace App\Services\Entry;

use App\Models\Holding;
use App\Models\PortfolioProfile;
use App\Models\Stock;
use App\Models\TradingRecommendation;
use App\Models\TradingStrategy;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * OD-12 position target/filled persistence on strategy-owned holdings + OD-11 cooldown lookup.
 */
final class StrategyPositionTargetService
{
    public function __construct(
        protected BuyCooldownEvaluator $cooldown,
    ) {}

    public function findHolding(PortfolioProfile $profile, int $stockId, int $strategyId): ?Holding
    {
        return Holding::query()
            ->where('profile_id', $profile->id)
            ->where('stock_id', $stockId)
            ->where('owner_key', Holding::ownerKeyFor($strategyId))
            ->first();
    }

    /**
     * Persist / update OD-12 target amount without changing filled/qty from fills.
     * Creates a zero-qty strategy holding when the position does not exist yet.
     */
    public function upsertTargetAmount(
        PortfolioProfile $profile,
        Stock $stock,
        TradingStrategy $strategy,
        float $targetAmount,
    ): Holding {
        $ownerKey = Holding::ownerKeyFor((int) $strategy->id);
        $holding = Holding::query()->firstOrNew([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'owner_key' => $ownerKey,
        ]);

        if (! $holding->exists) {
            $holding->strategy_id = (int) $strategy->id;
            $holding->quantity = 0;
            $holding->avg_buy_price = 0;
            $holding->invested_amount = 0;
            $holding->total_fees = 0;
            $holding->realized_profit = 0;
            $holding->filled_amount = 0;
        } else {
            $holding->strategy_id = (int) $strategy->id;
        }

        $holding->target_amount = round(max(0.0, $targetAmount), 4);
        $holding->updated_at = now();
        $holding->save();

        return $holding;
    }

    /**
     * Sync filled_amount from actual invested cost of the open lot (not from target).
     */
    public function syncFilledFromInvested(Holding $holding): void
    {
        $qty = (float) $holding->quantity;
        $invested = (float) $holding->invested_amount;
        $holding->filled_amount = $qty > 0.00001 ? round(max(0.0, $invested), 4) : 0.0;
        // Do not clear target_amount here — OD-12 target survives partial fills.
        $holding->save();
    }

    public function filledAmount(?Holding $holding): float
    {
        if ($holding === null) {
            return 0.0;
        }

        if ($holding->filled_amount !== null) {
            return max(0.0, (float) $holding->filled_amount);
        }

        // Fallback: invested cost of open lot.
        return (float) $holding->quantity > 0.00001
            ? max(0.0, (float) $holding->invested_amount)
            : 0.0;
    }

    public function targetAmount(?Holding $holding): float
    {
        if ($holding === null || $holding->target_amount === null) {
            return 0.0;
        }

        return max(0.0, (float) $holding->target_amount);
    }

    public function hasOpenPosition(?Holding $holding): bool
    {
        return $holding !== null && (float) $holding->quantity > 0.00001;
    }

    /**
     * Latest BUY opportunity calendar date for (stock, strategy), including cancelled.
     * Generation starts OD-11; fills do not reset it.
     */
    public function lastBuyOpportunityDate(
        PortfolioProfile $profile,
        int $stockId,
        int $strategyId,
    ): ?CarbonInterface {
        $row = TradingRecommendation::query()
            ->forProfile($profile)
            ->where('security_id', $stockId)
            ->whereIn('recommendation_type', [
                TradingRecommendation::ACTION_OPEN_POSITION,
                TradingRecommendation::ACTION_INCREASE_POSITION,
            ])
            ->whereHas('strategyVersion', fn ($q) => $q->where('strategy_id', $strategyId))
            ->whereNotNull('generated_at')
            ->orderByDesc('generated_at')
            ->orderByDesc('id')
            ->first(['generated_at']);

        return $row?->generated_at;
    }

    public function isBuyCooldownActive(
        PortfolioProfile $profile,
        int $stockId,
        int $strategyId,
        ?CarbonInterface $asOf = null,
    ): bool {
        return $this->cooldown->isActive(
            $this->lastBuyOpportunityDate($profile, $stockId, $strategyId),
            $asOf ?? Carbon::now(),
        );
    }
}
