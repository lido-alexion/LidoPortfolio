<?php

namespace App\Services;

use App\Models\Stock;
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
     * Active tradable equities for universe OHLCV sync (NSE + BSE-only).
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
            })
            ->orderBy('id');

        if ($scope === self::SCOPE_NIFTY500) {
            $symbols = $this->nifty500->symbols();
            if ($symbols === []) {
                return $query->whereRaw('1 = 0');
            }

            $query->where('exchange', 'NSE')->whereIn('symbol', $symbols);
        }

        return $query;
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
}
