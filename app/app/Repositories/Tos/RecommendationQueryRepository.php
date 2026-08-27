<?php

namespace App\Repositories\Tos;

use App\Models\PortfolioProfile;
use App\Models\RecommendationReview;
use App\Models\TradingRecommendation;
use App\Support\TradingOsPagination;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * V4-FEAT-032 — recommendation list/find queries.
 * expireStale, review decisions, markExecuted, and reservations stay in RecommendationLifecycleService.
 */
class RecommendationQueryRepository
{
    /**
     * @param  list<string>|null  $statuses
     * @param  list<string>|null  $types
     * @return Builder<TradingRecommendation>
     */
    public function profileListQuery(
        PortfolioProfile $profile,
        ?array $statuses = null,
        ?array $types = null,
    ): Builder {
        $query = TradingRecommendation::query()
            ->with(['security', 'evaluationResult', 'reviews'])
            ->forProfile($profile);

        if ($statuses !== null && $statuses !== []) {
            $query->whereIn('status', $statuses);
        }

        if ($types !== null && $types !== []) {
            $upper = array_map('strtoupper', $types);
            $actionableWithLegacy = [...TradingRecommendation::ACTIONABLE_ACTIONS, 'BUY', 'SELL'];
            if ($upper === array_map('strtoupper', $actionableWithLegacy)) {
                $query->actionableTypes();
            } else {
                $query->where(function ($q) use ($upper) {
                    foreach ($upper as $i => $t) {
                        $method = $i === 0 ? 'whereRaw' : 'orWhereRaw';
                        $q->{$method}('UPPER(recommendation_type) = ?', [$t]);
                    }
                });
            }
        }

        return $query
            ->orderByDesc('priority')
            ->orderByDesc('id');
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
        return $this->profileListQuery($profile, $statuses, $types)
            ->limit($limit)
            ->get()
            ->all();
    }

    /**
     * @param  list<string>|null  $statuses
     * @param  list<string>|null  $types
     * @return LengthAwarePaginator<int, TradingRecommendation>
     */
    public function paginateForProfile(
        PortfolioProfile $profile,
        ?array $statuses = null,
        int $page = 1,
        int $pageSize = 100,
        ?array $types = null,
    ): LengthAwarePaginator {
        $pageSize = TradingOsPagination::clampPageSize($pageSize);

        return $this->profileListQuery($profile, $statuses, $types)
            ->paginate($pageSize, ['*'], 'page', TradingOsPagination::clampPage($page));
    }

    /**
     * @return list<TradingRecommendation>
     */
    public function listOpenForReview(PortfolioProfile $profile, int $limit = 100): array
    {
        return TradingRecommendation::query()
            ->with(['security', 'evaluationResult', 'reviews'])
            ->forProfile($profile)
            ->openForReview()
            ->actionableTypes()
            ->orderByDesc('priority')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->all();
    }

    /**
     * @return list<TradingRecommendation>
     */
    public function listPendingExecution(PortfolioProfile $profile, int $limit = 100): array
    {
        return TradingRecommendation::query()
            ->with(['security', 'evaluationResult', 'reviews'])
            ->forProfile($profile)
            ->pendingExecution()
            ->actionableTypes()
            ->orderByDesc('priority')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->all();
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
            ->forProfile($profile)
            ->where('id', $id)
            ->first();
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
