<?php

namespace App\Services\Execution;

use App\Engines\Notification\NotificationEngine;
use App\Models\PortfolioProfile;
use App\Models\TradingRecommendation;
use App\Services\Broker\BrokerConnectionService;
use Carbon\Carbon;

final class RecommendationExecutionNotificationService
{
    public function __construct(
        protected NotificationEngine $notifications,
        protected BrokerConnectionService $brokerConnections,
    ) {}

    public function sendApproachingExpiry(?Carbon $at = null): int
    {
        $now = ($at ?? now())->copy()->timezone(RecommendationExecutionLifetime::TIMEZONE);
        $cutoff = $now->copy()->startOfDay()->setTimeFromTimeString((string) config('trading_os.execution.cutoff_time', '15:30'));
        if ($now->lt($cutoff)) {
            return 0;
        }

        $sent = 0;
        $rows = TradingRecommendation::query()
            ->with(['profile.user', 'security'])
            ->whereDate('first_eligible_execution_date', $now->toDateString())
            ->where('remaining_target_amount', '>', 0.0001)
            ->whereIn('status', [
                TradingRecommendation::STATUS_PENDING_REVIEW,
                TradingRecommendation::STATUS_DEFERRED,
                TradingRecommendation::STATUS_PENDING_EXECUTION,
                TradingRecommendation::STATUS_ACCEPTED,
            ])
            ->get();
        foreach ($rows as $row) {
            $profile = $row->profile;
            $user = $profile?->user;
            if (! $profile || ! $user || ! $this->isInvestorActionable($row, $profile)) {
                continue;
            }
            $expiry = optional($row->execution_expires_at)?->timezone(RecommendationExecutionLifetime::TIMEZONE)->format('d M Y H:i T') ?? 'Day #2 cutoff';
            $result = $this->notifications->notifyDomain(
                $profile,
                'recommendation_approaching_expiry',
                'feat039-approaching-expiry-'.$row->id,
                "StoX Recommendation #{$row->id} still needs attention and expires at {$expiry}. Remaining target: ₹".number_format((float) $row->remaining_target_amount, 2),
                ['remaining_target_amount' => (float) $row->remaining_target_amount, 'expires_at' => optional($row->execution_expires_at)?->toIso8601String()],
                $row->id,
            );
            $sent += $result ? 1 : 0;
        }

        return $sent;
    }

    public function notifyExpired(TradingRecommendation $row): void
    {
        $row->loadMissing(['profile', 'security']);
        if (! $row->profile) {
            return;
        }
        $this->notifications->notifyDomain(
            $row->profile,
            'recommendation_expired',
            'feat039-expired-'.$row->id,
            "StoX Recommendation #{$row->id} expired with ₹".number_format((float) $row->remaining_target_amount, 2).' of its target unfulfilled.',
            [
                'remaining_target_amount' => (float) $row->remaining_target_amount,
                'internal_executed_amount' => (float) $row->internal_executed_amount,
                'external_executed_amount' => (float) $row->external_executed_amount,
            ],
            $row->id,
        );
    }

    protected function isInvestorActionable(TradingRecommendation $row, PortfolioProfile $profile): bool
    {
        if (in_array($row->status, [TradingRecommendation::STATUS_PENDING_REVIEW, TradingRecommendation::STATUS_DEFERRED], true)) {
            return true;
        }
        $user = $profile->user;

        return $user !== null && ! ($this->brokerConnections->status($user)['usable'] ?? false);
    }
}
