<?php

namespace App\Services\Ownership;

use App\Models\Holding;
use App\Models\HoldingAdoption;
use App\Models\PortfolioProfile;
use App\Models\TradingRecommendation;
use App\Models\TradingStrategy;
use App\Models\Transaction;
use App\Models\User;
use App\Services\HoldingsCalculationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * V3 §10.4 explicit unmanaged → strategy adoption.
 *
 * Same-stock merge (V4-SPEC-001): weighted-average cost, one Strategy position,
 * destination target_amount and ownership episode preserved (OD-12 / OD-15).
 * Attribution uses HOLD_POSITION (not OPEN/INCREASE) so OD-11 BUY cooldown is unchanged.
 */
final class HoldingAdoptionService
{
    public function __construct(
        protected HoldingsCalculationService $holdings,
    ) {}

    /**
     * @return array{holding: Holding, adoption: HoldingAdoption, idempotent: bool}
     */
    public function adopt(
        PortfolioProfile $profile,
        Holding $holding,
        int $strategyId,
        ?User $user = null,
    ): array {
        if ((int) $holding->profile_id !== (int) $profile->id) {
            throw ValidationException::withMessages([
                'holding_id' => ['Holding must belong to the active portfolio.'],
            ]);
        }

        $strategy = TradingStrategy::query()
            ->where('profile_id', $profile->id)
            ->where('id', $strategyId)
            ->first();
        if ($strategy === null) {
            throw ValidationException::withMessages([
                'strategy_id' => ['Strategy not found in this portfolio.'],
            ]);
        }

        $destKey = Holding::ownerKeyFor((int) $strategy->id);

        if (! $holding->isUnmanaged() && (int) $holding->strategy_id === (int) $strategy->id) {
            $adoption = $this->recordAudit($profile, $holding, $strategy, $user, idempotent: true);

            return ['holding' => $holding->fresh(), 'adoption' => $adoption, 'idempotent' => true];
        }

        if (! $holding->isUnmanaged()) {
            throw ValidationException::withMessages([
                'holding_id' => ['Only unmanaged holdings can be adopted into a strategy.'],
            ]);
        }

        if ((float) $holding->quantity <= 0.00001) {
            throw ValidationException::withMessages([
                'holding_id' => ['Cannot adopt a holding with no open quantity.'],
            ]);
        }

        $existingDest = Holding::query()
            ->where('profile_id', $profile->id)
            ->where('stock_id', $holding->stock_id)
            ->where('owner_key', $destKey)
            ->where('id', '!=', $holding->id)
            ->where('quantity', '>', 0)
            ->first();
        $isMerge = $existingDest !== null;
        $preservedTarget = $isMerge ? $existingDest->target_amount : null;

        $result = DB::transaction(function () use (
            $profile,
            $holding,
            $strategy,
            $user,
            $destKey,
            $isMerge,
            $preservedTarget,
        ) {
            $stock = $holding->stock ?? $holding->stock()->firstOrFail();
            $rec = $this->ensureAttributionRecommendation($profile, $holding, $strategy);
            $this->attributeUnmanagedBuys($profile, $holding, (int) $rec->id);

            $this->holdings->recalculateOwnerLotsForProfileStock($profile, $stock);
            $adopted = Holding::query()
                ->where('profile_id', $profile->id)
                ->where('stock_id', $stock->id)
                ->where('owner_key', $destKey)
                ->first();
            if ($adopted === null) {
                throw ValidationException::withMessages([
                    'holding_id' => ['Adoption did not produce a strategy-owned holding from the ledger.'],
                ]);
            }

            if ($isMerge) {
                $qty = (float) $adopted->quantity;
                if ($qty > 0.00001) {
                    $adopted->avg_buy_price = $this->roundAverageCost((float) $adopted->invested_amount / $qty);
                }
                $adopted->target_amount = $preservedTarget;
            } else {
                $invested = round(max(0.0, (float) $adopted->invested_amount), 4);
                $adopted->target_amount = $invested;
                $adopted->filled_amount = $invested;
            }

            $adopted->updated_at = now();
            $adopted->save();

            $adoption = $this->recordAudit(
                $profile,
                $adopted,
                $strategy,
                $user,
                idempotent: false,
                recommendationId: (int) $rec->id,
                sameStockMerge: $isMerge,
            );

            Log::info('holding.adopted', [
                'profile_id' => $profile->id,
                'holding_id' => $adopted->id,
                'stock_id' => $adopted->stock_id,
                'to_strategy_id' => $strategy->id,
                'adoption_id' => $adoption->id,
                'same_stock_merge' => $isMerge,
            ]);

            return ['holding' => $adopted, 'adoption' => $adoption, 'idempotent' => false];
        });

        try {
            app(\App\Services\Protection\PositionProtectionService::class)
                ->afterAdoption($profile, $result['holding']);
        } catch (\Throwable) {
            // Adoption must stay applied even if GTT sync fails.
        }

        return $result;
    }

    /**
     * Final average cost only: 2 decimal places, half-up. Inputs are not rounded first.
     */
    public function roundAverageCost(float $value): float
    {
        return round($value, 2, PHP_ROUND_HALF_UP);
    }

    protected function ensureAttributionRecommendation(
        PortfolioProfile $profile,
        Holding $holding,
        TradingStrategy $strategy,
    ): TradingRecommendation {
        $versionId = $strategy->active_version_id;
        if ($versionId === null) {
            throw ValidationException::withMessages([
                'strategy_id' => ['Destination strategy has no active version.'],
            ]);
        }

        return TradingRecommendation::query()->create([
            'profile_id' => $profile->id,
            'security_id' => $holding->stock_id,
            'strategy_version_id' => $versionId,
            'recommendation_type' => TradingRecommendation::ACTION_HOLD_POSITION,
            'status' => TradingRecommendation::STATUS_EXECUTED,
            'priority' => 0,
            'strategy_score' => 0,
            'confidence' => 0,
            'risk_level' => TradingRecommendation::RISK_MEDIUM,
            'generated_at' => now(),
            'evidence' => [
                'holding_adoption' => true,
                'holding_id' => $holding->id,
            ],
        ]);
    }

    protected function attributeUnmanagedBuys(
        PortfolioProfile $profile,
        Holding $holding,
        int $recommendationId,
    ): void {
        Transaction::query()
            ->where('profile_id', $profile->id)
            ->where('stock_id', $holding->stock_id)
            ->where('type', 'buy')
            ->whereNull('recommendation_id')
            ->update(['recommendation_id' => $recommendationId]);
    }

    protected function recordAudit(
        PortfolioProfile $profile,
        Holding $holding,
        TradingStrategy $strategy,
        ?User $user,
        bool $idempotent,
        ?int $recommendationId = null,
        bool $sameStockMerge = false,
    ): HoldingAdoption {
        return HoldingAdoption::query()->create([
            'profile_id' => $profile->id,
            'holding_id' => $holding->id,
            'stock_id' => $holding->stock_id,
            'from_owner_key' => $idempotent
                ? Holding::ownerKeyFor((int) $strategy->id)
                : Holding::OWNER_UNMANAGED,
            'to_strategy_id' => $strategy->id,
            'to_owner_key' => Holding::ownerKeyFor((int) $strategy->id),
            'user_id' => $user?->id,
            'attribution_recommendation_id' => $recommendationId,
            'target_amount' => $holding->target_amount,
            'idempotent' => $idempotent,
            'evidence_json' => [
                'od15_entry_history_preserved' => true,
                'same_stock_merge' => $sameStockMerge,
            ],
        ]);
    }
}
