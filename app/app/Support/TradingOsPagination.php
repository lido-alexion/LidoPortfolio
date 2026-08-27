<?php

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

/**
 * V4-FEAT-028 — TOS /api/v1 list pagination.
 *
 * Query: `page` (default 1) and `pageSize` (alias `per_page`).
 * Meta: `{page, pageSize, total, lastPage}`.
 * Max page size is 200 except price-bars (500).
 */
final class TradingOsPagination
{
    public const DEFAULT_PAGE_SIZE = 50;

    public const MAX_PAGE_SIZE = 200;

    public const PRICE_BARS_MAX_PAGE_SIZE = 500;

    public const RECOMMENDATIONS_DEFAULT = 100;

    public const ORDERS_DEFAULT = 50;

    public const TRANSACTIONS_DEFAULT = 100;

    public const NOTIFICATIONS_DEFAULT = 50;

    public const REVIEWS_DEFAULT = 20;

    /**
     * @return array{page: int, pageSize: int}
     */
    public static function resolve(
        Request $request,
        int $defaultPageSize = self::DEFAULT_PAGE_SIZE,
        int $maxPageSize = self::MAX_PAGE_SIZE,
    ): array {
        $page = max(1, (int) $request->query('page', 1));
        $raw = $request->query('pageSize', $request->query('per_page', $defaultPageSize));
        $size = (int) $raw;
        if ($size < 1) {
            $size = $defaultPageSize;
        }

        return [
            'page' => $page,
            'pageSize' => min($size, $maxPageSize),
        ];
    }

    /**
     * @return array{page: int, pageSize: int, total: int, lastPage: int}
     */
    public static function meta(LengthAwarePaginator $paginator): array
    {
        return [
            'page' => $paginator->currentPage(),
            'pageSize' => $paginator->perPage(),
            'total' => $paginator->total(),
            'lastPage' => $paginator->lastPage(),
        ];
    }

    public static function clampPage(int $page): int
    {
        return max(1, $page);
    }

    public static function clampPageSize(int $pageSize, int $maxPageSize = self::MAX_PAGE_SIZE): int
    {
        return max(1, min($pageSize, $maxPageSize));
    }
}
