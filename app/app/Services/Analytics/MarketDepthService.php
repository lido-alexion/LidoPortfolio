<?php

namespace App\Services\Analytics;

use App\Models\MarketDepthSnapshot;
use App\Models\Setting;
use App\Models\StockPrice;
use App\Services\IndexCatalogService;
use App\Services\IndexConstituentService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Index × criterion breadth matrix (Rising / RS55 / SMAs).
 * One snapshot per as-of date (exchange_scope=all).
 * Market breadth is NSE-only (NSE/NSE+ constituents), excluding BSE-only stocks.
 * Dashboard must not call compute — use forDashboard / pagePayload only.
 */
class MarketDepthService
{
    public const SETTING_JSON = 'market_depth_json';

    public const SETTING_AS_OF = 'market_depth_as_of';

    public const CACHE_PREFIX = 'market_depth_matrix:';

    /** Stored snapshot scope — full matrix. */
    public const SCOPE_ALL = 'all';

    public const SCOPE_NSE = 'nse';

    public const SCOPE_BSE = 'bse';

    /** @var list<int> */
    public const SMA_PERIODS = [20, 50, 100, 200];

    public function __construct(
        protected IndexConstituentService $constituents,
        protected IndexCatalogService $catalog,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function forDashboard(): ?array
    {
        $cached = $this->readSettingsCache();
        if ($cached !== null) {
            return $this->normalizePayload($cached);
        }

        return $this->snapshotPayload(null);
    }

    /**
     * @return array{
     *   available_dates: list<string>,
     *   as_of_date: string|null,
     *   exchange_scope: string,
     *   matrix: array<string, mixed>|null,
     *   chart_symbol: string,
     *   chart_history: list<array<string, mixed>>
     * }
     */
    public function pagePayload(?string $asOfDate, string $exchangeFilter): array
    {
        $filter = self::SCOPE_NSE;
        $dates = $this->availableDates();
        $asOf = $asOfDate;
        if ($asOf === null || $asOf === '' || ! in_array($asOf, $dates, true)) {
            $asOf = $dates[0] ?? null;
        }

        $full = $asOf !== null ? $this->snapshotPayload($asOf) : null;
        if ($full === null && $asOf !== null) {
            $settings = $this->readSettingsCache();
            if ($settings !== null && ($settings['as_of_date'] ?? null) === $asOf) {
                $full = $this->normalizePayload($settings);
            }
        }

        $matrix = $full !== null ? $this->filterMatrixByExchange($full, $filter) : null;
        $chartSymbol = 'NIFTY50';

        return [
            'available_dates' => $dates,
            'as_of_date' => $asOf,
            'exchange_scope' => $filter,
            'matrix' => $matrix,
            'chart_symbol' => $chartSymbol,
            'chart_history' => $this->indexHistory($chartSymbol),
            // BC alias for earlier FE
            'nifty50_history' => $this->indexHistory('NIFTY50'),
        ];
    }

    /**
     * Compute + persist latest as-of date; prune to retention window.
     *
     * @return array<string, mixed>|null
     */
    public function refreshLatest(bool $forceRefresh = true): ?array
    {
        if (! $forceRefresh) {
            $existing = $this->snapshotPayload(null);
            if ($existing !== null) {
                return $existing;
            }
        }

        $payload = $this->computeForAsOf(null);
        if ($payload === null) {
            return null;
        }

        $this->persistSnapshot($payload);
        $this->writeSettingsCache($payload);
        $this->pruneHistory();

        return $payload;
    }

    /**
     * @deprecated Prefer refreshLatest / backfillLastTradingDays
     *
     * @return array{nse: array<string, mixed>|null, bse: array<string, mixed>|null}
     */
    public function refreshAll(bool $forceRefresh = true): array
    {
        $payload = $this->refreshLatest($forceRefresh);

        return [
            'nse' => $payload !== null ? $this->filterMatrixByExchange($payload, self::SCOPE_NSE) : null,
            'bse' => $payload !== null ? $this->filterMatrixByExchange($payload, self::SCOPE_BSE) : null,
        ];
    }

    /**
     * @deprecated
     *
     * @return array<string, mixed>|null
     */
    public function matrix(bool $forceRefresh = false): ?array
    {
        return $this->refreshLatest($forceRefresh);
    }

    /**
     * Recompute matrix for each of the last N trading days (benchmark sessions).
     *
     * @return array{dates: list<string>, saved: int, failed: list<string>}
     */
    public function backfillLastTradingDays(?int $days = null): array
    {
        $days = max(1, min(7, $days ?? $this->historyRetentionDays()));
        $dates = $this->recentTradingDates($days);
        $saved = 0;
        $failed = [];

        foreach ($dates as $asOf) {
            try {
                $payload = $this->computeForAsOf($asOf);
                if ($payload === null) {
                    $failed[] = $asOf;
                    continue;
                }
                $this->persistSnapshot($payload);
                $saved++;
            } catch (\Throwable) {
                $failed[] = $asOf;
            }
        }

        $latest = $this->snapshotPayload(null);
        if ($latest !== null) {
            $this->writeSettingsCache($latest);
        }
        $this->pruneHistory();

        return [
            'dates' => $dates,
            'saved' => $saved,
            'failed' => $failed,
        ];
    }

    /**
     * @return list<string>
     */
    public function availableDates(): array
    {
        if (! Schema::hasTable('portfolio_market_depth_snapshots')) {
            $asOf = Setting::getValue(self::SETTING_AS_OF);

            return is_string($asOf) && $asOf !== '' ? [$asOf] : [];
        }

        return MarketDepthSnapshot::query()
            ->select('as_of_date')
            ->distinct()
            ->orderByDesc('as_of_date')
            ->limit($this->historyRetentionDays())
            ->pluck('as_of_date')
            ->map(fn ($d) => is_string($d) ? $d : $d->toDateString())
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    public function configuredIndexes(): array
    {
        $raw = config('portfolio.market_depth.indexes');

        if ($raw === null || $raw === [] || $raw === ['*'] || $raw === '*') {
            return $this->allBreadthIndexes();
        }
        if (! is_array($raw)) {
            return $this->allBreadthIndexes();
        }

        $out = [];
        foreach ($raw as $sym) {
            if (! is_string($sym) || trim($sym) === '') {
                continue;
            }
            $sym = strtoupper(trim($sym));
            if ($sym === '*') {
                return $this->allBreadthIndexes();
            }
            $def = $this->catalog->definitionForSymbol($sym);
            if ($def !== null && $this->isBreadthIndex($def)) {
                $out[] = $sym;
            }
        }

        return $out !== [] ? array_values(array_unique($out)) : $this->allBreadthIndexes();
    }

    /**
     * Enabled NSE broad/sector indexes with constituent support.
     *
     * @return list<string>
     */
    public function allBreadthIndexes(): array
    {
        $out = [];
        foreach ($this->catalog->enabledDefinitions() as $def) {
            if ($this->isBreadthIndex($def)) {
                $out[] = strtoupper((string) $def['symbol']);
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  array<string, mixed>  $def
     */
    public function isBreadthIndex(array $def): bool
    {
        $tier = (string) ($def['tier'] ?? 'broad');

        return in_array($tier, ['broad', 'sector'], true)
            && (($def['exchange'] ?? null) === 'NSE')
            && $this->catalog->supportsConstituents($def);
    }

    /**
     * @param  list<float>  $closes
     * @param  list<float>  $benchCloses
     * @param  list<int>  $smaPeriods
     * @return array<string, bool|null>
     */
    public function evaluateStock(
        array $closes,
        array $benchCloses,
        array $smaPeriods,
        int $rsSessions,
    ): array {
        $n = count($closes);
        $last = $n > 0 ? $closes[$n - 1] : null;
        $out = ['rising' => null];

        if ($last !== null && $n >= 2) {
            $out['rising'] = $last > $closes[$n - 2];
        }

        foreach ($smaPeriods as $period) {
            $key = 'above_sma_'.$period;
            if ($last === null || $n < $period || $period < 1) {
                $out[$key] = null;
                continue;
            }
            $window = array_slice($closes, -$period);
            $sma = array_sum($window) / $period;
            $out[$key] = $last > $sma;
        }

        $need = $rsSessions + 1;
        $bn = count($benchCloses);
        if ($n < $need || $bn < $need) {
            $out['rs_55_positive'] = null;

            return $out;
        }

        $s0 = $closes[$n - $need];
        $s1 = $closes[$n - 1];
        $b0 = $benchCloses[$bn - $need];
        $b1 = $benchCloses[$bn - 1];
        if ($s0 <= 0.0 || $b0 <= 0.0) {
            $out['rs_55_positive'] = null;

            return $out;
        }

        $out['rs_55_positive'] = (($s1 - $s0) / $s0 - ($b1 - $b0) / $b0) > 0.0;

        return $out;
    }

    public function pct(int $pass, int $scanned): ?int
    {
        if ($scanned <= 0) {
            return null;
        }

        return (int) round(($pass / $scanned) * 100);
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    public function columnDefinitions(): array
    {
        $cols = [
            ['key' => 'rising', 'label' => 'Rising'],
            ['key' => 'rs_55_positive', 'label' => 'RS 55 > 0'],
        ];
        foreach (self::SMA_PERIODS as $period) {
            $cols[] = [
                'key' => 'above_sma_'.$period,
                'label' => 'Above SMA '.$period,
            ];
        }

        return $cols;
    }

    /**
     * @param  string|null  $asOfDate  Y-m-d or null = latest benchmark close
     * @return array<string, mixed>|null
     */
    public function computeForAsOf(?string $asOfDate): ?array
    {
        $indexes = $this->configuredIndexes();
        if ($indexes === []) {
            return null;
        }

        $rsSessions = max(1, (int) config('portfolio.market_depth.rs_sessions', 55));
        $historyDays = max(250, (int) config('portfolio.market_depth.history_calendar_days', 400));
        $fromDate = now()->subDays($historyDays)->toDateString();

        $membership = [];
        $allStockIds = [];

        foreach ($indexes as $sym) {
            $def = $this->catalog->definitionForSymbol($sym);
            $rows = ($def !== null && $this->catalog->supportsConstituents($def))
                ? $this->constituents->constituentsForSymbol($sym)
                : [];
            $ids = [];
            foreach ($rows as $row) {
                $id = $row['stock_id'] ?? null;
                if ($id !== null && (int) $id > 0) {
                    $ids[] = (int) $id;
                }
            }
            $ids = array_values(array_unique($ids));
            $membership[$sym] = [
                'list_count' => count($rows),
                'stock_ids' => $ids,
                'exchange' => strtoupper((string) ($def['exchange'] ?? 'NSE')),
                'name' => is_array($def) ? (string) ($def['name'] ?? $sym) : $sym,
            ];
            foreach ($ids as $id) {
                $allStockIds[$id] = true;
            }
        }

        $benchmark = $this->catalog->primaryBenchmarkStock();
        $allStockIds[(int) $benchmark->id] = true;

        $stockIdList = array_map('intval', array_keys($allStockIds));
        if ($stockIdList === []) {
            return null;
        }

        $seriesByStock = $this->loadCloseSeriesByStock($stockIdList, $fromDate);
        $resolvedAsOf = $asOfDate;
        if ($resolvedAsOf === null || $resolvedAsOf === '') {
            $resolvedAsOf = $this->latestCloseDate((int) $benchmark->id);
        }
        if ($resolvedAsOf === null) {
            return null;
        }

        $benchCloses = $this->closesThrough(
            $seriesByStock[(int) $benchmark->id] ?? [],
            $resolvedAsOf,
        );
        $columns = $this->columnDefinitions();
        $metricKeys = array_column($columns, 'key');

        $flagsByStock = [];
        foreach ($seriesByStock as $stockId => $series) {
            if ((int) $stockId === (int) $benchmark->id) {
                continue;
            }
            $closes = $this->closesThrough($series, $resolvedAsOf);
            if ($closes === []) {
                continue;
            }
            $flagsByStock[(int) $stockId] = $this->evaluateStock(
                $closes,
                $benchCloses,
                self::SMA_PERIODS,
                $rsSessions,
            );
        }

        $rows = [];
        foreach ($indexes as $sym) {
            $meta = $membership[$sym];
            $ids = $meta['stock_ids'];
            $pass = array_fill_keys($metricKeys, 0);
            $scanned = array_fill_keys($metricKeys, 0);

            foreach ($ids as $id) {
                $flags = $flagsByStock[$id] ?? null;
                if ($flags === null) {
                    continue;
                }
                foreach ($metricKeys as $key) {
                    $val = $flags[$key] ?? null;
                    if ($val === null) {
                        continue;
                    }
                    $scanned[$key]++;
                    if ($val === true) {
                        $pass[$key]++;
                    }
                }
            }

            $row = [
                'symbol' => $sym,
                'name' => $meta['name'],
                'exchange' => $meta['exchange'],
                'constituents' => (int) $meta['list_count'],
                'eligible' => count($ids),
            ];
            foreach ($metricKeys as $key) {
                $row['pass_'.$key] = $pass[$key];
                $row['scanned_'.$key] = $scanned[$key];
                $row['pct_'.$key] = $this->pct($pass[$key], $scanned[$key]);
            }
            $rows[] = $row;
        }

        return [
            'as_of_date' => $resolvedAsOf,
            'exchange_scope' => self::SCOPE_ALL,
            'benchmark_symbol' => (string) $benchmark->symbol,
            'title' => 'Market Breadth',
            'columns' => $columns,
            'rows' => $rows,
        ];
    }

    /**
     * @return list<string> Y-m-d descending then reversed to chronological for processing
     */
    public function recentTradingDates(int $limit): array
    {
        $benchmark = $this->catalog->primaryBenchmarkStock();
        $dates = StockPrice::query()
            ->where('stock_id', $benchmark->id)
            ->orderByDesc('price_date')
            ->limit($limit)
            ->pluck('price_date')
            ->map(fn ($d) => is_string($d) ? $d : $d->toDateString())
            ->values()
            ->all();

        return array_values(array_reverse($dates));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function filterMatrixByExchange(array $payload, string $exchangeFilter): array
    {
        $filter = $this->normalizeExchangeFilter($exchangeFilter);
        $want = strtoupper($filter);
        $rows = [];
        foreach ($payload['rows'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $ex = strtoupper((string) ($row['exchange'] ?? ''));
            if ($ex === '') {
                $def = $this->catalog->definitionForSymbol((string) ($row['symbol'] ?? ''));
                $ex = strtoupper((string) ($def['exchange'] ?? 'NSE'));
                $row['exchange'] = $ex;
            }
            if ($ex === $want) {
                $rows[] = $row;
            }
        }
        $payload['rows'] = $rows;
        $payload['exchange_scope'] = $filter;

        return $payload;
    }

    /**
     * @return list<array{date: string, metrics: array<string, array{pass: int, scanned: int, pct: int|null}>}>
     */
    protected function indexHistory(string $indexSymbol): array
    {
        if (! Schema::hasTable('portfolio_market_depth_snapshots')) {
            return [];
        }

        $snapshots = MarketDepthSnapshot::query()
            ->whereIn('exchange_scope', [self::SCOPE_ALL, self::SCOPE_NSE, self::SCOPE_BSE])
            ->orderByDesc('as_of_date')
            ->limit($this->historyRetentionDays() * 3)
            ->get()
            ->unique(fn ($s) => is_string($s->as_of_date) ? $s->as_of_date : $s->as_of_date->toDateString());

        $series = [];
        foreach ($snapshots->take($this->historyRetentionDays()) as $snap) {
            $payload = $snap->decodedPayload();
            if ($payload === null) {
                continue;
            }
            $payload = $this->normalizePayload($payload);
            $row = null;
            foreach ($payload['rows'] ?? [] as $r) {
                if (($r['symbol'] ?? '') === $indexSymbol) {
                    $row = $r;
                    break;
                }
            }
            if ($row === null) {
                continue;
            }
            $metrics = [];
            foreach ($payload['columns'] ?? [] as $col) {
                $key = $col['key'];
                $metrics[$key] = [
                    'pass' => (int) ($row['pass_'.$key] ?? 0),
                    'scanned' => (int) ($row['scanned_'.$key] ?? 0),
                    'pct' => $row['pct_'.$key] ?? null,
                ];
            }
            $date = is_string($snap->as_of_date)
                ? $snap->as_of_date
                : $snap->as_of_date->toDateString();
            $series[] = [
                'date' => $date,
                'metrics' => $metrics,
            ];
        }

        usort($series, fn ($a, $b) => strcmp($a['date'], $b['date']));

        return $series;
    }

    protected function snapshotPayload(?string $asOfDate): ?array
    {
        if (! Schema::hasTable('portfolio_market_depth_snapshots')) {
            return null;
        }

        $q = MarketDepthSnapshot::query()
            ->whereIn('exchange_scope', [self::SCOPE_ALL, self::SCOPE_NSE, self::SCOPE_BSE]);
        if ($asOfDate !== null && $asOfDate !== '') {
            $q->whereDate('as_of_date', $asOfDate);
        } else {
            $q->orderByDesc('as_of_date');
        }

        // Prefer unified "all" snapshot when multiple scopes exist for same date.
        $snaps = $q->get();
        $snap = $snaps->firstWhere('exchange_scope', self::SCOPE_ALL) ?? $snaps->first();
        if ($snap === null) {
            return null;
        }

        $payload = $snap->decodedPayload();

        return $payload !== null ? $this->normalizePayload($payload) : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function normalizePayload(array $payload): array
    {
        $payload['columns'] = $this->columnDefinitions();
        $payload['title'] = 'Market Breadth';
        if (! isset($payload['exchange_scope'])) {
            $payload['exchange_scope'] = self::SCOPE_ALL;
        }

        foreach ($payload['rows'] ?? [] as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            if (empty($row['exchange'])) {
                $def = $this->catalog->definitionForSymbol((string) ($row['symbol'] ?? ''));
                $row['exchange'] = strtoupper((string) ($def['exchange'] ?? 'NSE'));
            }
            foreach ($payload['columns'] as $col) {
                $key = $col['key'];
                if (! isset($row['pass_'.$key]) && isset($row['pct_'.$key], $row['scanned_'.$key])) {
                    $scanned = (int) $row['scanned_'.$key];
                    $pct = $row['pct_'.$key];
                    $row['pass_'.$key] = ($pct === null || $scanned <= 0)
                        ? 0
                        : (int) round(((int) $pct / 100) * $scanned);
                }
            }
            $payload['rows'][$i] = $row;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function persistSnapshot(array $payload): void
    {
        if (! Schema::hasTable('portfolio_market_depth_snapshots')) {
            return;
        }
        $asOf = is_string($payload['as_of_date'] ?? null)
            ? $payload['as_of_date']
            : now()->toDateString();

        MarketDepthSnapshot::query()->updateOrCreate(
            [
                'as_of_date' => $asOf,
                'exchange_scope' => self::SCOPE_ALL,
            ],
            [
                'payload_json' => json_encode($payload),
            ],
        );
    }

    protected function pruneHistory(): void
    {
        if (! Schema::hasTable('portfolio_market_depth_snapshots')) {
            return;
        }
        $keep = $this->historyRetentionDays();
        $keepDates = MarketDepthSnapshot::query()
            ->select('as_of_date')
            ->distinct()
            ->orderByDesc('as_of_date')
            ->limit($keep)
            ->pluck('as_of_date')
            ->map(fn ($d) => is_string($d) ? $d : $d->toDateString())
            ->all();

        if ($keepDates === []) {
            return;
        }

        MarketDepthSnapshot::query()
            ->whereNotIn('as_of_date', $keepDates)
            ->delete();
    }

    protected function historyRetentionDays(): int
    {
        return max(1, min(7, (int) config('portfolio.market_depth.history_retention_days', 7)));
    }

    protected function normalizeExchangeFilter(string $scope): string
    {
        $scope = strtolower(trim($scope));

        return $scope === self::SCOPE_BSE ? self::SCOPE_BSE : self::SCOPE_NSE;
    }

    /**
     * @param  list<int>  $stockIds
     * @return array<int, list<array{date: string, close: float}>>
     */
    protected function loadCloseSeriesByStock(array $stockIds, string $fromDate): array
    {
        $out = [];
        foreach (array_chunk($stockIds, 150) as $chunk) {
            $rows = StockPrice::query()
                ->whereIn('stock_id', $chunk)
                ->where('price_date', '>=', $fromDate)
                ->orderBy('stock_id')
                ->orderBy('price_date')
                ->get(['stock_id', 'price_date', 'close_price', 'adjusted_close_price']);

            foreach ($rows as $row) {
                $close = $row->adjusted_close_price ?? $row->close_price;
                if ($close === null) {
                    continue;
                }
                $date = $row->price_date;
                $dateStr = is_string($date) ? $date : $date->toDateString();
                $out[(int) $row->stock_id][] = [
                    'date' => $dateStr,
                    'close' => (float) $close,
                ];
            }
        }

        return $out;
    }

    /**
     * @param  list<array{date: string, close: float}>  $series
     * @return list<float>
     */
    protected function closesThrough(array $series, string $asOfDate): array
    {
        $closes = [];
        foreach ($series as $point) {
            if ($point['date'] <= $asOfDate) {
                $closes[] = $point['close'];
            }
        }

        return $closes;
    }

    protected function latestCloseDate(int $benchmarkId): ?string
    {
        $date = StockPrice::query()
            ->where('stock_id', $benchmarkId)
            ->orderByDesc('price_date')
            ->value('price_date');

        if ($date === null) {
            return null;
        }

        return is_string($date) ? $date : $date->toDateString();
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function readSettingsCache(): ?array
    {
        $asOfSetting = Setting::getValue(self::SETTING_AS_OF);
        $cacheKey = self::CACHE_PREFIX.($asOfSetting ?: 'latest');
        $fromCache = Cache::get($cacheKey);
        if (is_array($fromCache) && isset($fromCache['rows'])) {
            return $fromCache;
        }

        $raw = Setting::getValue(self::SETTING_JSON);
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded) || ! isset($decoded['rows'])) {
            return null;
        }

        $ttl = max(300, (int) config('portfolio.market_depth.cache_ttl_seconds', 21600));
        Cache::put($cacheKey, $decoded, $ttl);

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function writeSettingsCache(array $payload): void
    {
        $asOf = is_string($payload['as_of_date'] ?? null) ? $payload['as_of_date'] : now()->toDateString();
        $ttl = max(300, (int) config('portfolio.market_depth.cache_ttl_seconds', 21600));
        Cache::put(self::CACHE_PREFIX.$asOf, $payload, $ttl);
        Setting::setValue(self::SETTING_JSON, json_encode($payload));
        Setting::setValue(self::SETTING_AS_OF, $asOf);
    }
}
