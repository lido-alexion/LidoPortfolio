<?php

namespace App\Services;

use App\Models\Stock;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

class UniverseStockResolverService
{
    public const SCOPE_ALL_NSE = 'all_nse';

    public const SCOPE_NIFTY500 = 'nifty500';

    public function __construct(
        protected Nifty500ConstituentService $nifty500,
    ) {}

    public function defaultScope(): string
    {
        $scope = config('portfolio.universe_price_sync.scope', self::SCOPE_ALL_NSE);

        return $this->normalizeScope($scope);
    }

    public function normalizeScope(?string $scope): string
    {
        $scope = strtolower(trim((string) $scope));

        if (! in_array($scope, [self::SCOPE_ALL_NSE, self::SCOPE_NIFTY500], true)) {
            throw new InvalidArgumentException('Unsupported universe scope: '.$scope);
        }

        return $scope;
    }

    /**
     * Active NSE equities in the configured universe (excludes benchmarks).
     */
    public function stockQuery(?string $scope = null): Builder
    {
        $scope = $this->normalizeScope($scope ?? $this->defaultScope());

        $query = Stock::query()
            ->where('is_active', true)
            ->where('is_benchmark', false)
            ->where('exchange', 'NSE')
            ->orderBy('id');

        if ($scope === self::SCOPE_NIFTY500) {
            $symbols = $this->nifty500->symbols();
            if ($symbols === []) {
                return $query->whereRaw('1 = 0');
            }

            $query->whereIn('symbol', $symbols);
        }

        return $query;
    }

    public function count(?string $scope = null): int
    {
        return $this->stockQuery($scope)->count();
    }
}
