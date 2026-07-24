<?php

namespace App\Services;

use App\Models\Stock;
use App\Models\StockPrice;
use App\Support\TradingCalendar;
use Carbon\Carbon;
class StockPriceHistoryService
{
    public function __construct(
        protected PortfolioLoggerService $portfolioLogger,
        protected IgnoredPriceGapService $ignoredGaps,
    ) {}

    protected function priceFetch(): PriceFetchService
    {
        return app(PriceFetchService::class);
    }

    /**
     * @return array{from: Carbon, to: Carbon}|null
     */
    public function getAvailableHistoryRange(Stock $stock): ?array
    {
        $min = StockPrice::query()->where('stock_id', $stock->id)->min('price_date');
        $max = StockPrice::query()->where('stock_id', $stock->id)->max('price_date');

        if (! $min || ! $max) {
            return null;
        }

        return [
            'from' => Carbon::parse($min)->startOfDay(),
            'to' => Carbon::parse($max)->startOfDay(),
        ];
    }

    /**
     * @param  bool  $includePreListingPrefix  Report the prefix edge gap even when it looks
     *                                         pre-listing (history-depth backfill: providers
     *                                         simply return nothing before the listing date).
     * @return array<int, array{from: Carbon, to: Carbon}>
     */
    public function getMissingHistoryRanges(
        Stock $stock,
        Carbon $requiredFrom,
        Carbon $requiredTo,
        bool $includePreListingPrefix = false,
    ): array {
        $requiredFrom = $requiredFrom->copy()->startOfDay();
        $requiredTo = $requiredTo->copy()->startOfDay();

        if ($requiredFrom->gt($requiredTo)) {
            return [];
        }

        $available = $this->getAvailableHistoryRange($stock);

        if ($available === null) {
            return $this->ignoredGaps->filterRanges((int) $stock->id, [
                ['from' => $requiredFrom, 'to' => $requiredTo],
            ]);
        }

        $ranges = [];

        if ($requiredFrom->lt($available['from'])) {
            $prefixTo = $available['from']->copy()->subDay();
            if ($includePreListingPrefix
                || ! $this->isPreListingPrefixGap($stock, $requiredFrom, $prefixTo, $available['from'])) {
                $ranges[] = [
                    'from' => $requiredFrom,
                    'to' => $prefixTo,
                ];
            }
        }

        // Suffix edge gaps (missing days after last stored price through required_through)
        // are ignored — universe daily sync covers recent sessions.

        $ranges = $this->filterEdgeGapsByMinSpan($ranges);
        $ranges = array_values(array_filter($ranges, fn (array $range) => $range['from']->lte($range['to'])));

        $internalGaps = $this->detectInternalGaps($stock, $requiredFrom, $requiredTo);
        foreach ($internalGaps as $gap) {
            $ranges[] = $gap;
        }

        $ranges = $this->mergeAdjacentRanges($ranges);

        return $this->ignoredGaps->filterRanges((int) $stock->id, $ranges);
    }

    /**
     * @return array{
     *   success: bool,
     *   cache_hit: bool,
     *   stored_rows: int,
     *   fetched_rows: int,
     *   ranges_fetched: array<int, array{from: string, to: string, provider: string}>,
     *   gaps_remaining: int,
     *   attempted_ranges: array<int, array{from: string, to: string}>,
     *   remaining_ranges: array<int, array{from: string, to: string}>,
     *   providers_tried: array<int, string>,
     *   range_results: array<int, array{from: string, to: string, provider: string, providers_tried: array<int, string>, errors: array<int, string>, stored_rows: int}>,
     *   errors: array<int, string>
     * }
     */
    public function fetchMissingHistory(
        Stock $stock,
        Carbon $requiredFrom,
        Carbon $requiredTo,
        bool $notifyTelegramOnFailure = true,
        bool $includePreListingPrefix = false,
    ): array
    {
        $started = microtime(true);
        $missingRanges = $this->getMissingHistoryRanges($stock, $requiredFrom, $requiredTo, $includePreListingPrefix);

        if ($missingRanges === []) {
            $this->portfolioLogger->api('info', 'Historical cache hit — no fetch required', [
                'category' => 'History',
                'symbol' => $stock->symbol,
                'exchange' => $stock->exchange,
                'required_from' => $requiredFrom->toDateString(),
                'required_to' => $requiredTo->toDateString(),
                'cache_source' => 'local',
                'duration_ms' => (int) ((microtime(true) - $started) * 1000),
            ]);

            return [
                'success' => true,
                'cache_hit' => true,
                'stored_rows' => 0,
                'fetched_rows' => 0,
                'ranges_fetched' => [],
                'gaps_remaining' => 0,
                'attempted_ranges' => [],
                'remaining_ranges' => [],
                'providers_tried' => [],
                'range_results' => [],
                'errors' => [],
            ];
        }

        $this->portfolioLogger->api('info', 'Historical cache miss — fetching missing ranges', [
            'category' => 'History',
            'symbol' => $stock->symbol,
            'missing_ranges' => collect($missingRanges)->map(fn ($r) => [
                'from' => $r['from']->toDateString(),
                'to' => $r['to']->toDateString(),
            ])->all(),
        ]);

        $storedTotal = 0;
        $fetchedTotal = 0;
        $rangesFetched = [];
        $rangeResults = [];
        $errors = [];
        $providersTried = [];
        $attemptedRanges = $this->serializeDateRanges($missingRanges);

        foreach ($missingRanges as $range) {
            $fetch = $this->priceFetch();
            $rangeErrors = [];
            $rangeProvidersTried = [];
            $rangeStored = 0;
            $rangeFetched = 0;
            $lastProvider = 'none';

            foreach ($fetch->providerChainForStock($stock) as $providerName) {
                if ($providerName === 'bse_bhavcopy' && $this->shouldSkipBseBhavcopyForRange($stock, $range['from'], $range['to'])) {
                    $rangeErrors[] = $this->bseBhavcopySkipReason($stock, $range['from'], $range['to']);
                    continue;
                }

                for ($attempt = 1; $attempt <= 2; $attempt++) {
                    $attemptResult = $fetch->fetchFromProvider(
                        $providerName,
                        $stock->symbol,
                        $range['from'],
                        $range['to'],
                        $stock,
                    );

                    if (! in_array($providerName, $rangeProvidersTried, true)) {
                        $rangeProvidersTried[] = $providerName;
                    }

                    $rangeErrors = array_merge($rangeErrors, $attemptResult['errors'] ?? []);

                    if (($attemptResult['rows'] ?? []) === []) {
                        continue;
                    }

                    $lastProvider = $providerName;
                    $rangeFetched += count($attemptResult['rows']);
                    $rangeStored += $fetch->storeHistoricalRows($stock, $attemptResult['rows'], $providerName);
                    break;
                }

                if ($this->getMissingHistoryRanges($stock, $range['from'], $range['to']) === []) {
                    break;
                }
            }

            $providersTried = array_values(array_unique(array_merge($providersTried, $rangeProvidersTried)));

            if ($this->getMissingHistoryRanges($stock, $range['from'], $range['to']) !== []) {
                if ($rangeErrors === [] && $rangeStored > 0) {
                    $rangeErrors[] = $lastProvider.': stored '.$rangeStored.' rows but gap remains for '
                        .$range['from']->toDateString().'→'.$range['to']->toDateString();
                } elseif ($rangeErrors === []) {
                    $rangeErrors[] = 'No provider returned usable OHLCV for '
                        .$range['from']->toDateString().'→'.$range['to']->toDateString();
                }
            }

            $storedTotal += $rangeStored;
            $fetchedTotal += $rangeFetched;
            $errors = array_merge($errors, $rangeErrors);

            if ($rangeStored > 0) {
                $rangesFetched[] = [
                    'from' => $range['from']->toDateString(),
                    'to' => $range['to']->toDateString(),
                    'provider' => $lastProvider,
                ];
            }

            $rangeResults[] = [
                'from' => $range['from']->toDateString(),
                'to' => $range['to']->toDateString(),
                'provider' => $lastProvider,
                'providers_tried' => $rangeProvidersTried,
                'errors' => $rangeErrors,
                'stored_rows' => $rangeStored,
            ];
        }

        $stillMissing = $this->getMissingHistoryRanges($stock, $requiredFrom, $requiredTo);
        $remainingRanges = $this->serializeDateRanges($stillMissing);

        $this->portfolioLogger->api('info', 'Historical missing ranges fetch completed', [
            'category' => 'History',
            'symbol' => $stock->symbol,
            'stored_rows' => $storedTotal,
            'fetched_rows' => $fetchedTotal,
            'ranges_fetched' => $rangesFetched,
            'gaps_remaining' => count($stillMissing),
            'duration_ms' => (int) ((microtime(true) - $started) * 1000),
        ]);

        if ($stillMissing !== [] && $errors === [] && $storedTotal === 0 && $fetchedTotal === 0) {
            $errors[] = 'Provider returned no OHLCV rows for the missing range(s).';
        }

        return [
            'success' => $stillMissing === [],
            'cache_hit' => false,
            'stored_rows' => $storedTotal,
            'fetched_rows' => $fetchedTotal,
            'ranges_fetched' => $rangesFetched,
            'gaps_remaining' => count($stillMissing),
            'attempted_ranges' => $attemptedRanges,
            'remaining_ranges' => $remainingRanges,
            'providers_tried' => $providersTried,
            'range_results' => $rangeResults,
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<int, array{from: Carbon, to: Carbon}>  $ranges
     * @return array<int, array{from: string, to: string}>
     */
    protected function serializeDateRanges(array $ranges): array
    {
        return array_map(static fn (array $range) => [
            'from' => $range['from']->toDateString(),
            'to' => $range['to']->toDateString(),
        ], $ranges);
    }

    public function ensurePortfolioHistory(Stock $stock, Carbon $buyDate): array
    {
        $requiredFrom = $buyDate->copy()->subMonths(3)->startOfDay();
        $requiredTo = now()->startOfDay();

        return $this->fetchMissingHistory($stock, $requiredFrom, $requiredTo);
    }

    public function ensureAnalyticsHistory(Stock $stock, int $maxMonths = 3): array
    {
        $bufferKey = $maxMonths.'m';
        $bufferDays = config('portfolio.history.analytics_buffer_days.'.$bufferKey)
            ?? ($maxMonths >= 3 ? 150 : 60);

        $requiredFrom = now()->subDays((int) $bufferDays)->startOfDay();
        $requiredTo = now()->startOfDay();

        return $this->fetchMissingHistory($stock, $requiredFrom, $requiredTo);
    }

    /**
     * Read-only cache status for analytics (no provider fetch). Used by Explorer, which
     * relies on universe price sync populating portfolio_stock_prices.
     *
     * @return array{
     *   success: bool,
     *   cache_hit: bool,
     *   stored_rows: int,
     *   fetched_rows: int,
     *   ranges_fetched: array<int, array{from: string, to: string, provider: string}>,
     *   errors: array<int, string>
     * }
     */
    public function getCachedAnalyticsHistoryStatus(Stock $stock, int $maxMonths = 6): array
    {
        $bufferKey = $maxMonths.'m';
        $bufferDays = config('portfolio.history.analytics_buffer_days.'.$bufferKey)
            ?? ($maxMonths >= 6 ? 210 : ($maxMonths >= 3 ? 150 : 60));

        $requiredFrom = now()->subDays((int) $bufferDays)->startOfDay();
        $requiredTo = now()->startOfDay();
        $missingRanges = $this->getMissingHistoryRanges($stock, $requiredFrom, $requiredTo);

        if ($missingRanges === []) {
            return [
                'success' => true,
                'cache_hit' => true,
                'stored_rows' => 0,
                'fetched_rows' => 0,
                'ranges_fetched' => [],
                'errors' => [],
            ];
        }

        return [
            'success' => false,
            'cache_hit' => false,
            'stored_rows' => 0,
            'fetched_rows' => 0,
            'ranges_fetched' => [],
            'errors' => ['Insufficient cached price history. Universe price sync may not have covered this symbol yet.'],
        ];
    }

    public function getLatestClose(Stock $stock, ?Carbon $asOf = null): ?float
    {
        $asOf = ($asOf ?? now())->copy()->startOfDay();

        return $this->getCloseOnOrBeforeDate($stock, $asOf);
    }

    public function getCloseOnOrBeforeDate(Stock $stock, Carbon $targetDate): ?float
    {
        $prices = StockPrice::query()
            ->where('stock_id', $stock->id)
            ->where('price_date', '<=', $targetDate->copy()->endOfDay())
            ->orderByDesc('price_date')
            ->limit(14)
            ->get(['price_date', 'close_price', 'adjusted_close_price']);

        foreach ($prices as $price) {
            $sessionDate = Carbon::parse($price->price_date)->startOfDay();
            if (! TradingCalendar::isEquitySessionDate($sessionDate)) {
                continue;
            }

            $close = $price->adjusted_close_price ?? $price->close_price;

            return $close !== null ? (float) $close : null;
        }

        return null;
    }

    public function getGrowthPercentage(Stock $stock, int $months, ?Carbon $asOf = null): ?float
    {
        $asOf = ($asOf ?? now())->copy()->startOfDay();
        $startTarget = $asOf->copy()->subMonths($months);

        return $this->growthBetween($stock, $startTarget, $asOf);
    }

    public function getGrowthPercentageForDays(Stock $stock, int $days, ?Carbon $asOf = null): ?float
    {
        $asOf = ($asOf ?? now())->copy()->startOfDay();
        $startTarget = $asOf->copy()->subDays(max(1, $days));

        return $this->growthBetween($stock, $startTarget, $asOf);
    }

    protected function growthBetween(Stock $stock, Carbon $startTarget, Carbon $asOf): ?float
    {
        $startClose = $this->getCloseOnOrBeforeDate($stock, $startTarget);
        $endClose = $this->getCloseOnOrBeforeDate($stock, $asOf);

        if ($startClose === null || $endClose === null || abs($startClose) < 0.000001) {
            return null;
        }

        return round((($endClose - $startClose) / $startClose) * 100, 4);
    }

    public function getRelativeStrength(Stock $stock, Stock $benchmark, int $months, ?Carbon $asOf = null): ?float
    {
        $stockReturn = $this->getGrowthPercentage($stock, $months, $asOf);
        $benchmarkReturn = $this->getGrowthPercentage($benchmark, $months, $asOf);

        if ($stockReturn === null || $benchmarkReturn === null) {
            return null;
        }

        return round($stockReturn - $benchmarkReturn, 4);
    }

    /**
     * Daily % gain vs the close at the start of the lookback window (same anchor as period growth).
     *
     * @return list<array{
     *   date: string,
     *   stock_gain_percent: float,
     *   benchmark_gain_percent: float|null
     * }>
     */
    public function getNormalizedGainSeries(
        Stock $stock,
        Stock $benchmark,
        int $months = 12,
        ?Carbon $asOf = null,
    ): array {
        $asOf = ($asOf ?? now())->copy()->startOfDay();
        $startTarget = $asOf->copy()->subMonths($months);

        $stockBase = $this->getCloseOnOrBeforeDate($stock, $startTarget);
        $benchmarkBase = $this->getCloseOnOrBeforeDate($benchmark, $startTarget);

        if ($stockBase === null || abs($stockBase) < 0.000001) {
            return [];
        }

        $stockRows = StockPrice::query()
            ->where('stock_id', $stock->id)
            ->where('price_date', '>=', $startTarget->toDateString())
            ->where('price_date', '<=', $asOf->toDateString())
            ->orderBy('price_date')
            ->get(['price_date', 'close_price', 'adjusted_close_price']);

        $benchmarkByDate = StockPrice::query()
            ->where('stock_id', $benchmark->id)
            ->where('price_date', '>=', $startTarget->toDateString())
            ->where('price_date', '<=', $asOf->toDateString())
            ->orderBy('price_date')
            ->get(['price_date', 'close_price', 'adjusted_close_price'])
            ->keyBy(fn ($row) => Carbon::parse($row->price_date)->toDateString());

        $series = [];

        foreach ($stockRows as $row) {
            $sessionDate = Carbon::parse($row->price_date)->startOfDay();
            if (! TradingCalendar::isEquitySessionDate($sessionDate)) {
                continue;
            }

            $dateKey = $sessionDate->toDateString();
            $stockClose = $row->adjusted_close_price ?? $row->close_price;
            if ($stockClose === null) {
                continue;
            }

            $stockClose = (float) $stockClose;
            $point = [
                'date' => $dateKey,
                'stock_gain_percent' => round((($stockClose - $stockBase) / $stockBase) * 100, 4),
                'benchmark_gain_percent' => null,
            ];

            if ($benchmarkBase !== null && abs($benchmarkBase) >= 0.000001) {
                $benchRow = $benchmarkByDate->get($dateKey);
                if ($benchRow !== null) {
                    $benchClose = $benchRow->adjusted_close_price ?? $benchRow->close_price;
                    if ($benchClose !== null) {
                        $point['benchmark_gain_percent'] = round(
                            (((float) $benchClose - $benchmarkBase) / $benchmarkBase) * 100,
                            4,
                        );
                    }
                }
            }

            $series[] = $point;
        }

        return $series;
    }

    /**
     * Daily % gain vs the close at the start of the lookback window.
     *
     * @return list<array{date: string, gain_percent: float}>
     */
    public function getNormalizedGainSeriesForStock(
        Stock $stock,
        int $months = 12,
        ?Carbon $asOf = null,
    ): array {
        $asOf = ($asOf ?? now())->copy()->startOfDay();
        $startTarget = $asOf->copy()->subMonths($months);
        $baseClose = $this->getCloseOnOrBeforeDate($stock, $startTarget);

        if ($baseClose === null || abs($baseClose) < 0.000001) {
            return [];
        }

        $rows = StockPrice::query()
            ->where('stock_id', $stock->id)
            ->where('price_date', '>=', $startTarget->toDateString())
            ->where('price_date', '<=', $asOf->toDateString())
            ->orderBy('price_date')
            ->get(['price_date', 'close_price', 'adjusted_close_price']);

        $series = [];
        foreach ($rows as $row) {
            $sessionDate = Carbon::parse($row->price_date)->startOfDay();
            if (! TradingCalendar::isEquitySessionDate($sessionDate)) {
                continue;
            }

            $close = $row->adjusted_close_price ?? $row->close_price;
            if ($close === null) {
                continue;
            }

            $series[] = [
                'date' => $sessionDate->toDateString(),
                'gain_percent' => round((((float) $close - $baseClose) / $baseClose) * 100, 4),
            ];
        }

        return $series;
    }

    /**
     * Prefix edge before the first stored session is usually pre-listing (IPO after universe
     * window start). Skip unless the user traded in that span or the stock master row predates
     * first OHLCV by many months (likely incomplete backfill).
     */
    protected function isPreListingPrefixGap(
        Stock $stock,
        Carbon $gapFrom,
        Carbon $gapTo,
        Carbon $firstStored,
    ): bool {
        if (! $gapTo->copy()->addDay()->startOfDay()->equalTo($firstStored->copy()->startOfDay())) {
            return false;
        }

        if ($stock->transactions()
            ->where('transaction_date', '>=', $gapFrom->toDateString())
            ->where('transaction_date', '<=', $gapTo->toDateString())
            ->exists()) {
            return false;
        }

        $createdAt = $stock->created_at;
        if ($createdAt instanceof Carbon
            && $createdAt->copy()->startOfDay()->lt($firstStored->copy()->subDays(180))) {
            return false;
        }

        return true;
    }

    protected function filterEdgeGapsByMinSpan(array $ranges): array
    {
        $maxGapDays = (int) config('portfolio.history.max_internal_gap_days', 7);

        return array_values(array_filter(
            $ranges,
            fn (array $range) => $range['from']->diffInDays($range['to']) > $maxGapDays,
        ));
    }

    /**
     * @return array<int, array{from: Carbon, to: Carbon}>
     */
    protected function detectInternalGaps(Stock $stock, Carbon $from, Carbon $to): array
    {
        $maxGapDays = (int) config('portfolio.history.max_internal_gap_days', 7);
        $dates = StockPrice::query()
            ->where('stock_id', $stock->id)
            ->where('price_date', '>=', $from->copy()->startOfDay())
            ->where('price_date', '<=', $to->copy()->endOfDay())
            ->orderBy('price_date')
            ->pluck('price_date')
            ->map(fn ($d) => Carbon::parse($d)->startOfDay());

        if ($dates->count() < 2) {
            return [];
        }

        $gaps = [];
        $previous = $dates->first();
        foreach ($dates->skip(1) as $current) {
            $diff = $previous->diffInDays($current);
            if ($diff > $maxGapDays) {
                $gaps[] = [
                    'from' => $previous->copy()->addDay(),
                    'to' => $current->copy()->subDay(),
                ];
            }
            $previous = $current;
        }

        return $gaps;
    }

    /**
     * @param  array<int, array{from: Carbon, to: Carbon}>  $ranges
     * @return array<int, array{from: Carbon, to: Carbon}>
     */
    protected function mergeAdjacentRanges(array $ranges): array
    {
        if ($ranges === []) {
            return [];
        }

        usort($ranges, fn ($a, $b) => $a['from']->timestamp <=> $b['from']->timestamp);

        $merged = [$ranges[0]];
        foreach (array_slice($ranges, 1) as $range) {
            $last = &$merged[count($merged) - 1];
            if ($range['from']->lte($last['to']->copy()->addDay())) {
                if ($range['to']->gt($last['to'])) {
                    $last['to'] = $range['to'];
                }
            } else {
                $merged[] = $range;
            }
        }

        return $merged;
    }

    protected function shouldSkipBseBhavcopyForRange(Stock $stock, Carbon $from, Carbon $to): bool
    {
        if (strtoupper((string) $stock->exchange) !== 'BSE') {
            return false;
        }

        if (! (bool) config('portfolio.universe_price_sync.bse_bhavcopy_gap_fill_enabled', false)) {
            return true;
        }

        $maxDays = (int) config('portfolio.universe_price_sync.bse_bhavcopy_max_gap_calendar_days', 45);

        return $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) > $maxDays;
    }

    protected function bseBhavcopySkipReason(Stock $stock, Carbon $from, Carbon $to): string
    {
        if (! (bool) config('portfolio.universe_price_sync.bse_bhavcopy_gap_fill_enabled', false)) {
            return 'bse_bhavcopy: disabled for gap fill (use cpanel-bse-bhavcopy-backfill.php for BSE history)';
        }

        return 'bse_bhavcopy: skipped (range exceeds '
            .(int) config('portfolio.universe_price_sync.bse_bhavcopy_max_gap_calendar_days', 45)
            .' calendar days — run portfolio:backfill-bse-bhavcopy)';
    }
}
