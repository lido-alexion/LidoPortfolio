<?php

namespace App\Engines\Recommendation;

use App\Models\EvaluationRun;
use App\Models\PortfolioProfile;
use App\Models\RecommendationReview;
use App\Models\TradingRecommendation;
use App\Models\User;

/**
 * Recommendation Engine — thin façade over generation + lifecycle.
 *
 * Generation orchestration (TD-002) is delegated to {@see RecommendationGenerationPipeline}
 * (prepare → cancel stale → draft → rank → allocate capital → persist).
 *
 * Lifecycle (approve/reject/expire/reservations/queries) is delegated to
 * {@see RecommendationLifecycleService} (TD-001). This class only forwards calls and
 * preserves the previous public method signatures so callers (TradingOsController,
 * ExecutionEngine, DailyDecisionPipeline) require no changes.
 */
class RecommendationEngine
{
    public function __construct(
        protected RecommendationGenerationPipeline $generationPipeline,
        protected RecommendationLifecycleService $lifecycleService,
    ) {}

    /**
     * @return array{
     *     recommendations: list<TradingRecommendation>,
     *     batch_id: string,
     *     cash: array{cash_balance: float, reserved_cash: float, available_investable_cash: float},
     *     strategy: array{version_id: int, version: int, name: string}
     * }
     * @param  list<int>|null  $onlyStrategyIds
     */
    public function generate(
        PortfolioProfile $profile,
        ?EvaluationRun $evaluationRun = null,
        ?array $onlyStrategyIds = null,
    ): array {
        return $this->generationPipeline->run($profile, $evaluationRun, $onlyStrategyIds);
    }

    public function recordReview(
        PortfolioProfile $profile,
        User $user,
        TradingRecommendation $recommendation,
        string $decision,
        ?string $notes = null,
    ): TradingRecommendation {
        return $this->lifecycleService->recordReview($profile, $user, $recommendation, $decision, $notes);
    }

    /**
     * Reserve suggested investable cash when approving a buy recommendation.
     */
    public function reserveForApproval(TradingRecommendation $r): void
    {
        $this->lifecycleService->reserveForApproval($r);
    }

    /**
     * Release cash reserved for a pending-execution buy recommendation.
     */
    public function releaseReservation(TradingRecommendation $r): void
    {
        $this->lifecycleService->releaseReservation($r);
    }

    /**
     * Convert a reservation into an actual executed outflow amount.
     */
    public function convertReservation(TradingRecommendation $r, float $executedAmount): void
    {
        $this->lifecycleService->convertReservation($r, $executedAmount);
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
        return $this->lifecycleService->cancelExecution($profile, $user, $recommendation, $reason, $notes);
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
        return $this->lifecycleService->markExpired($profile, $user, $recommendation, $notes);
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
        return $this->lifecycleService->reopenForReview($profile, $user, $recommendation, $notes);
    }

    public function expireStale(PortfolioProfile $profile): int
    {
        return $this->lifecycleService->expireStale($profile);
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
        return $this->lifecycleService->listForProfile($profile, $statuses, $limit, $types);
    }

    /**
     * @return list<TradingRecommendation>
     */
    public function listOpenForReview(PortfolioProfile $profile): array
    {
        return $this->lifecycleService->listOpenForReview($profile);
    }

    /**
     * Approved recommendations awaiting a ledger fill (Transactions → Pending Execution).
     *
     * @return list<TradingRecommendation>
     */
    public function listPendingExecution(PortfolioProfile $profile, int $limit = 100): array
    {
        return $this->lifecycleService->listPendingExecution($profile, $limit);
    }

    /** @deprecated use listOpenForReview */
    public function listActive(PortfolioProfile $profile): array
    {
        return $this->lifecycleService->listActive($profile);
    }

    public function findForProfile(PortfolioProfile $profile, int $id): ?TradingRecommendation
    {
        return $this->lifecycleService->findForProfile($profile, $id);
    }

    /**
     * @return list<TradingRecommendation>
     */
    public function history(PortfolioProfile $profile, int $limit = 50): array
    {
        return $this->lifecycleService->history($profile, $limit);
    }

    /**
     * @return list<RecommendationReview>
     */
    public function reviewHistory(TradingRecommendation $recommendation): array
    {
        return $this->lifecycleService->reviewHistory($recommendation);
    }
}
