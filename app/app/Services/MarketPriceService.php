<?php

namespace App\Services;

use App\Models\Stock;
use App\Models\StockPrice;
use App\Services\PriceProviders\KiteAuthenticatedPriceData;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class MarketPriceService
{
    public function __construct(protected KiteAuthenticatedPriceData $kitePrices) {}

    /**
     * Authenticated, account-scoped chart read. Kite's raw candles are returned
     * only as one coherent source series; they are never persisted or
     * combined with corporate-action-adjusted shared history.
     *
     * @return array<string, mixed>
     */
    public function accountHistoryForStock(Stock $stock, int $userId): array
    {
        $cached = $this->historyForStock($stock);
        $to = now('Asia/Kolkata')->startOfDay();
        $cached['data'] = $cached['data']->filter(fn ($row) => $row->price_date?->toDateString() <= $to->toDateString())->values();
        $from = $cached['data']->isNotEmpty()
            ? Carbon::parse($cached['data']->last()->price_date->toDateString(), 'Asia/Kolkata')->startOfDay()
            : now('Asia/Kolkata')->subYear()->startOfDay();
        // Kite serves at most ten years. For older stored histories, request the
        // latest ten-year window instead of suppressing Kite for the whole chart.
        $from = $from->max($to->copy()->subDays(3650)->startOfDay())->min($to->copy());
        $quote = null;
        try {
            // A live quote is independent of the historical provider and remains
            // optional; the adapter enforces session, freshness and permission.
            $quote = $this->kitePrices->latestQuote($userId, $stock);
        } catch (\Throwable) {
            $quote = null;
        }

        // Kite history is unadjusted and capped by its adapter. Keep a coherent
        // source series by falling back wholesale when Kite cannot serve it.
        try {
            $cacheKey = 'kite:price:history:'. $userId .':'. $stock->id .':'. $from->toDateString() .':'. $to->toDateString();
            $rows = [];
            if ($this->kitePrices->hasUsableSession($userId)) {
                $rows = Cache::get($cacheKey);
                if (! is_array($rows)) {
                    $rows = $this->kitePrices->historicalDaily($userId, $stock, $from, $to);
                    if ($rows !== []) {
                        Cache::put($cacheKey, $rows, 900);
                    }
                }
            }
            if ($rows !== []) {
                return $this->historyPayload($stock, collect($rows)->sortByDesc('price_date')->values(), 'kite', 'unadjusted', $quote);
            }
        } catch (\Throwable) {
            // A private provider outage must not make a chart unavailable.
        }

        if ($cached['data']->isNotEmpty()) {
            return $this->historyPayload($stock, $this->chartRows($cached['data']), 'stored', 'corporate_action_adjusted_when_available', $quote);
        }

        // An empty local history can occur for a newly listed stock. Use the
        // repository's existing fallback chain without writing shared rows.
        try {
            $fallbackKey = 'market-price:fallback:'.$stock->id.':'.$from->toDateString().':'.$to->toDateString();
            $fallback = Cache::get($fallbackKey);
            if (! is_array($fallback)) {
                $fallback = app(PriceFetchService::class)->fetchHistoricalWithFallback(
                    (string) $stock->symbol,
                    $from,
                    $to,
                    $stock,
                    notifyTelegramOnFailure: false,
                );
                if (($fallback['rows'] ?? []) !== []) {
                    Cache::put($fallbackKey, $fallback, 900);
                }
            }
            if (($fallback['rows'] ?? []) !== []) {
                return $this->historyPayload($stock, collect($fallback['rows'])->sortByDesc('price_date')->values(), (string) ($fallback['provider'] ?? 'provider'), 'provider_native', $quote);
            }
        } catch (\Throwable) {
            // Keep the established empty-history response contract.
        }

        return $this->historyPayload($stock, $this->chartRows($cached['data']), 'stored', 'corporate_action_adjusted_when_available', $quote);
    }

    /**
     * Eloquent's decimal:4 cast correctly preserves database precision as a
     * string. The chart JSON contract represents OHLC values as numbers, so
     * normalize only this read payload (without changing model/storage casts).
     */
    protected function chartRows($prices)
    {
        return $prices->map(function ($row): array {
            $data = $row->toArray();
            foreach (['open_price', 'high_price', 'low_price', 'close_price', 'adjusted_close_price'] as $field) {
                if (array_key_exists($field, $data) && $data[$field] !== null) {
                    $data[$field] = (float) $data[$field];
                }
            }
            return $data;
        });
    }

    protected function historyPayload(Stock $stock, $prices, string $provider, string $adjustmentBasis, ?array $quote): array
    {
        $latest = $prices->first();
        $latestClose = is_array($latest) ? ($latest['close_price'] ?? null) : $latest?->close_price;
        $latestDate = is_array($latest) ? ($latest['price_date'] ?? null) : $latest?->price_date?->toDateString();
        $oldest = $prices->last();
        $oldestDate = is_array($oldest) ? ($oldest['price_date'] ?? null) : $oldest?->price_date?->toDateString();
        return [
            'stock' => $stock->only(['id', 'symbol', 'name', 'exchange']),
            'data' => $prices,
            'price_count' => $prices->count(),
            'has_price_history' => $prices->isNotEmpty(),
            'from_date' => $oldestDate,
            'to_date' => $latestDate,
            'latest_close' => $latestClose !== null ? round((float) $latestClose, 4) : null,
            'latest_price_date' => $latestDate,
            'provider' => $provider,
            'adjustment_basis' => $adjustmentBasis,
            'live_quote' => $quote,
        ];
    }

    /**
     * Cached OHLCV from portfolio_stock_prices (no holding required).
     *
     * @return array{
     *     stock: array<string, mixed>,
     *     data: \Illuminate\Support\Collection,
     *     price_count: int,
     *     has_price_history: bool,
     *     from_date: ?string,
     *     to_date: ?string,
     *     latest_close: ?float,
     *     latest_price_date: ?string
     * }
     */
    public function historyForStock(Stock $stock): array
    {
        $prices = StockPrice::query()
            ->where('stock_id', $stock->id)
            ->orderByDesc('price_date')
            ->get();

        $latest = $prices->first();

        return [
            'stock' => $stock->only(['id', 'symbol', 'name', 'exchange']),
            'data' => $prices,
            'price_count' => $prices->count(),
            'has_price_history' => $prices->isNotEmpty(),
            'from_date' => $prices->last()?->price_date?->toDateString(),
            'to_date' => $latest?->price_date?->toDateString(),
            'latest_close' => $latest !== null ? round((float) $latest->close_price, 4) : null,
            'latest_price_date' => $latest?->price_date?->toDateString(),
        ];
    }

    /**
     * @return array{
     *     price_count: int,
     *     has_price_history: bool,
     *     latest_close: ?float,
     *     latest_price_date: ?string,
     *     previous_close: ?float,
     *     daily_change: ?float,
     *     daily_change_percent: ?float,
     *     is_price_fresh: bool
     * }
     */
    public function summaryForStock(Stock $stock): array
    {
        $rows = StockPrice::query()
            ->where('stock_id', $stock->id)
            ->orderByDesc('price_date')
            ->limit(2)
            ->get(['price_date', 'close_price']);

        $latest = $rows->get(0);
        $previous = $rows->get(1);
        $priceCount = StockPrice::query()
            ->where('stock_id', $stock->id)
            ->count();

        $latestClose = $latest !== null ? round((float) $latest->close_price, 4) : null;
        $previousClose = $previous !== null ? round((float) $previous->close_price, 4) : null;
        $latestDate = $latest?->price_date?->toDateString();

        $dailyChange = null;
        $dailyChangePercent = null;
        if ($latestClose !== null && $previousClose !== null && $previousClose > 0) {
            $dailyChange = round($latestClose - $previousClose, 4);
            $dailyChangePercent = round(($dailyChange / $previousClose) * 100, 2);
        }

        return [
            'price_count' => $priceCount,
            'has_price_history' => $priceCount > 0,
            'latest_close' => $latestClose,
            'latest_price_date' => $latestDate,
            'previous_close' => $previousClose,
            'daily_change' => $dailyChange,
            'daily_change_percent' => $dailyChangePercent,
            'is_price_fresh' => $this->isPriceFresh($latestDate),
        ];
    }

    protected function isPriceFresh(?string $latestPriceDate): bool
    {
        if ($latestPriceDate === null || $latestPriceDate === '') {
            return false;
        }

        $tz = config('app.timezone', 'Asia/Kolkata');
        $today = now($tz)->toDateString();

        return Carbon::parse($latestPriceDate, $tz)->toDateString() === $today;
    }
}
