<?php

namespace App\Repositories\Tos;

use App\Models\PortfolioProfile;
use App\Models\TosNotification;
use App\Support\TradingOsPagination;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * V4-FEAT-032 — TOS notification history queries.
 * Delivery, retry policy, and idempotent send stay in NotificationEngine.
 */
class NotificationQueryRepository
{
    /**
     * @return LengthAwarePaginator<int, TosNotification>
     */
    public function paginateHistory(PortfolioProfile $profile, int $page = 1, int $pageSize = 50): LengthAwarePaginator
    {
        return TosNotification::query()
            ->where('profile_id', $profile->id)
            ->orderByDesc('id')
            ->paginate(
                TradingOsPagination::clampPageSize($pageSize),
                ['*'],
                'page',
                TradingOsPagination::clampPage($page),
            );
    }

    public function findForProfile(PortfolioProfile $profile, int $id): ?TosNotification
    {
        return TosNotification::query()
            ->where('profile_id', $profile->id)
            ->where('id', $id)
            ->first();
    }

    public function findByIdempotencyKey(string $key): ?TosNotification
    {
        return TosNotification::query()->where('idempotency_key', $key)->first();
    }
}
