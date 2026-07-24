<?php

namespace App\Services;

use App\Models\Holding;
use App\Models\Stock;
use App\Models\WatchlistItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class EquityUniverseService
{
    public const EXCHANGE_LABEL_NSE_DUAL = 'NSE+';

    public const SCOPE_ALL_EQUITIES = 'all_equities';

    /** @deprecated Use SCOPE_ALL_EQUITIES */
    public const SCOPE_ALL_NSE = 'all_nse';

    public const SCOPE_NIFTY500 = 'nifty500';

    public const SYNC_PRIORITY_HOLDING = 0;

    public const SYNC_PRIORITY_WATCHLIST = 1;

    public const SYNC_PRIORITY_OTHER = 2;

    /** @var Collection<int, int>|null */
    protected ?Collection $cachedHoldingStockIds = null;

    /** @var Collection<int, int>|null */
    protected ?Collection $cachedWatchlistOnlyStockIds = null;

    public function __construct(
        protected Nifty500ConstituentService $nifty500,
    ) {}

    public function defaultScope(): string
    {
        return $this->normalizeScope(config('portfolio.universe_price_sync.scope', self::SCOPE_ALL_EQUITIES));
    }

    public function normalizeScope(?string $scope): string
    {
        $scope = strtolower(trim((string) $scope));

        if ($scope === self::SCOPE_ALL_NSE) {
            return self::SCOPE_ALL_EQUITIES;
        }

        if (! in_array($scope, [self::SCOPE_ALL_EQUITIES, self::SCOPE_NIFTY500], true)) {
            throw new InvalidArgumentException('Unsupported universe scope: '.$scope);
        }

        return $scope;
    }

    public function exchangeLabel(Stock $stock): string
    {
        if ($stock->exchange === 'NSE' && $stock->is_dual_listed) {
            return self::EXCHANGE_LABEL_NSE_DUAL;
        }

        return (string) $stock->exchange;
    }

    /**
     * @return array<string, mixed>
     */
    public function formatStockForApi(Stock $stock): array
    {
        $payload = $stock->toArray();
        $payload['exchange_label'] = $this->exchangeLabel($stock);

        return $payload;
    }

    /**
     * @return Collection<int, string>
     */
    public function activeNseIsins(): Collection
    {
        return Stock::query()
            ->where('is_active', true)
            ->where('is_benchmark', false)
            ->where('exchange', 'NSE')
            ->whereNotNull('isin')
            ->where('isin', '!=', '')
            ->pluck('isin');
    }

    /**
     * Distinct stock IDs with open holdings across all portfolios.
     *
     * @return Collection<int, int>
     */
    public function holdingStockIds(): Collection
    {
        if ($this->cachedHoldingStockIds !== null) {
            return $this->cachedHoldingStockIds;
        }

        $this->cachedHoldingStockIds = Holding::query()
            ->where('quantity', '>', 0)
            ->distinct()
            ->orderBy('stock_id')
            ->pluck('stock_id')
            ->map(fn ($id) => (int) $id)
            ->values();

        return $this->cachedHoldingStockIds;
    }

    /**
     * Watchlist stock IDs that are not already open holdings (all portfolios).
     *
     * @return Collection<int, int>
     */
    public function watchlistOnlyStockIds(): Collection
    {
        if ($this->cachedWatchlistOnlyStockIds !== null) {
            return $this->cachedWatchlistOnlyStockIds;
        }

        $holdingSet = array_fill_keys($this->holdingStockIds()->all(), true);

        $this->cachedWatchlistOnlyStockIds = WatchlistItem::query()
            ->distinct()
            ->orderBy('stock_id')
            ->pluck('stock_id')
            ->map(fn ($id) => (int) $id)
            ->reject(fn (int $id) => isset($holdingSet[$id]))
            ->values();

        return $this->cachedWatchlistOnlyStockIds;
    }

    public function syncPriorityForStockId(int $stockId): int
    {
        if ($stockId <= 0) {
            return self::SYNC_PRIORITY_OTHER;
        }

        $holdings = $this->holdingStockIds();
        $watchlists = $this->watchlistOnlyStockIds();

        // With no holdings/watchlists syncPriorityCase() is the constant 0 for
        // every stock; return the same value so cursor comparisons stay aligned.
        if ($holdings->isEmpty() && $watchlists->isEmpty()) {
            return self::SYNC_PRIORITY_HOLDING;
        }

        if (in_array($stockId, $holdings->all(), true)) {
            return self::SYNC_PRIORITY_HOLDING;
        }

        if (in_array($stockId, $watchlists->all(), true)) {
            return self::SYNC_PRIORITY_WATCHLIST;
        }

        return self::SYNC_PRIORITY_OTHER;
    }

    /**
     * Active tradable equities for universe OHLCV sync (NSE + BSE-only).
     * Ordered: holdings → watchlists (non-holdings) → remaining stocks, then by id.
     */
    public function universeStockQuery(?string $scope = null): Builder
    {
        $scope = $this->normalizeScope($scope ?? $this->defaultScope());
        $nseIsins = $this->activeNseIsins();

        $query = Stock::query()
            ->where('is_active', true)
            ->where('is_benchmark', false)
            ->where(function (Builder $builder) use ($nseIsins) {
                $builder->where('exchange', 'NSE')
                    ->orWhere(function (Builder $bseOnly) use ($nseIsins) {
                        $bseOnly->where('exchange', 'BSE');
                        if ($nseIsins->isNotEmpty()) {
                            $bseOnly->where(function (Builder $isinFilter) use ($nseIsins) {
                                $isinFilter->whereNull('isin')
                                    ->orWhere('isin', '')
                                    ->orWhereNotIn('isin', $nseIsins);
                            });
                        }
                    });
            });

        if ($scope === self::SCOPE_NIFTY500) {
            $symbols = $this->nifty500->symbols();
            if ($symbols === []) {
                return $this->applySyncPriorityOrder($query->whereRaw('1 = 0'));
            }

            $query->where('exchange', 'NSE')->whereIn('symbol', $symbols);
        }

        return $this->applySyncPriorityOrder($query);
    }

    public function applySyncPriorityOrder(Builder $query): Builder
    {
        [$sql, $bindings] = $this->syncPriorityCase();

        // With no holdings/watchlists the priority is the constant '0'; SQL
        // engines treat a bare integer in ORDER BY as a column position
        // (SQLite/MySQL error on `ORDER BY 0`), so order by id alone.
        if ($sql === '0') {
            return $query->orderBy('id');
        }

        return $query->orderByRaw($sql, $bindings)->orderBy('id');
    }

    /**
     * Restrict to stocks after the cursor position in sync-priority order.
     * Pass `$cursorPriority` from the stored cursor so mid-cycle membership
     * changes do not scramble remaining work.
     */
    public function applyAfterCursor(Builder $query, int $cursorStockId, ?int $cursorPriority = null): Builder
    {
        if ($cursorStockId <= 0) {
            return $query;
        }

        $priority = $cursorPriority ?? $this->syncPriorityForStockId($cursorStockId);
        [$caseSql, $caseBindings] = $this->syncPriorityCase();

        return $query->where(function (Builder $outer) use ($caseSql, $caseBindings, $priority, $cursorStockId) {
            $outer->whereRaw("({$caseSql}) > ?", [...$caseBindings, $priority])
                ->orWhere(function (Builder $sameTier) use ($caseSql, $caseBindings, $priority, $cursorStockId) {
                    $sameTier->whereRaw("({$caseSql}) = ?", [...$caseBindings, $priority])
                        ->where('id', '>', $cursorStockId);
                });
        });
    }

    /**
     * Restrict to stocks at or before the cursor position in sync-priority order.
     */
    public function applyThroughCursor(Builder $query, int $cursorStockId, ?int $cursorPriority = null): Builder
    {
        if ($cursorStockId <= 0) {
            return $query->whereRaw('1 = 0');
        }

        $priority = $cursorPriority ?? $this->syncPriorityForStockId($cursorStockId);
        [$caseSql, $caseBindings] = $this->syncPriorityCase();

        return $query->where(function (Builder $outer) use ($caseSql, $caseBindings, $priority, $cursorStockId) {
            $outer->whereRaw("({$caseSql}) < ?", [...$caseBindings, $priority])
                ->orWhere(function (Builder $sameTier) use ($caseSql, $caseBindings, $priority, $cursorStockId) {
                    $sameTier->whereRaw("({$caseSql}) = ?", [...$caseBindings, $priority])
                        ->where('id', '<=', $cursorStockId);
                });
        });
    }

    public function countThroughCursor(?string $scope, int $cursorStockId, ?int $cursorPriority = null): int
    {
        if ($cursorStockId <= 0) {
            return 0;
        }

        return $this->applyThroughCursor(
            $this->universeStockQuery($scope),
            $cursorStockId,
            $cursorPriority,
        )->count();
    }

    public function hasStocksAfterCursor(?string $scope, int $cursorStockId, ?int $cursorPriority = null): bool
    {
        if ($cursorStockId <= 0) {
            return $this->universeStockQuery($scope)->exists();
        }

        return $this->applyAfterCursor(
            $this->universeStockQuery($scope),
            $cursorStockId,
            $cursorPriority,
        )->exists();
    }

    /**
     * Local master search (autocomplete). Excludes BSE rows duplicated on NSE via ISIN.
     */
    public function searchQuery(?string $exchangeFilter = null): Builder
    {
        $nseIsins = $this->activeNseIsins();

        $query = Stock::query()
            ->where('is_benchmark', false)
            ->where('is_active', true);

        $exchangeFilter = $exchangeFilter ? strtoupper($exchangeFilter) : null;

        if ($exchangeFilter === 'NSE') {
            $query->where('exchange', 'NSE');
        } elseif ($exchangeFilter === 'BSE') {
            $query->where('exchange', 'BSE');
            if ($nseIsins->isNotEmpty()) {
                $query->where(function (Builder $builder) use ($nseIsins) {
                    $builder->whereNull('isin')
                        ->orWhere('isin', '')
                        ->orWhereNotIn('isin', $nseIsins);
                });
            }
        } else {
            $query->where(function (Builder $builder) use ($nseIsins) {
                $builder->where('exchange', 'NSE')
                    ->orWhere(function (Builder $bseOnly) use ($nseIsins) {
                        $bseOnly->where('exchange', 'BSE');
                        if ($nseIsins->isNotEmpty()) {
                            $bseOnly->where(function (Builder $isinFilter) use ($nseIsins) {
                                $isinFilter->whereNull('isin')
                                    ->orWhere('isin', '')
                                    ->orWhereNotIn('isin', $nseIsins);
                            });
                        }
                    });
            });
        }

        return $query;
    }

    public function resolveCanonicalStock(string $symbol, string $exchange): ?Stock
    {
        $symbol = strtoupper(trim($symbol));
        $exchange = strtoupper(trim($exchange));

        $direct = Stock::query()
            ->where('symbol', $symbol)
            ->where('exchange', $exchange)
            ->where('is_benchmark', false)
            ->where('is_active', true)
            ->first();

        if ($direct) {
            return $direct;
        }

        if ($exchange === 'BSE') {
            $nse = Stock::query()
                ->where('symbol', $symbol)
                ->where('exchange', 'NSE')
                ->where('is_benchmark', false)
                ->where('is_active', true)
                ->first();

            if ($nse && $nse->is_dual_listed) {
                return $nse;
            }
        }

        return null;
    }

    /**
     * @return array{0: string, 1: list<int>}
     */
    protected function syncPriorityCase(): array
    {
        $holdings = $this->holdingStockIds();
        $watchlists = $this->watchlistOnlyStockIds();

        if ($holdings->isEmpty() && $watchlists->isEmpty()) {
            return ['0', []];
        }

        $sql = 'CASE';
        $bindings = [];

        if ($holdings->isNotEmpty()) {
            $placeholders = implode(',', array_fill(0, $holdings->count(), '?'));
            $sql .= " WHEN id IN ({$placeholders}) THEN ".self::SYNC_PRIORITY_HOLDING;
            array_push($bindings, ...$holdings->all());
        }

        if ($watchlists->isNotEmpty()) {
            $placeholders = implode(',', array_fill(0, $watchlists->count(), '?'));
            $sql .= " WHEN id IN ({$placeholders}) THEN ".self::SYNC_PRIORITY_WATCHLIST;
            array_push($bindings, ...$watchlists->all());
        }

        $sql .= ' ELSE '.self::SYNC_PRIORITY_OTHER.' END';

        return [$sql, $bindings];
    }
}
