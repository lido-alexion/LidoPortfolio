<?php

namespace App\Repositories\Tos;

use App\Models\PortfolioProfile;
use App\Models\ReviewReport;
use App\Support\TradingOsPagination;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * V4-FEAT-032 — review-report list/find queries.
 * Report generation and dashboard aggregations stay in ReviewEngine.
 */
class ReviewReportRepository
{
    /**
     * @return LengthAwarePaginator<int, ReviewReport>
     */
    public function paginateReports(PortfolioProfile $profile, int $page = 1, int $pageSize = 20): LengthAwarePaginator
    {
        return ReviewReport::query()
            ->with('metrics')
            ->where('profile_id', $profile->id)
            ->orderByDesc('id')
            ->paginate(
                TradingOsPagination::clampPageSize($pageSize),
                ['*'],
                'page',
                TradingOsPagination::clampPage($page),
            );
    }

    public function findForProfile(PortfolioProfile $profile, int $id): ?ReviewReport
    {
        return ReviewReport::query()
            ->with('metrics')
            ->where('profile_id', $profile->id)
            ->where('id', $id)
            ->first();
    }
}
