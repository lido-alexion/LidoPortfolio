<?php

namespace App\Services\Analytics;

use App\Models\Setting;
use App\Models\StockPrice;
use App\Services\IndexCatalogService;
use App\Services\IndexConstituentService;
use Illuminate\Support\Facades\Cache;

/**
 * Index × criterion breadth matrix ("% of constituents above …").
 * Cached daily — Nifty 500 + SMA200 is too expensive for every dashboard hit.
 */
class MarketDepthService
{
    public const SETTING_JSON = 'market_depth_json';

    public const SETTING_AS_OF = 'market_depth_as_of';

    public const CACHE_PREFIX = 'market_depth_matrix:';

    /** @var list<int> */
    public const SMA_PERIODS = [20, 50, 100, 200];

    public function __construct(
        protected IndexConstituentService $constituents,
        protected IndexCatalogService $catalog,
    ) {}

    /**
     * @return array{
     *   as_of_date: string|null,
     *   benchmark_symbol: string,
     *   title: string,
     *   columns: list<array{key: string, label: string}>,
     *   rows: list<array<string, mixed>>
     * }|null
     */
    public function matrix(bool $forceRefresh = false): ?array
    {
        $indexes = $this->configuredIndexes();
        if ($indexes === []) {
            return null;
        }

        if (! $forceRefresh) {
            $cached = $this->readCache();
            if ($cached !== null) {
                return $cached;
            }
        }

        $payload = $this->compute($indexes);
        if ($payload !== null) {
            $this->writeCache($payload);
        }

        return $payload;
    }

    /**
     * @return list<string>
     */
    public function configuredIndexes(): array
    {
        $raw = config('portfolio.market_depth.indexes', [
            'NIFTY50',
            'NIFTY500',
            'NIFTYBANK',
            'NIFTYFINSERVICE',
            'NIFTYMIDCAP50',
        ]);
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $sym) {
            if (! is_string($sym) || trim($sym) === '') {
                continue;
            }
            $out[] = strtoupper(trim($sym));
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  list<string>  $indexSymbols
     * @return array<string, mixed>|null
     */
    protected function compute(array $indexSymbols): ?array
    {
        $rsSessions = max(1, (int) config('portfolio.market_depth.rs_sessions', 55));
        $historyDays = max(250, (int) config('portfolio.market_depth.history_calendar_days', 400));
        $fromDate = now()->subDays($historyDays)->toDateString();

        $membership = [];
        $allStockIds = [];

        foreach ($indexSymbols as $sym) {
            $rows = $this->constituents->constituentsForSymbol($sym);
            $ids = [];
            foreach ($rows as $row) {
                $id = $row['stock_id'] ?? null;
                if ($id !== null && (int) $id > 0) {
                    $ids[] = (int) $id;
                }
            }
            $ids = array_values(array_unique($ids));
            $membership[$sym] = [
                'constituents' => count($rows),
                'stock_ids' => $ids,
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

        $closesByStock = $this->loadClosesByStock($stockIdList, $fromDate);
        $benchCloses = $closesByStock[(int) $benchmark->id] ?? [];

        $flagsByStock = [];
        foreach ($closesByStock as $stockId => $closes) {
            if ((int) $stockId === (int) $benchmark->id) {
                continue;
            }
            $flagsByStock[(int) $stockId] = $this->evaluateStock(
                $closes,
                $benchCloses,
                self::SMA_PERIODS,
                $rsSessions,
            );
        }

        $columns = [
            ['key' => 'rs_55_positive', 'label' => 'RS 55 > 0'],
        ];
        foreach (self::SMA_PERIODS as $period) {
            $columns[] = [
                'key' => 'above_sma_'.$period,
                'label' => 'SMA '.$period,
            ];
        }

        $rows = [];
        foreach ($indexSymbols as $sym) {
            $def = $this->catalog->definitionForSymbol($sym);
            $ids = $membership[$sym]['stock_ids'] ?? [];
            $metricKeys = array_column($columns, 'key');
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
                'name' => is_array($def) ? (string) ($def['name'] ?? $sym) : $sym,
                'constituents' => (int) ($membership[$sym]['constituents'] ?? 0),
            ];
            foreach ($metricKeys as $key) {
                $row['pct_'.$key] = $this->pct($pass[$key], $scanned[$key]);
                $row['scanned_'.$key] = $scanned[$key];
            }
            $rows[] = $row;
        }

        return [
            'as_of_date' => $this->latestCloseDate((int) $benchmark->id),
            'benchmark_symbol' => (string) $benchmark->symbol,
            'title' => 'Stocks Above',
            'columns' => $columns,
            'rows' => $rows,
        ];
    }

    /**
     * @param  list<float>  $closes  ascending (oldest → newest)
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
        $out = [];

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

        $stockRet = ($s1 - $s0) / $s0;
        $benchRet = ($b1 - $b0) / $b0;
        $out['rs_55_positive'] = ($stockRet - $benchRet) > 0.0;

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
     * @param  list<int>  $stockIds
     * @return array<int, list<float>>
     */
    protected function loadClosesByStock(array $stockIds, string $fromDate): array
    {
        $out = [];
        foreach (array_chunk($stockIds, 150) as $chunk) {
            $rows = StockPrice::query()
                ->whereIn('stock_id', $chunk)
                ->where('price_date', '>=', $fromDate)
                ->orderBy('stock_id')
                ->orderBy('price_date')
                ->get(['stock_id', 'close_price', 'adjusted_close_price']);

            foreach ($rows as $row) {
                $close = $row->adjusted_close_price ?? $row->close_price;
                if ($close === null) {
                    continue;
                }
                $out[(int) $row->stock_id][] = (float) $close;
            }
        }

        return $out;
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
    protected function readCache(): ?array
    {
        $asOfSetting = Setting::getValue(self::SETTING_AS_OF);
        $benchmark = $this->catalog->primaryBenchmarkStock();
        $latest = StockPrice::query()
            ->where('stock_id', $benchmark->id)
            ->orderByDesc('price_date')
            ->value('price_date');
        $latestStr = $latest === null
            ? null
            : (is_string($latest) ? $latest : $latest->toDateString());

        if ($asOfSetting && $latestStr && $asOfSetting < $latestStr) {
            return null;
        }

        $cacheKey = self::CACHE_PREFIX.($asOfSetting ?: ($latestStr ?? 'unknown'));
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
    protected function writeCache(array $payload): void
    {
        $asOf = is_string($payload['as_of_date'] ?? null) ? $payload['as_of_date'] : now()->toDateString();
        $ttl = max(300, (int) config('portfolio.market_depth.cache_ttl_seconds', 21600));
        Cache::put(self::CACHE_PREFIX.$asOf, $payload, $ttl);
        Setting::setValue(self::SETTING_JSON, json_encode($payload));
        Setting::setValue(self::SETTING_AS_OF, $asOf);
    }
}
