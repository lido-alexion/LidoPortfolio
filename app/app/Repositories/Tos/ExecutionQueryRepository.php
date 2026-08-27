<?php

namespace App\Repositories\Tos;

use App\Models\Holding;
use App\Models\PortfolioProfile;
use App\Models\TradingOrder;
use App\Models\Transaction;
use App\Support\TradingOsPagination;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * V4-FEAT-032 — TOS execution list/find queries (orders, ledger rows, open positions).
 * Fill orchestration, validation, and markExecuted stay in ExecutionEngine.
 */
class ExecutionQueryRepository
{
    public function findOrder(PortfolioProfile $profile, int $id): ?TradingOrder
    {
        return TradingOrder::query()
            ->with(['security', 'recommendation', 'orderTransactions'])
            ->where('profile_id', $profile->id)
            ->where('id', $id)
            ->first();
    }

    /**
     * @return LengthAwarePaginator<int, TradingOrder>
     */
    public function paginateOrders(
        PortfolioProfile $profile,
        int $page = 1,
        int $pageSize = 50,
        ?string $status = null,
    ): LengthAwarePaginator {
        $query = TradingOrder::query()
            ->with(['security', 'recommendation'])
            ->where('profile_id', $profile->id);

        if ($status) {
            $query->where('status', $status);
        }

        return $query
            ->orderByDesc('id')
            ->paginate(
                TradingOsPagination::clampPageSize($pageSize),
                ['*'],
                'page',
                TradingOsPagination::clampPage($page),
            );
    }

    /**
     * @return list<Holding>
     */
    public function listOpenPositions(PortfolioProfile $profile): array
    {
        return Holding::query()
            ->with('stock')
            ->where('profile_id', $profile->id)
            ->where('quantity', '>', 0)
            ->orderBy('stock_id')
            ->get()
            ->all();
    }

    /**
     * @return LengthAwarePaginator<int, Transaction>
     */
    public function paginateTransactions(
        PortfolioProfile $profile,
        int $page = 1,
        int $pageSize = 100,
    ): LengthAwarePaginator {
        return Transaction::query()
            ->with('stock')
            ->where('profile_id', $profile->id)
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->paginate(
                TradingOsPagination::clampPageSize($pageSize),
                ['*'],
                'page',
                TradingOsPagination::clampPage($page),
            );
    }
}
