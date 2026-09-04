<?php

namespace App\Services\Execution;

use App\Engines\Recommendation\RecommendationLifecycleService;
use App\Models\TradingRecommendation;
use App\Models\TradingStrategyVersion;
use Illuminate\Support\Facades\DB;

final class RecommendationSupersessionService
{
    public function __construct(protected RecommendationLifecycleService $lifecycle) {}

    public function supersedeMateriallyDifferentPriorIntent(TradingRecommendation $replacement): int
    {
        $strategyId = $replacement->owningStrategyId();
        if ($strategyId === null || $replacement->target_amount === null) {
            return 0;
        }
        $versionIds = TradingStrategyVersion::query()->where('strategy_id', $strategyId)->pluck('id');
        $prior = TradingRecommendation::query()
            ->where('profile_id', $replacement->profile_id)
            ->where('security_id', $replacement->security_id)
            ->whereIn('strategy_version_id', $versionIds)
            ->where('id', '!=', $replacement->id)
            ->whereNotNull('execution_anchor_date')
            ->whereIn('status', [
                TradingRecommendation::STATUS_PENDING_REVIEW,
                TradingRecommendation::STATUS_DEFERRED,
                TradingRecommendation::STATUS_PENDING_EXECUTION,
                TradingRecommendation::STATUS_ACCEPTED,
            ])
            ->where(function ($query) use ($replacement): void {
                $query->where('recommendation_type', '!=', $replacement->recommendation_type)
                    ->orWhereNull('target_amount')
                    ->orWhere('target_amount', '<', (float) $replacement->target_amount - 0.0001)
                    ->orWhere('target_amount', '>', (float) $replacement->target_amount + 0.0001);
            })
            ->pluck('id');

        $count = 0;
        foreach ($prior as $id) {
            $count += DB::transaction(function () use ($id, $replacement): int {
                $row = TradingRecommendation::query()->lockForUpdate()->find($id);
                if (! $row || $row->isImmutable()) {
                    return 0;
                }
                $row->forceFill([
                    'status' => TradingRecommendation::STATUS_SUPERSEDED,
                    'superseded_at' => now(),
                    'superseded_by_id' => $replacement->id,
                ])->save();
                $this->lifecycle->releaseReservation($row);

                return 1;
            });
        }

        return $count;
    }
}
