<?php

namespace App\Engines\Recommendation;

use App\Engines\Recommendation\Allocation\CapitalAllocationStrategy;
use App\Engines\Recommendation\Allocation\ScorePriorityCapitalAllocator;
use App\Models\EvaluationRun;
use App\Models\PortfolioProfile;
use App\Models\RecommendationReview;
use App\Models\TradingOrder;
use App\Models\TradingRecommendation;
use App\Models\User;
use App\Services\CashManagementService;
use App\Services\PortfolioCalculationService;
use App\Services\PortfolioLoggerService;
use App\Services\StrategyConfigurationService;
use App\Services\StrategyEligibilityService;
use App\Services\Analytics\MarketAnalyticsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Recommendation Engine — generation + review lifecycle.
 *
 * Generation orchestration (TD-002) is delegated to {@see RecommendationGenerationPipeline}
 * (prepare → cancel stale → draft → rank → allocate capital → persist).
 *
 * Lifecycle (approve/reject/expire/…) remains on this class until TD-001.
 */
class RecommendationEngine
{
    protected CapitalAllocationStrategy $allocator;

    protected RecommendationGenerationPipeline $generationPipeline;

    public function __construct(
        protected PortfolioCalculationService $portfolio,
        protected PortfolioLoggerService $logger,
        protected CashManagementService $cash,
        protected StrategyConfigurationService $strategies,
        protected StrategyEligibilityService $eligibility,
        protected MarketAnalyticsService $marketAnalytics,
        ?CapitalAllocationStrategy $allocator = null,
        ?RecommendationGenerationPipeline $generationPipeline = null,
    ) {
        $this->allocator = $allocator ?? new ScorePriorityCapitalAllocator();
        $this->generationPipeline = $generationPipeline ?? new RecommendationGenerationPipeline(
            $portfolio,
            $logger,
            $cash,
            $strategies,
            $eligibility,
            $marketAnalytics,
            $this->allocator,
        );
    }

    /**
     * @return array{
     *     recommendations: list<TradingRecommendation>,
     *     batch_id: string,
     *     cash: array{cash_balance: float, reserved_cash: float, available_investable_cash: float},
     *     strategy: array{version_id: int, version: int, name: string}
     * }
     */
    public function generate(PortfolioProfile $profile, ?EvaluationRun $evaluationRun = null): array
    {
        return $this->generationPipeline->run($profile, $evaluationRun);
    }

    public function recordReview(
        PortfolioProfile $profile,
        User $user,
        TradingRecommendation $recommendation,
        string $decision,
        ?string $notes = null,
    ): TradingRecommendation {
        if ((int) $recommendation->profile_id !== (int) $profile->id) {
            throw ValidationException::withMessages([
                'recommendation' => ['Recommendation does not belong to this portfolio.'],
            ]);
        }

        $decision = TradingRecommendation::normalizeReviewDecision($decision);
        if (! in_array($decision, [
            TradingRecommendation::DECISION_APPROVED,
            TradingRecommendation::STATUS_REJECTED,
            TradingRecommendation::STATUS_DEFERRED,
        ], true)) {
            throw ValidationException::withMessages([
                'decision' => ['Decision must be approved (or accepted), rejected, or deferred.'],
            ]);
        }

        if ($recommendation->isInformational()) {
            throw ValidationException::withMessages([
                'decision' => ['HOLD_POSITION and WATCH are informational and do not require review.'],
            ]);
        }

        if (! $recommendation->isActionable()) {
            throw ValidationException::withMessages([
                'decision' => ['Only actionable portfolio decisions can be reviewed.'],
            ]);
        }

        if ($recommendation->status === TradingRecommendation::STATUS_EXECUTED) {
            throw ValidationException::withMessages([
                'decision' => ['Executed recommendations cannot be reviewed.'],
            ]);
        }

        if ($recommendation->status === TradingRecommendation::STATUS_REJECTED
            && $decision !== TradingRecommendation::STATUS_REJECTED) {
            throw ValidationException::withMessages([
                'decision' => ['Rejected recommendations cannot be changed this way. Use Reopen for review first.'],
            ]);
        }

        if (! $recommendation->canBeReviewed()
            && $recommendation->status !== TradingRecommendation::STATUS_REJECTED) {
            throw ValidationException::withMessages([
                'decision' => ['Recommendation is not open for review (status: '.$recommendation->status.').'],
            ]);
        }

        $status = $decision === TradingRecommendation::DECISION_APPROVED
            ? TradingRecommendation::STATUS_PENDING_EXECUTION
            : $decision;

        DB::transaction(function () use ($recommendation, $user, $decision, $notes, $status, $profile) {
            RecommendationReview::query()->create([
                'recommendation_id' => $recommendation->id,
                'user_id' => $user->id,
                'decision' => $decision,
                'notes' => $notes,
                'created_at' => now(),
            ]);

            $fill = ['status' => $status];
            if ($status === TradingRecommendation::STATUS_PENDING_EXECUTION) {
                $fill['approved_at'] = now();
                $fill['cancelled_at'] = null;
                $fill['cancellation_reason'] = null;
                $fill['executed_at'] = null;
                $fill['executed_transaction_id'] = null;
            }

            $recommendation->forceFill($fill)->save();

            if ($status === TradingRecommendation::STATUS_PENDING_EXECUTION
                && $recommendation->requiresCashReservation()) {
                $recommendation->setRelation('profile', $profile);
                $this->reserveForApproval($recommendation);
            }
        });

        $this->logger->log('daily', 'RecommendationEngine', 'info', 'Recommendation reviewed', [
            'recommendation_id' => $recommendation->id,
            'decision' => $decision,
            'status' => $status,
            'user_id' => $user->id,
        ]);

        return $recommendation->fresh(['security', 'evaluationResult', 'reviews']);
    }

    /**
     * Reserve suggested investable cash when approving a buy recommendation.
     */
    public function reserveForApproval(TradingRecommendation $r): void
    {
        if (! $r->requiresCashReservation()) {
            return;
        }

        $amount = $r->suggestedInvestmentAmount();
        if ($amount === null || $amount <= 0) {
            throw ValidationException::withMessages([
                'cash' => ['Cannot reserve cash: recommendation has no suggested investment amount.'],
            ]);
        }

        $amount = round((float) $amount, 4);
        $profile = $r->relationLoaded('profile') && $r->profile
            ? $r->profile
            : PortfolioProfile::query()->findOrFail($r->profile_id);

        $available = $this->cash->availableInvestableCash($profile);
        if ($amount > $available + 0.0001) {
            throw ValidationException::withMessages([
                'cash' => [
                    'Insufficient available investable cash to approve this recommendation '
                    .'(need '.$amount.', available '.$available.').',
                ],
            ]);
        }

        $r->forceFill([
            'reserved_amount' => $amount,
            'reservation_status' => TradingRecommendation::RESERVATION_RESERVED,
            'reserved_at' => now(),
        ])->save();
    }

    /**
     * Release cash reserved for a pending-execution buy recommendation.
     */
    public function releaseReservation(TradingRecommendation $r): void
    {
        $r->forceFill([
            'reservation_status' => TradingRecommendation::RESERVATION_RELEASED,
            'reserved_amount' => 0,
            'reserved_at' => null,
        ])->save();
    }

    /**
     * Convert a reservation into an actual executed outflow amount.
     */
    public function convertReservation(TradingRecommendation $r, float $executedAmount): void
    {
        $r->forceFill([
            'reservation_status' => TradingRecommendation::RESERVATION_CONVERTED,
            'executed_amount' => round($executedAmount, 4),
            'reserved_amount' => 0,
        ])->save();
    }

    /**
     * Cancel pending execution (approved recommendation will not be traded in-system).
     */
    public function cancelExecution(
        PortfolioProfile $profile,
        User $user,
        TradingRecommendation $recommendation,
        ?string $reason = null,
        ?string $notes = null,
    ): TradingRecommendation {
        if ((int) $recommendation->profile_id !== (int) $profile->id) {
            throw ValidationException::withMessages([
                'recommendation' => ['Recommendation does not belong to this portfolio.'],
            ]);
        }

        if (! $recommendation->canCancelExecution()) {
            throw ValidationException::withMessages([
                'recommendation' => ['Only recommendations pending execution can be cancelled.'],
            ]);
        }

        $reason = $reason ? strtolower(trim($reason)) : 'other';
        if (! in_array($reason, TradingRecommendation::CANCELLATION_REASONS, true)) {
            throw ValidationException::withMessages([
                'reason' => ['Invalid cancellation reason.'],
            ]);
        }

        $label = TradingRecommendation::CANCELLATION_REASON_LABELS[$reason] ?? $reason;
        $auditNotes = trim(($notes ? $notes.' — ' : '').$label);

        DB::transaction(function () use ($recommendation, $user, $reason, $auditNotes, $profile) {
            TradingOrder::query()
                ->where('profile_id', $profile->id)
                ->where('recommendation_id', $recommendation->id)
                ->where('status', TradingOrder::STATUS_PENDING)
                ->update([
                    'status' => TradingOrder::STATUS_CANCELLED,
                    'cancelled_at' => now(),
                ]);

            RecommendationReview::query()->create([
                'recommendation_id' => $recommendation->id,
                'user_id' => $user->id,
                'decision' => TradingRecommendation::DECISION_EXECUTION_CANCELLED,
                'notes' => $auditNotes !== '' ? $auditNotes : null,
                'created_at' => now(),
            ]);

            $recommendation->forceFill([
                'status' => TradingRecommendation::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ])->save();

            $this->releaseReservation($recommendation);
        });

        $this->logger->log('daily', 'RecommendationEngine', 'info', 'Recommendation execution cancelled', [
            'recommendation_id' => $recommendation->id,
            'reason' => $reason,
            'user_id' => $user->id,
        ]);

        return $recommendation->fresh(['security', 'evaluationResult', 'reviews']);
    }

    /**
     * Manual expire (automatic expiry reserved for later).
     */
    public function markExpired(
        PortfolioProfile $profile,
        User $user,
        TradingRecommendation $recommendation,
        ?string $notes = null,
    ): TradingRecommendation {
        if ((int) $recommendation->profile_id !== (int) $profile->id) {
            throw ValidationException::withMessages([
                'recommendation' => ['Recommendation does not belong to this portfolio.'],
            ]);
        }

        if (! $recommendation->isActionable()) {
            throw ValidationException::withMessages([
                'recommendation' => ['Only actionable recommendations can expire.'],
            ]);
        }

        if (in_array($recommendation->status, [
            TradingRecommendation::STATUS_EXECUTED,
            TradingRecommendation::STATUS_EXPIRED,
            TradingRecommendation::STATUS_ARCHIVED,
        ], true)) {
            throw ValidationException::withMessages([
                'recommendation' => ['Recommendation cannot be expired from status '.$recommendation->status.'.'],
            ]);
        }

        DB::transaction(function () use ($recommendation, $user, $notes, $profile) {
            TradingOrder::query()
                ->where('profile_id', $profile->id)
                ->where('recommendation_id', $recommendation->id)
                ->where('status', TradingOrder::STATUS_PENDING)
                ->update([
                    'status' => TradingOrder::STATUS_CANCELLED,
                    'cancelled_at' => now(),
                ]);

            RecommendationReview::query()->create([
                'recommendation_id' => $recommendation->id,
                'user_id' => $user->id,
                'decision' => TradingRecommendation::DECISION_EXPIRED,
                'notes' => $notes,
                'created_at' => now(),
            ]);

            $recommendation->forceFill([
                'status' => TradingRecommendation::STATUS_EXPIRED,
                'expires_at' => $recommendation->expires_at ?? now(),
            ])->save();

            $this->releaseReservation($recommendation);
        });

        return $recommendation->fresh(['security', 'evaluationResult', 'reviews']);
    }

    /**
     * Undo Approve / Reject / Defer / Cancelled → pending_review.
     * Executed recommendations: delete the linked transaction first (returns to pending_execution).
     */
    public function reopenForReview(
        PortfolioProfile $profile,
        User $user,
        TradingRecommendation $recommendation,
        ?string $notes = null,
    ): TradingRecommendation {
        if ((int) $recommendation->profile_id !== (int) $profile->id) {
            throw ValidationException::withMessages([
                'recommendation' => ['Recommendation does not belong to this portfolio.'],
            ]);
        }

        if (! $recommendation->isActionable()) {
            throw ValidationException::withMessages([
                'recommendation' => ['Only actionable recommendations can be reopened.'],
            ]);
        }

        if ($recommendation->status === TradingRecommendation::STATUS_EXECUTED) {
            throw ValidationException::withMessages([
                'recommendation' => ['Executed recommendations cannot be reopened here. Delete the linked transaction on the Transactions page first.'],
            ]);
        }

        if (! $recommendation->canReopenForReview()) {
            throw ValidationException::withMessages([
                'recommendation' => ['Recommendation cannot be reopened (status: '.$recommendation->status.').'],
            ]);
        }

        $fromStatus = $recommendation->status;

        DB::transaction(function () use ($recommendation, $user, $notes, $fromStatus, $profile) {
            TradingOrder::query()
                ->where('profile_id', $profile->id)
                ->where('recommendation_id', $recommendation->id)
                ->where('status', TradingOrder::STATUS_PENDING)
                ->update([
                    'status' => TradingOrder::STATUS_CANCELLED,
                    'cancelled_at' => now(),
                ]);

            RecommendationReview::query()->create([
                'recommendation_id' => $recommendation->id,
                'user_id' => $user->id,
                'decision' => TradingRecommendation::DECISION_REOPENED,
                'notes' => $notes ?? 'Reopened from '.$fromStatus.' to pending_review',
                'created_at' => now(),
            ]);

            $recommendation->forceFill([
                'status' => TradingRecommendation::STATUS_PENDING_REVIEW,
                'approved_at' => null,
                'cancelled_at' => null,
                'cancellation_reason' => null,
                'executed_at' => null,
                'executed_transaction_id' => null,
            ])->save();

            $this->releaseReservation($recommendation);
        });

        $this->logger->log('daily', 'RecommendationEngine', 'info', 'Recommendation reopened for review', [
            'recommendation_id' => $recommendation->id,
            'from_status' => $fromStatus,
            'user_id' => $user->id,
        ]);

        return $recommendation->fresh(['security', 'evaluationResult', 'reviews', 'orders']);
    }

    public function expireStale(PortfolioProfile $profile): int
    {
        return TradingRecommendation::query()
            ->where('profile_id', $profile->id)
            ->whereIn('status', [
                TradingRecommendation::STATUS_PENDING_REVIEW,
                TradingRecommendation::STATUS_DEFERRED,
                TradingRecommendation::STATUS_PUBLISHED,
                'active',
            ])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->update(['status' => TradingRecommendation::STATUS_EXPIRED]);
    }

    /**
     * @param  list<string>|null  $statuses
     * @param  list<string>|null  $types
     * @return list<TradingRecommendation>
     */
    public function listForProfile(
        PortfolioProfile $profile,
        ?array $statuses = null,
        int $limit = 100,
        ?array $types = null,
    ): array {
        $this->expireStale($profile);

        $query = TradingRecommendation::query()
            ->with(['security', 'evaluationResult', 'reviews'])
            ->where('profile_id', $profile->id);

        if ($statuses !== null && $statuses !== []) {
            $query->whereIn('status', $statuses);
        }

        if ($types !== null && $types !== []) {
            $upper = array_map('strtoupper', $types);
            $query->where(function ($q) use ($upper) {
                foreach ($upper as $i => $t) {
                    $method = $i === 0 ? 'whereRaw' : 'orWhereRaw';
                    $q->{$method}('UPPER(recommendation_type) = ?', [$t]);
                }
            });
        }

        return $query
            ->orderByDesc('priority')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->all();
    }

    /**
     * @return list<TradingRecommendation>
     */
    public function listOpenForReview(PortfolioProfile $profile): array
    {
        return $this->listForProfile(
            $profile,
            [
                TradingRecommendation::STATUS_PENDING_REVIEW,
                TradingRecommendation::STATUS_DEFERRED,
            ],
            100,
            [...TradingRecommendation::ACTIONABLE_ACTIONS, 'BUY', 'SELL'],
        );
    }

    /**
     * Approved recommendations awaiting a ledger fill (Transactions → Pending Execution).
     *
     * @return list<TradingRecommendation>
     */
    public function listPendingExecution(PortfolioProfile $profile, int $limit = 100): array
    {
        return $this->listForProfile(
            $profile,
            [
                TradingRecommendation::STATUS_PENDING_EXECUTION,
                TradingRecommendation::STATUS_ACCEPTED,
            ],
            $limit,
            [...TradingRecommendation::ACTIONABLE_ACTIONS, 'BUY', 'SELL'],
        );
    }

    /** @deprecated use listOpenForReview */
    public function listActive(PortfolioProfile $profile): array
    {
        return $this->listOpenForReview($profile);
    }

    public function findForProfile(PortfolioProfile $profile, int $id): ?TradingRecommendation
    {
        return TradingRecommendation::query()
            ->with([
                'security',
                'evaluationResult.candidate',
                'reviews.user',
                'orders',
                'executedTransaction.stock',
            ])
            ->where('profile_id', $profile->id)
            ->where('id', $id)
            ->first();
    }

    /**
     * @return list<TradingRecommendation>
     */
    public function history(PortfolioProfile $profile, int $limit = 50): array
    {
        return $this->listForProfile($profile, null, $limit);
    }

    /**
     * @return list<RecommendationReview>
     */
    public function reviewHistory(TradingRecommendation $recommendation): array
    {
        return RecommendationReview::query()
            ->with('user')
            ->where('recommendation_id', $recommendation->id)
            ->orderByDesc('id')
            ->get()
            ->all();
    }
}
