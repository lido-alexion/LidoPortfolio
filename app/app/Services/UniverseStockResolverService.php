<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;

/**
 * @deprecated Prefer EquityUniverseService — kept as a thin wrapper for existing callers.
 */
class UniverseStockResolverService
{
    public const SCOPE_ALL_EQUITIES = EquityUniverseService::SCOPE_ALL_EQUITIES;

    /** @deprecated Use SCOPE_ALL_EQUITIES */
    public const SCOPE_ALL_NSE = EquityUniverseService::SCOPE_ALL_NSE;

    public const SCOPE_NIFTY500 = EquityUniverseService::SCOPE_NIFTY500;

    public function __construct(
        protected EquityUniverseService $equityUniverse,
    ) {}

    public function defaultScope(): string
    {
        return $this->equityUniverse->defaultScope();
    }

    public function normalizeScope(?string $scope): string
    {
        return $this->equityUniverse->normalizeScope($scope);
    }

    public function stockQuery(?string $scope = null): Builder
    {
        return $this->equityUniverse->universeStockQuery($scope);
    }

    public function count(?string $scope = null): int
    {
        return $this->stockQuery($scope)->count();
    }

    public function applyAfterCursor(Builder $query, int $cursorStockId, ?int $cursorPriority = null): Builder
    {
        return $this->equityUniverse->applyAfterCursor($query, $cursorStockId, $cursorPriority);
    }

    public function countThroughCursor(?string $scope, int $cursorStockId, ?int $cursorPriority = null): int
    {
        return $this->equityUniverse->countThroughCursor($scope, $cursorStockId, $cursorPriority);
    }

    public function hasStocksAfterCursor(?string $scope, int $cursorStockId, ?int $cursorPriority = null): bool
    {
        return $this->equityUniverse->hasStocksAfterCursor($scope, $cursorStockId, $cursorPriority);
    }

    public function syncPriorityForStockId(int $stockId): int
    {
        return $this->equityUniverse->syncPriorityForStockId($stockId);
    }
}