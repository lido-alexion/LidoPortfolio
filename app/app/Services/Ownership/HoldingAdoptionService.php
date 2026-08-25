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
 * Does not invent cost-basis merge when the destination already owns the stock.
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
        if ($existingDest !== null) {
            throw ValidationException::withMessages([
                'strategy_id' => [
                    'Adoption into a strategy that already owns this stock is not available. '
                    .'Cost-basis and multi-lot merge rules are unspecified (V3 §10.4 / OD-15).',
                ],
            ]);
        }

        return DB::transaction(function () use ($profile, $holding, $strategy, $user, $destKey) {
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

            $invested = round(max(0.0, (float) $adopted->invested_amount), 4);
            $adopted->target_amount = $invested;
            $adopted->filled_amount = $invested;
            $adopted->updated_at = now();
            $adopted->save();
            $holding = $adopted;

            $adoption = $this->recordAudit(
                $profile,
                $holding,
                $strategy,
                $user,
                idempotent: false,
                recommendationId: (int) $rec->id,
            );

            Log::info('holding.adopted', [
                'profile_id' => $profile->id,
                'holding_id' => $holding->id,
                'stock_id' => $holding->stock_id,
                'to_strategy_id' => $strategy->id,
                'adoption_id' => $adoption->id,
            ]);

            return ['holding' => $holding, 'adoption' => $adoption, 'idempotent' => false];
        });
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
                'merge_blocked_when_dest_owns_stock' => true,
            ],
        ]);
    }
}
