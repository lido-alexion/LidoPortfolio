<?php

namespace App\Services;

use App\Models\PortfolioProfile;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\Watchlist;
use App\Models\WatchlistItem;
use App\Models\WatchlistPatternScan;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PatternScanService
{
    public function __construct(
        protected PatternDetectionService $detection,
    ) {}

    /**
     * @return array{
     *     scope: string,
     *     actionable_only: bool,
     *     watchlist_id: ?int,
     *     persisted: bool,
     *     results: list<array{
     *         stock_id: int,
     *         symbol: string,
     *         name: ?string,
     *         exchange: ?string,
     *         matches: list<array<string, mixed>>
     *     }>
     * }
     */
    public function scan(
        PortfolioProfile $profile,
        string $scope,
        bool $actionableOnly = true,
        ?int $watchlistId = null,
    ): array {
        $watchlist = null;
        if ($scope === 'watchlist' && $watchlistId !== null) {
            $watchlist = Watchlist::query()
                ->where('profile_id', $profile->id)
                ->where('id', $watchlistId)
                ->first();
        }

        $stocks = match ($scope) {
            'watchlist' => $this->stocksFromWatchlist($profile, $watchlistId),
            'holdings' => $this->stocksFromHoldings($profile),
            default => collect(),
        };

        $results = [];
        $scannedPayload = [];

        foreach ($stocks as $stock) {
            $bars = $this->loadBars($stock);
            $priceAsOf = $bars === [] ? null : $bars[array_key_last($bars)]['date'];
            $matches = $bars === [] ? [] : $this->detection->scanBars($bars, $actionableOnly);

            $scannedPayload[] = [
                'stock' => $stock,
                'matches' => $matches,
                'price_as_of' => $priceAsOf,
            ];

            if ($matches === []) {
                continue;
            }

            $results[] = [
                'stock_id' => $stock->id,
                'symbol' => $stock->symbol,
                'name' => $stock->name,
                'exchange' => $stock->exchange,
                'matches' => $matches,
            ];
        }

        $persisted = false;
        if ($watchlist !== null) {
            $this->persistWatchlistScan($watchlist, $scannedPayload);
            $persisted = true;
        }

        return [
            'scope' => $scope,
            'actionable_only' => $actionableOnly,
            'watchlist_id' => $watchlist?->id,
            'persisted' => $persisted,
            'results' => $results,
        ];
    }

    /**
     * Scan a single stock for the profile. Reuses a valid persisted watchlist
     * scan when the stock is on any of the profile's watchlists; otherwise
     * computes fresh and writes the result back to those watchlist rows so the
     * next load (and the watchlist item icons) reuse it.
     *
     * @return array{
     *     stock_id: int,
     *     symbol: string,
     *     name: ?string,
     *     exchange: ?string,
     *     matches: list<array<string, mixed>>,
     *     price_as_of: ?string,
     *     source: string,
     *     persisted: bool
     * }
     */
    public function scanStock(PortfolioProfile $profile, Stock $stock, bool $actionableOnly = false): array
    {
        $base = [
            'stock_id' => (int) $stock->id,
            'symbol' => $stock->symbol,
            'name' => $stock->name,
            'exchange' => $stock->exchange,
        ];

        $cached = $this->validScanForStock($profile, $stock);
        if ($cached !== null) {
            // Copy the cached result to member watchlists that don't have a
            // valid row yet (e.g. stock just added to another list).
            $this->persistStockScanToMemberWatchlists(
                $profile,
                $stock,
                $cached['matches'],
                $cached['price_as_of'],
                onlyMissing: true,
            );

            return $base + [
                'matches' => $cached['matches'],
                'price_as_of' => $cached['price_as_of'],
                'source' => 'watchlist_cache',
                'persisted' => true,
            ];
        }

        $bars = $this->loadBars($stock);
        $priceAsOf = $bars === [] ? null : $bars[array_key_last($bars)]['date'];
        $matches = $bars === [] ? [] : $this->detection->scanBars($bars, $actionableOnly);

        $persisted = $this->persistStockScanToMemberWatchlists($profile, $stock, $matches, $priceAsOf);

        return $base + [
            'matches' => $matches,
            'price_as_of' => $priceAsOf,
            'source' => 'fresh',
            'persisted' => $persisted,
        ];
    }

    /**
     * Latest valid (non-expired, price-fresh) persisted scan for a stock across
     * all of the profile's watchlists. An empty matches array is still a valid
     * cached result ("scanned, nothing found").
     *
     * @return array{matches: list<array<string, mixed>>, price_as_of: ?string}|null
     */
    protected function validScanForStock(PortfolioProfile $profile, Stock $stock): ?array
    {
        $scans = WatchlistPatternScan::query()
            ->where('profile_id', $profile->id)
            ->where('stock_id', $stock->id)
            ->where('expires_at', '>', now())
            ->orderByDesc('scanned_at')
            ->get();

        if ($scans->isEmpty()) {
            return null;
        }

        $latestByStock = $this->latestPriceDatesForStocks([(int) $stock->id]);
        $latestRaw = $latestByStock[(int) $stock->id] ?? null;
        $latest = $latestRaw ? Carbon::parse($latestRaw)->startOfDay() : null;

        foreach ($scans as $scan) {
            $priceAsOf = $scan->price_as_of
                ? Carbon::parse($scan->price_as_of)->startOfDay()
                : null;

            // Invalidate only when a strictly newer OHLCV session exists.
            if ($priceAsOf !== null && $latest !== null && $latest->gt($priceAsOf)) {
                continue;
            }

            $matches = $scan->matches;
            if (is_string($matches)) {
                $decoded = json_decode($matches, true);
                $matches = is_array($decoded) ? $decoded : [];
            }

            return [
                'matches' => is_array($matches) ? array_values($matches) : [],
                'price_as_of' => $priceAsOf?->toDateString(),
            ];
        }

        return null;
    }

    /**
     * Write a fresh single-stock result to every profile watchlist containing
     * the stock, so watchlist icons and later loads reuse it.
     *
     * @param  list<array<string, mixed>>  $matches
     */
    protected function persistStockScanToMemberWatchlists(
        PortfolioProfile $profile,
        Stock $stock,
        array $matches,
        ?string $priceAsOf,
        bool $onlyMissing = false,
    ): bool {
        $watchlistIds = WatchlistItem::query()
            ->where('profile_id', $profile->id)
            ->where('stock_id', $stock->id)
            ->pluck('watchlist_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($watchlistIds->isEmpty()) {
            return false;
        }

        $now = now();
        $expiresAt = $this->resolveExpiresAt($priceAsOf, $now);

        if ($onlyMissing) {
            $existing = WatchlistPatternScan::query()
                ->whereIn('watchlist_id', $watchlistIds)
                ->where('stock_id', $stock->id)
                ->where('expires_at', '>', $now)
                ->pluck('watchlist_id')
                ->map(fn ($id) => (int) $id)
                ->all();
            $watchlistIds = $watchlistIds->reject(fn ($id) => in_array($id, $existing, true))->values();
        }

        foreach ($watchlistIds as $watchlistId) {
            WatchlistPatternScan::query()->updateOrCreate(
                [
                    'watchlist_id' => $watchlistId,
                    'stock_id' => $stock->id,
                ],
                [
                    'profile_id' => $profile->id,
                    'matches' => $matches,
                    'price_as_of' => $priceAsOf,
                    'expires_at' => $expiresAt,
                    'scanned_at' => $now,
                ],
            );
        }

        return true;
    }

    /**
     * Valid (non-expired, price-fresh) pattern matches keyed by stock_id.
     *
     * @return array<int, list<array<string, mixed>>>
     */
    public function validMatchesByStockForWatchlist(Watchlist $watchlist): array
    {
        $now = now();

        $scans = WatchlistPatternScan::query()
            ->where('watchlist_id', $watchlist->id)
            ->where('expires_at', '>', $now)
            ->get();

        if ($scans->isEmpty()) {
            return [];
        }

        $stockIds = $scans->pluck('stock_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $latestByStock = $this->latestPriceDatesForStocks($stockIds);

        $out = [];
        foreach ($scans as $scan) {
            $stockId = (int) $scan->stock_id;
            $priceAsOf = $scan->price_as_of
                ? Carbon::parse($scan->price_as_of)->startOfDay()
                : null;
            $latestRaw = $latestByStock[$stockId] ?? null;
            $latest = $latestRaw
                ? Carbon::parse($latestRaw)->startOfDay()
                : null;

            // Invalidate only when a strictly newer OHLCV session exists.
            if ($priceAsOf !== null && $latest !== null && $latest->gt($priceAsOf)) {
                continue;
            }

            $matches = $scan->matches;
            if (is_string($matches)) {
                $decoded = json_decode($matches, true);
                $matches = is_array($decoded) ? $decoded : [];
            }
            if (! is_array($matches) || $matches === []) {
                continue;
            }

            $out[$stockId] = array_values($matches);
        }

        return $out;
    }

    /**
     * @param  list<array{stock: Stock, matches: list<array<string, mixed>>, price_as_of: ?string}>  $payload
     */
    protected function persistWatchlistScan(Watchlist $watchlist, array $payload): void
    {
        $now = now();
        $stockIds = [];

        DB::transaction(function () use ($watchlist, $payload, $now, &$stockIds) {
            foreach ($payload as $row) {
                /** @var Stock $stock */
                $stock = $row['stock'];
                $stockIds[] = (int) $stock->id;
                $priceAsOf = $row['price_as_of'];
                $expiresAt = $this->resolveExpiresAt($priceAsOf, $now);

                WatchlistPatternScan::query()->updateOrCreate(
                    [
                        'watchlist_id' => $watchlist->id,
                        'stock_id' => $stock->id,
                    ],
                    [
                        'profile_id' => $watchlist->profile_id,
                        'matches' => $row['matches'],
                        'price_as_of' => $priceAsOf,
                        'expires_at' => $expiresAt,
                        'scanned_at' => $now,
                    ],
                );
            }

            // Drop scans for stocks no longer on the watchlist.
            WatchlistPatternScan::query()
                ->where('watchlist_id', $watchlist->id)
                ->when($stockIds !== [], fn ($q) => $q->whereNotIn('stock_id', $stockIds))
                ->delete();
        });
    }

    protected function resolveExpiresAt(?string $priceAsOf, Carbon $scannedAt): Carbon
    {
        $tz = config('app.timezone', 'Asia/Kolkata');
        $fromScan = $scannedAt->copy()->timezone($tz)->startOfDay()->addDays(2)->endOfDay();

        if ($priceAsOf === null || $priceAsOf === '') {
            return $fromScan;
        }

        $fromPrice = Carbon::parse($priceAsOf, $tz)->startOfDay()->addDays(2)->endOfDay();

        return $fromPrice->greaterThan($fromScan) ? $fromPrice : $fromScan;
    }

    /**
     * @param  list<int>  $stockIds
     * @return array<int, string>
     */
    protected function latestPriceDatesForStocks(array $stockIds): array
    {
        if ($stockIds === []) {
            return [];
        }

        $rows = StockPrice::query()
            ->whereIn('stock_id', $stockIds)
            ->selectRaw('stock_id, MAX(price_date) as latest_price_date')
            ->groupBy('stock_id')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $stockId = (int) $row->stock_id;
            $raw = $row->latest_price_date;
            if ($raw instanceof Carbon) {
                $out[$stockId] = $raw->toDateString();
            } else {
                $out[$stockId] = Carbon::parse((string) $raw)->toDateString();
            }
        }

        return $out;
    }

    /** @return Collection<int, Stock> */
    protected function stocksFromHoldings(PortfolioProfile $profile): Collection
    {
        return $profile->holdings()
            ->where('quantity', '>', 0)
            ->with('stock')
            ->get()
            ->pluck('stock')
            ->filter()
            ->unique('id')
            ->values();
    }

    /** @return Collection<int, Stock> */
    protected function stocksFromWatchlist(PortfolioProfile $profile, ?int $watchlistId = null): Collection
    {
        $query = WatchlistItem::query()
            ->where('profile_id', $profile->id)
            ->with('stock');

        if ($watchlistId !== null) {
            $query->where('watchlist_id', $watchlistId);
        }

        return $query->get()
            ->pluck('stock')
            ->filter()
            ->unique('id')
            ->values();
    }

    /**
     * @return list<array{date: string, open: float, high: float, low: float, close: float}>
     */
    protected function loadBars(Stock $stock): array
    {
        $rows = StockPrice::query()
            ->where('stock_id', $stock->id)
            ->orderBy('price_date')
            ->get(['price_date', 'open_price', 'high_price', 'low_price', 'close_price']);

        $bars = [];

        foreach ($rows as $row) {
            $close = (float) $row->close_price;
            $open = $row->open_price !== null ? (float) $row->open_price : $close;
            $high = $row->high_price !== null ? (float) $row->high_price : max($open, $close);
            $low = $row->low_price !== null ? (float) $row->low_price : min($open, $close);

            $bars[] = [
                'date' => $row->price_date->toDateString(),
                'open' => $open,
                'high' => $high,
                'low' => $low,
                'close' => $close,
            ];
        }

        return $bars;
    }
}
