<?php

namespace App\Services\Execution;

use App\Models\TradingOrder;
use App\Models\TradingRecommendation;
use App\Support\TradingCalendar;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** Frozen FEAT-039 two-session lifetime derivation. */
class RecommendationExecutionLifetime
{
    public function __construct(protected RecommendationExecutionNotificationService $notifications) {}

    public const TIMEZONE = 'Asia/Kolkata';

    /**
     * @return array{anchor_class:string,anchor_date:string,first_eligible_date:string,second_eligible_date:string,expires_at:Carbon}
     */
    public function derive(Carbon $generatedAt, string $cutoff): array
    {
        $generated = $generatedAt->copy()->timezone(self::TIMEZONE);
        $day = $generated->copy()->startOfDay();
        $cutoffAt = $day->copy()->setTimeFromTimeString($cutoff);
        $isDayZero = TradingCalendar::isEquitySessionDate($day) && $generated->lt($cutoffAt);

        $first = $isDayZero
            ? $day->copy()
            : TradingCalendar::nextSessionOnOrAfter($day->copy()->addDay());
        $second = TradingCalendar::addSessions($first, 1);

        return [
            'anchor_class' => $isDayZero ? 'day_0' : 'day_1',
            'anchor_date' => $day->toDateString(),
            'first_eligible_date' => $first->toDateString(),
            'second_eligible_date' => $second->toDateString(),
            'expires_at' => $second->copy()->setTimeFromTimeString($cutoff),
        ];
    }

    public function initialize(TradingRecommendation $recommendation): TradingRecommendation
    {
        if ($recommendation->execution_anchor_date !== null) {
            return $recommendation;
        }

        $plan = is_array($recommendation->execution_plan) ? $recommendation->execution_plan : [];
        $capital = $recommendation->capitalAllocationMeta() ?? [];
        $directionAmount = max(0.0, (float) ($plan['this_cycle_amount']
            ?? $capital['desired_amount']
            ?? $recommendation->suggestedInvestmentAmount()
            ?? 0));
        $targetAmount = in_array($recommendation->recommendation_type, [
            TradingRecommendation::ACTION_EXIT_POSITION,
        ], true) ? 0.0 : max(0.0, (float) ($plan['position_target_amount'] ?? $directionAmount));
        $capitalResolved = max(0.0, (float) ($capital['allocated_amount']
            ?? $recommendation->suggestedInvestmentAmount()
            ?? 0));
        $dates = $this->derive(
            $recommendation->generated_at?->copy() ?? $recommendation->created_at?->copy() ?? now(),
            (string) config('trading_os.execution.cutoff_time', '15:30'),
        );

        $recommendation->forceFill([
            'target_amount' => $targetAmount,
            'capital_resolved_amount' => $capitalResolved,
            'remaining_target_amount' => $directionAmount,
            'original_display_quantity' => $recommendation->suggestedQuantity(),
            'execution_anchor_date' => $dates['anchor_date'],
            'execution_anchor_class' => $dates['anchor_class'],
            'first_eligible_execution_date' => $dates['first_eligible_date'],
            'second_eligible_execution_date' => $dates['second_eligible_date'],
            'execution_expires_at' => $dates['expires_at'],
        ])->save();

        return $recommendation->fresh();
    }

    public function isExecutionOpportunity(TradingRecommendation $recommendation, ?Carbon $at = null): bool
    {
        // Pre-FEAT-039 rows retain their prior behavior during migration.
        if ($recommendation->first_eligible_execution_date === null
            || $recommendation->second_eligible_execution_date === null) {
            return true;
        }

        $now = ($at ?? now())->copy()->timezone(self::TIMEZONE);
        $date = $now->toDateString();
        if (! in_array($date, [
            $recommendation->first_eligible_execution_date->toDateString(),
            $recommendation->second_eligible_execution_date->toDateString(),
        ], true) || ! TradingCalendar::isEquitySessionDate($now)) {
            return false;
        }

        $start = $now->copy()->startOfDay()->setTimeFromTimeString((string) config('trading_os.execution.window_start', '09:15'));
        $cutoff = $now->copy()->startOfDay()->setTimeFromTimeString((string) config('trading_os.execution.cutoff_time', '15:30'));

        return $now->gte($start) && $now->lt($cutoff);
    }

    /** Expire only unresolved intent without an in-flight broker order. */
    public function expireDue(?Carbon $at = null): int
    {
        $at ??= now();
        $ids = TradingRecommendation::query()
            ->whereIn('status', [
                TradingRecommendation::STATUS_PENDING_REVIEW,
                TradingRecommendation::STATUS_DEFERRED,
                TradingRecommendation::STATUS_PENDING_EXECUTION,
                TradingRecommendation::STATUS_ACCEPTED,
            ])
            ->whereNotNull('execution_expires_at')
            ->where('execution_expires_at', '<=', $at)
            ->whereDoesntHave('orders', fn ($query) => $query->whereIn('broker_status', TradingOrder::IN_FLIGHT_BROKER_STATUSES))
            ->pluck('id');

        $expired = 0;
        foreach ($ids as $id) {
            $didExpire = DB::transaction(function () use ($id, $at): int {
                $row = TradingRecommendation::query()->lockForUpdate()->find($id);
                if (! $row || ! in_array($row->status, [
                    TradingRecommendation::STATUS_PENDING_REVIEW,
                    TradingRecommendation::STATUS_DEFERRED,
                    TradingRecommendation::STATUS_PENDING_EXECUTION,
                    TradingRecommendation::STATUS_ACCEPTED,
                ], true) || $row->orders()->whereIn('broker_status', TradingOrder::IN_FLIGHT_BROKER_STATUSES)->exists()) {
                    return 0;
                }
                $row->forceFill([
                    'status' => TradingRecommendation::STATUS_EXPIRED,
                    'expires_at' => $at,
                ])->save();

                return 1;
            });
            $expired += $didExpire;
            if ($didExpire > 0) {
                $row = TradingRecommendation::query()->find($id);
                if ($row?->status === TradingRecommendation::STATUS_EXPIRED) {
                    $this->notifications->notifyExpired($row);
                }
            }
        }

        return $expired;
    }
}
