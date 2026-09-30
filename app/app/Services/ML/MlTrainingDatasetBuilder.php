<?php

namespace App\Services\ML;

use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\V7\FundamentalFact;
use App\Services\Fundamentals\FundamentalBankMetricsService;
use App\Services\Fundamentals\FundamentalDataService;
use Carbon\Carbon;
use RuntimeException;

class MlTrainingDatasetBuilder
{
    public const NUMERIC_FEATURES = [
        'relative_strength_1m',
        'relative_strength_3m',
        'relative_strength_6m',
        'benchmark_return_3m',
        'benchmark_return_6m',
        'benchmark_trend_score',
        'sector_relative_strength_3m',
        'market_breadth_nifty',
        'momentum_score',
        'price_return_1m',
        'price_return_3m',
        'price_return_6m',
        'price_return_12m',
        'trend_score',
        'trend_score_ma50',
        'trend_score_ma200',
        'benchmark_realized_volatility_20d',
        'roe',
        'debt_equity',
        'revenue_growth_proxy',
        'eps_growth_yoy',
        'net_income_growth_yoy',
        'operating_margin',
        'net_margin',
        'fcf_margin',
        'ocf_to_net_income_ratio',
        'pe_ratio',
        'pb_ratio',
        'realized_volatility_20d',
        'realized_volatility_63d',
        'volatility_ratio_20_63',
        'volume_trend_20d',
        'net_debt_equity',
        'current_drawdown_pct',
        'distance_52w_high_pct',
        'distance_20d_high_pct',
        'volume_ratio_20d',
        'atr_pct_14',
        'consolidation_width_20d_pct',
        'range_position_20d',
        'candle_body_to_range_1d',
        'downside_volatility_20d',
        'up_day_ratio_20d',
        'current_ratio',
        'gross_margin',
        'gross_npa_ratio',
        'net_npa_ratio',
        'capital_adequacy_ratio',
        'net_interest_margin',
    ];

    public const CATEGORICAL_FEATURES = ['sector'];

    public function __construct(
        private readonly FundamentalDataService $fundamentals,
        private readonly FundamentalBankMetricsService $bankMetrics,
        private readonly MlMarketBreadthFeatureService $marketBreadth,
        private readonly MlSectorRelativeStrengthService $sectorRelative,
    ) {}

    /** @return array{rows:list<array<string,mixed>>,partitions:array<string,mixed>,feature_definitions:array<string,mixed>,diagnostics:array<string,mixed>} */
    public function build(string $horizon, Carbon $cutoff): array
    {
        $directory = storage_path('framework/cache/ml-datasets/build-'.bin2hex(random_bytes(8)));
        $stream = null;
        $rows = [];
        try {
            $stream = $this->buildStreamed($horizon, $cutoff, $directory);
            foreach (['train', 'validation', 'test'] as $partition) {
                $handle = fopen($stream['paths'][$partition], 'rb');
                while (($line = fgets($handle)) !== false) {
                    $row = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
                    $row['partition'] = $partition;
                    $rows[] = $row;
                }
                fclose($handle);
            }
        } finally {
            $this->removeDirectory($directory);
        }

        usort($rows, fn (array $a, array $b): int => strcmp($a['reference_date'], $b['reference_date']) ?: ($a['stock_id'] <=> $b['stock_id']));

        return [
            'rows' => $rows,
            'partitions' => $stream['partitions'],
            'feature_definitions' => $stream['feature_definitions'],
            'diagnostics' => $stream['diagnostics'],
        ];
    }

    /** Canonical viable reference dates and label ends; no dataset or membership writes. */
    public function requiredReferenceDates(string $horizon, Carbon $cutoff): array
    {
        $horizonDays = ['1m' => 21, '3m' => 63, '6m' => 126][$horizon] ?? throw new RuntimeException('Unsupported ML horizon.');
        $benchmark = Stock::query()->where('symbol', 'NIFTY50')->first();
        if ($benchmark === null) {
            return [];
        }
        $benchmarkSeries = $this->priceSeries($benchmark, $cutoff);
        $referenceDates = [];
        $this->universeQuery()->chunkById(100, function ($stockChunk) use (&$referenceDates, $benchmarkSeries, $horizonDays, $cutoff): void {
            foreach ($stockChunk as $stock) {
                $series = $this->priceSeries($stock, $cutoff);
                foreach ($this->viableObservations($series, $benchmarkSeries, $horizonDays, $cutoff) as $observation) {
                    $date = $observation['reference_date'];
                    if (! isset($referenceDates[$date]) || $observation['label_end'] < $referenceDates[$date]) {
                        $referenceDates[$date] = $observation['label_end'];
                    }
                }
            }
        }, 'id');
        ksort($referenceDates);
        return $referenceDates;
    }

    /**
     * Build a partitioned JSONL dataset without retaining the historical
     * matrix in PHP memory. The first pass establishes date partitions; the
     * second pass writes rows in stock-sized buffers.
     *
     * @return array{paths:array{train:string,validation:string,test:string},partitions:array<string,mixed>,feature_definitions:array<string,mixed>,diagnostics:array<string,mixed>}
     */
    public function buildStreamed(string $horizon, Carbon $cutoff, string $directory): array
    {
        $this->marketBreadth->resetMemo();
        $this->sectorRelative->resetMemo();

        $horizonDays = ['1m' => 21, '3m' => 63, '6m' => 126][$horizon] ?? throw new RuntimeException('Unsupported ML horizon.');
        $benchmark = Stock::query()->where('symbol', 'NIFTY50')->first();
        if ($benchmark === null) {
            throw new RuntimeException('Primary benchmark NIFTY50 is unavailable.');
        }
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create the temporary ML dataset directory.');
        }

        $benchmarkSeries = $this->priceSeries($benchmark, $cutoff);
        $referenceDates = $this->requiredReferenceDates($horizon, $cutoff);
        $dates = array_keys($referenceDates);
        sort($dates);
        if (count($dates) < 3) {
            throw new RuntimeException('Insufficient point-in-time training dates.');
        }
        $partitions = $this->partitionMetadata($referenceDates, $cutoff, $horizonDays);
        $membershipCoverage = app(MlHistoricalUniverseMembershipService::class)->coverageForDates($dates);
        $paths = [
            'train' => $directory.'/train.jsonl',
            'validation' => $directory.'/validation.jsonl',
            'test' => $directory.'/test.jsonl',
        ];
        $handles = array_map(static fn (string $path) => fopen($path, 'wb'), $paths);
        $rowCounts = ['train' => 0, 'validation' => 0, 'test' => 0];
        $purgedRows = ['train' => 0, 'validation' => 0, 'test' => 0];
        $peakBufferedRows = 0;
        $stocksProcessed = 0;
        $contextCoverage = [
            'candidate_rows' => 0,
            'written_rows' => 0,
            'market_breadth_present' => 0,
            'market_breadth_missing' => 0,
            'sector_relative_present' => 0,
            'sector_relative_missing' => 0,
        ];
        try {
            $this->universeQuery()->chunkById(100, function ($stockChunk) use (&$handles, &$rowCounts, &$purgedRows, &$peakBufferedRows, &$stocksProcessed, &$contextCoverage, $benchmarkSeries, $horizonDays, $cutoff, $partitions): void {
                foreach ($stockChunk as $stock) {
                    $stocksProcessed++;
                    $series = $this->priceSeries($stock, $cutoff);
                    if ($series['dates'] === []) {
                        continue;
                    }
                    $facts = $this->fundamentalFacts($stock, $cutoff);
                    $stockRows = $this->rowsForStock($stock, $series, $benchmarkSeries, $facts, $horizonDays, $cutoff);
                    $peakBufferedRows = max($peakBufferedRows, count($stockRows));
                    foreach ($stockRows as $row) {
                        $contextCoverage['candidate_rows']++;
                        foreach (['market_breadth_nifty' => 'market_breadth', 'sector_relative_strength_3m' => 'sector_relative'] as $feature => $counter) {
                            if (($row['features'][$feature] ?? null) === null) {
                                $contextCoverage[$counter.'_missing']++;
                            } else {
                                $contextCoverage[$counter.'_present']++;
                            }
                        }
                        $partition = $this->partitionForDate($row['reference_date'], $partitions);
                        if (($partition === 'train' && $row['label_end'] >= $partitions['validation_start']) || ($partition === 'validation' && $row['label_end'] >= $partitions['test_start'])) {
                            $purgedRows[$partition]++;
                            continue;
                        }
                        fwrite($handles[$partition], json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
                        $rowCounts[$partition]++;
                        $contextCoverage['written_rows']++;
                    }
                    unset($stockRows, $facts, $series);
                }
            }, 'id');
        } finally {
            foreach ($handles as $handle) {
                fclose($handle);
            }
        }
        foreach ($rowCounts as $partition => $count) {
            if ($count < 1) {
                throw new RuntimeException("ML dataset partition is empty: {$partition}.");
            }
        }
        if (array_sum($rowCounts) < 12) {
            throw new RuntimeException('Insufficient point-in-time training examples.');
        }
        $partitions['row_counts'] = $rowCounts;
        $partitions['label_separation'] = [
            'rule' => 'label_end_before_next_partition_start',
            'train_purged_rows' => $purgedRows['train'],
            'validation_purged_rows' => $purgedRows['validation'],
        ];
        $bytes = array_sum(array_map(static fn (string $path): int => is_file($path) ? (int) filesize($path) : 0, $paths));
        $rowDateRanges = [];
        $maxLabelEnds = ['train' => null, 'validation' => null, 'test' => null];
        $seenBuckets = [];
        foreach ($paths as $partition => $path) {
            $first = null;
            $last = null;
            $handle = fopen($path, 'rb');
            while (($line = fgets($handle)) !== false) {
                $row = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
                $date = $row['reference_date'] ?? null;
                $labelEnd = $row['label_end'] ?? null;
                $first = $first === null || $date < $first ? $date : $first;
                $last = $last === null || $date > $last ? $date : $last;
                $bucket = substr($date, 0, 7);
                if (isset($seenBuckets[$bucket]) && $seenBuckets[$bucket] !== $partition) {
                    throw new RuntimeException("ML sampling bucket spans partitions: {$bucket}.");
                }
                $seenBuckets[$bucket] = $partition;
                if ($labelEnd !== null && ($maxLabelEnds[$partition] === null || $labelEnd > $maxLabelEnds[$partition])) {
                    $maxLabelEnds[$partition] = $labelEnd;
                }
                if (($partition === 'train' && $labelEnd >= $partitions['validation_start']) || ($partition === 'validation' && $labelEnd >= $partitions['test_start'])) {
                    throw new RuntimeException("ML label horizon crosses {$partition} partition boundary.");
                }
            }
            fclose($handle);
            $rowDateRanges[$partition] = ['start' => $first, 'end' => $last];
            if ($first === null || $last === null || $first < $partitions[$partition.'_start'] || $last > $partitions[$partition.'_end']) {
                throw new RuntimeException("ML dataset rows fall outside reported {$partition} partition dates.");
            }
        }

        return [
            'paths' => $paths,
            'partitions' => $partitions,
            'feature_definitions' => $this->featureDefinitions($horizon),
            'diagnostics' => [
                'stocks_processed' => $stocksProcessed,
                'rows_written' => array_sum($rowCounts),
                'peak_buffered_rows' => $peakBufferedRows,
                'temporary_dataset_bytes' => $bytes,
                'transport' => 'partitioned_jsonl',
                'viable_reference_date_start' => $dates[0],
                'viable_reference_date_end' => $dates[array_key_last($dates)],
                'viable_reference_date_count' => count($dates),
                'benchmark_start_date' => $benchmarkSeries['dates'][0] ?? null,
                'benchmark_end_date' => $benchmarkSeries['dates'][array_key_last($benchmarkSeries['dates'])] ?? null,
                'row_date_ranges' => $rowDateRanges,
                'train_max_label_end' => $maxLabelEnds['train'],
                'validation_start' => $partitions['validation_start'],
                'validation_max_label_end' => $maxLabelEnds['validation'],
                'test_start' => $partitions['test_start'],
                'train_purged_for_label_overlap' => $purgedRows['train'],
                'validation_purged_for_label_overlap' => $purgedRows['validation'],
                'context_coverage' => $contextCoverage,
                'membership_coverage' => $membershipCoverage,
            ],
        ];
    }

    private function universeQuery()
    {
        return Stock::query()
            ->where('is_active', true)
            ->where(function ($query): void {
                $query->where('is_benchmark', false)->orWhereNull('is_benchmark');
            })
            ->where('exchange', 'NSE')
            ->whereExists(fn ($query) => $query->selectRaw('1')
                ->from('portfolio_stock_prices')
                ->whereColumn('portfolio_stock_prices.stock_id', 'portfolio_stocks.id'))
            ->orderBy('id');
    }

    /** @return list<array{reference_date:string,label_end:string}> */
    private function viableObservations(array $series, array $benchmarkSeries, int $horizonDays, Carbon $cutoff): array
    {
        $dates = $series['dates'];
        $labelPrices = $series['labels'];
        $benchmarkLabelPrices = $benchmarkSeries['labels'];
        $observations = [];
        foreach ($this->referenceDatesForHorizon($dates, $horizonDays) as $date) {
            $index = $series['index'][$date] ?? null;
            if ($index === null || $index < 63 || ! isset($dates[$index + $horizonDays])) {
                continue;
            }
            $futureDate = $dates[$index + $horizonDays];
            if ($futureDate > $cutoff->toDateString()) {
                continue;
            }
            $entry = (float) ($labelPrices[$date] ?? 0);
            $future = (float) ($labelPrices[$futureDate] ?? 0);
            $benchmarkEntry = $this->closeAtOrBefore($benchmarkLabelPrices, $date, $benchmarkSeries['dates']);
            $benchmarkFuture = $this->closeAtOrBefore($benchmarkLabelPrices, $futureDate, $benchmarkSeries['dates']);
            if ($entry <= 0 || $future <= 0 || $benchmarkEntry === null || $benchmarkEntry <= 0 || $benchmarkFuture === null || $benchmarkFuture <= 0) {
                continue;
            }
            $observations[] = ['reference_date' => $date, 'label_end' => $futureDate];
        }
        return $observations;
    }

    /** @param array<string,string> $referenceDates @return array<string,mixed> */
    private function partitionMetadata(array $referenceDates, Carbon $cutoff, int $horizonDays): array
    {
        $bucketDates = [];
        foreach ($referenceDates as $date => $minimumLabelEnd) {
            $bucket = substr($date, 0, 7);
            $bucketDates[$bucket]['dates'][] = $date;
            if (! isset($bucketDates[$bucket]['minimum_label_end']) || $minimumLabelEnd < $bucketDates[$bucket]['minimum_label_end']) {
                $bucketDates[$bucket]['minimum_label_end'] = $minimumLabelEnd;
            }
        }
        $buckets = array_keys($bucketDates);
        sort($buckets);
        $bucketCount = count($buckets);
        if ($bucketCount < 3) {
            throw new RuntimeException('Insufficient chronological sampling buckets.');
        }
        $nominalTrainEndIndex = max(0, min($bucketCount - 3, (int) floor($bucketCount * 0.70) - 1));
        $nominalValidationEndIndex = min($bucketCount - 2, max($nominalTrainEndIndex + 1, (int) floor($bucketCount * 0.85) - 1));
        $prefixMinimumLabelEnd = [];
        $minimumLabelEnd = null;
        foreach ($buckets as $index => $bucket) {
            $candidate = $bucketDates[$bucket]['minimum_label_end'];
            $minimumLabelEnd = $minimumLabelEnd === null || $candidate < $minimumLabelEnd ? $candidate : $minimumLabelEnd;
            $prefixMinimumLabelEnd[$index] = $minimumLabelEnd;
        }
        $best = null;
        for ($trainEndIndex = 0; $trainEndIndex <= $bucketCount - 3; $trainEndIndex++) {
            for ($validationEndIndex = $trainEndIndex + 1; $validationEndIndex <= $bucketCount - 2; $validationEndIndex++) {
                $validationStart = $this->firstBucketDate($bucketDates, $buckets[$trainEndIndex + 1]);
                $testStart = $this->firstBucketDate($bucketDates, $buckets[$validationEndIndex + 1]);
                $trainHasUsableRow = $prefixMinimumLabelEnd[$trainEndIndex] < $validationStart;
                $validationMinimumLabelEnd = null;
                for ($index = $trainEndIndex + 1; $index <= $validationEndIndex; $index++) {
                    $candidate = $bucketDates[$buckets[$index]]['minimum_label_end'];
                    $validationMinimumLabelEnd = $validationMinimumLabelEnd === null || $candidate < $validationMinimumLabelEnd ? $candidate : $validationMinimumLabelEnd;
                }
                $validationHasUsableRow = $validationMinimumLabelEnd !== null && $validationMinimumLabelEnd < $testStart;
                if (! $trainHasUsableRow || ! $validationHasUsableRow) {
                    continue;
                }
                $score = abs($trainEndIndex - $nominalTrainEndIndex) + abs($validationEndIndex - $nominalValidationEndIndex);
                if ($best === null || $score < $best['score']) {
                    $best = ['score' => $score, 'train_end_index' => $trainEndIndex, 'validation_end_index' => $validationEndIndex];
                }
            }
        }
        if ($best === null) {
            throw new RuntimeException("Insufficient horizon-aware chronological buckets for {$horizonDays}-observation labels: no train/validation/test allocation retains a usable row after label separation.");
        }
        $trainEndIndex = $best['train_end_index'];
        $validationEndIndex = $best['validation_end_index'];
        $bucketPartitions = [];
        foreach ($buckets as $index => $bucket) {
            $bucketPartitions[$bucket] = $index <= $trainEndIndex ? 'train' : ($index <= $validationEndIndex ? 'validation' : 'test');
        }
        $trainEndBucket = $buckets[$trainEndIndex];
        $validationStartBucket = $buckets[$trainEndIndex + 1];
        $validationEndBucket = $buckets[$validationEndIndex];
        $testStartBucket = $buckets[$validationEndIndex + 1];
        $firstDate = fn (string $bucket): string => $this->firstBucketDate($bucketDates, $bucket);
        $lastDate = static function (array $bucketDates, string $bucket): string {
            $dates = $bucketDates[$bucket]['dates'];
            sort($dates);
            return $dates[array_key_last($dates)];
        };

        return [
            'train_start' => $firstDate($buckets[0]),
            'train_end' => $lastDate($bucketDates, $trainEndBucket),
            'validation_start' => $firstDate($validationStartBucket),
            'validation_end' => $lastDate($bucketDates, $validationEndBucket),
            'test_start' => $firstDate($testStartBucket),
            'test_end' => $lastDate($bucketDates, $buckets[array_key_last($buckets)]),
            'cutoff_date' => $cutoff->toDateString(),
            'split_basis' => 'chronological_monthly_sampling_buckets',
            'sampling_buckets' => $bucketPartitions,
            'sampling' => $this->samplingPolicyForHorizon($horizonDays),
            'purge_embargo' => [
                'version' => 'v8-label-window-purge-1',
                'horizon_observations' => $horizonDays,
                'rule' => 'exclude_rows_whose_forward_label_window_reaches_or_crosses_next_partition_start',
                'train_boundary' => $validationStartBucket,
                'validation_boundary' => $testStartBucket,
                'embargo_observations' => $horizonDays,
            ],
            'horizon_aware' => [
                'label_observations' => $horizonDays,
                'nominal_train_end_bucket' => $buckets[$nominalTrainEndIndex],
                'nominal_validation_end_bucket' => $buckets[$nominalValidationEndIndex],
                'selected_train_end_bucket' => $trainEndBucket,
                'selected_validation_end_bucket' => $validationEndBucket,
                'boundary_adjustment_score' => $best['score'],
            ],
        ];
    }

    private function firstBucketDate(array $bucketDates, string $bucket): string
    {
        $dates = $bucketDates[$bucket]['dates'];
        sort($dates);
        return $dates[0];
    }

    private function partitionForDate(string $date, array $partitions): string
    {
        $bucket = substr($date, 0, 7);
        if (! isset($partitions['sampling_buckets'][$bucket])) {
            throw new RuntimeException("ML row has no sampling bucket partition: {$bucket}.");
        }
        return $partitions['sampling_buckets'][$bucket];
    }

    /** @return array<string,mixed> */
    private function featureDefinitions(string $horizon): array
    {
        $horizonDays = ['1m' => 21, '3m' => 63, '6m' => 126][$horizon] ?? throw new RuntimeException('Unsupported ML horizon.');
        $featureProfile = app(MlFeatureRegistryService::class)->featureSetForHorizon($horizon);

        return [
            'version' => 'v8-features-1',
            'numeric' => array_fill_keys(self::NUMERIC_FEATURES, ['missing' => 'median_with_missingness_flags', 'as_of' => 'reference_date']),
            'categorical' => ['sector' => ['encoding' => 'training_partition_categories', 'unknown' => '__unknown']],
            'price_semantics' => [
                'features' => 'unadjusted_close_as_of_reference_date',
                'labels' => 'adjusted_close_as_of_observed_label_window',
                'reason' => 'adjusted_close is retroactively changed by later corporate-action repair; features cannot consume that series.',
            ],
            'universe' => [
                'version' => 'v8-active-eligible-nse-1',
                'rule' => 'currently active, non-benchmark NSE stocks with price observations through the cutoff',
            ],
            'benchmark_mapping' => $this->benchmarkMappingDefinition(),
            'sampling' => $this->samplingPolicyForHorizon($horizonDays),
            'resolved_feature_profile' => $featureProfile,
        ];
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory.'/'.$entry;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }
        @rmdir($directory);
    }

    /**
     * Read-only planning path. It never creates lifecycle rows or loads the
     * training matrix; the estimate is intentionally cheap and conservative.
     *
     * @return array<string,mixed>
     */
    public function plan(string $horizon, Carbon $cutoff): array
    {
        if (! isset(['1m' => 21, '3m' => 63, '6m' => 126][$horizon])) {
            throw new RuntimeException('Unsupported ML horizon.');
        }
        $stockQuery = $this->universeQuery();
        $stockCount = (int) $stockQuery->count();
        $first = StockPrice::query()->whereDate('price_date', '<=', $cutoff->toDateString())->min('price_date');
        $last = StockPrice::query()->whereDate('price_date', '<=', $cutoff->toDateString())->max('price_date');
        $months = 0;
        if ($first !== null && $last !== null) {
            $months = ((int) Carbon::parse($first)->diffInMonths(Carbon::parse($last))) + 1;
        }

        return [
            'horizon' => $horizon,
            'cutoff_date' => $cutoff->toDateString(),
            'stock_count' => $stockCount,
            'sampling' => $this->samplingPolicyForHorizon(['1m' => 21, '3m' => 63, '6m' => 126][$horizon]),
            'estimated_reference_rows' => $stockCount * $months,
            'estimate_basis' => 'conservative_upper_bound: eligible_stock_count multiplied by global calendar-month range; actual listing/history overlap may be lower',
            'date_range' => ['start' => $first, 'end' => $last],
            'expected_partitions' => ['train' => '70%', 'validation' => '15%', 'test' => '15%'],
        ];
    }

    /** @return array<string,mixed> */
    public function featuresFor(Stock $stock, Carbon $asOf): array
    {
        $benchmark = $this->benchmarkFor($stock);
        $series = $this->priceSeries($stock, $asOf);
        $stockPrices = $series['features'];
        $benchmarkPrices = $benchmark ? $this->prices($benchmark, $asOf) : [];
        $highs = $series['highs'];
        $lows = $series['lows'];
        $close = $this->closeAtOrBefore($stockPrices, $asOf->toDateString());
        $benchmarkClose = $this->closeAtOrBefore($benchmarkPrices, $asOf->toDateString());
        $stock1m = $this->returnOver($stockPrices, $asOf->toDateString(), 21);
        $benchmark1m = $this->returnOver($benchmarkPrices, $asOf->toDateString(), 21);
        $stock3m = $this->returnOver($stockPrices, $asOf->toDateString(), 63);
        $benchmark3m = $this->returnOver($benchmarkPrices, $asOf->toDateString(), 63);
        $momentum = $stock1m;
        $sma = $this->sma($stockPrices, $asOf->toDateString(), 20);
        $benchmarkSma = $this->sma($benchmarkPrices, $asOf->toDateString(), 20);
        $roe = $this->fundamentals->metric($stock, 'roe', 'ttm', $asOf);
        $debtEquity = $this->fundamentals->metric($stock, 'debt_equity', 'ttm', $asOf);
        $revenue = $this->fundamentals->growthMetric($stock, 'revenue', 'quarterly', $asOf);

        $margins = $this->marginFeaturesFromService($stock, $asOf);
        $volatility = $this->realizedVolatility($stockPrices, $asOf->toDateString(), 63, array_flip(array_keys($stockPrices)));
        $stock6m = $this->returnOver($stockPrices, $asOf->toDateString(), 126);
        $benchmark6m = $this->returnOver($benchmarkPrices, $asOf->toDateString(), 126);
        $pe = $close !== null ? $this->fundamentals->metric($stock, 'pe', 'ttm', $asOf, $close) : ['value' => null];
        $pb = $close !== null ? $this->fundamentals->metric($stock, 'pb', 'ttm', $asOf, $close) : ['value' => null];
        $epsGrowth = $this->fundamentals->growthMetric($stock, 'eps', FundamentalDataService::CADENCE_QUARTERLY, $asOf);
        $niGrowth = $this->fundamentals->growthMetric($stock, 'net_income', FundamentalDataService::CADENCE_QUARTERLY, $asOf);
        $ocf = $this->fundamentals->metric($stock, 'operating_cash_flow', 'ttm', $asOf);
        $priceIndex = array_flip(array_keys($stockPrices));
        $volumes = $series['volumes'];
        $sma50 = $this->sma($stockPrices, $asOf->toDateString(), 50, $priceIndex);
        $sma200 = $this->sma($stockPrices, $asOf->toDateString(), 200, $priceIndex);
        $benchmarkPriceIndex = array_flip(array_keys($benchmarkPrices));

        return [
            'relative_strength_1m' => $stock1m !== null && $benchmark1m !== null ? $stock1m - $benchmark1m : null,
            'relative_strength_3m' => $stock3m !== null && $benchmark3m !== null ? $stock3m - $benchmark3m : null,
            'relative_strength_6m' => $stock6m !== null && $benchmark6m !== null ? $stock6m - $benchmark6m : null,
            'benchmark_return_3m' => $benchmark3m,
            'benchmark_return_6m' => $benchmark6m,
            'benchmark_trend_score' => $benchmarkClose !== null && $benchmarkSma !== null && $benchmarkSma != 0
                ? (($benchmarkClose / $benchmarkSma) - 1) * 100
                : null,
            'sector_relative_strength_3m' => $this->sectorRelative->relativeStrength3m($stock, $asOf->toDateString()),
            'market_breadth_nifty' => $this->marketBreadth->pctAboveSma20($asOf->toDateString()),
            'momentum_score' => $momentum,
            'price_return_1m' => $stock1m,
            'price_return_3m' => $this->returnOver($stockPrices, $asOf->toDateString(), 63),
            'price_return_6m' => $this->returnOver($stockPrices, $asOf->toDateString(), 126),
            'price_return_12m' => $this->returnOver($stockPrices, $asOf->toDateString(), 252),
            'trend_score' => $close !== null && $sma !== null && $sma != 0 ? (($close / $sma) - 1) * 100 : null,
            'trend_score_ma50' => $close !== null && $sma50 !== null && $sma50 != 0 ? (($close / $sma50) - 1) * 100 : null,
            'trend_score_ma200' => $close !== null && $sma200 !== null && $sma200 != 0 ? (($close / $sma200) - 1) * 100 : null,
            'benchmark_realized_volatility_20d' => $this->realizedVolatility(
                $benchmarkPrices,
                $asOf->toDateString(),
                20,
                $benchmarkPriceIndex,
            ),
            'roe' => $roe['value'],
            'debt_equity' => $debtEquity['value'],
            'revenue_growth_proxy' => $revenue['value'],
            'eps_growth_yoy' => $epsGrowth['value'],
            'net_income_growth_yoy' => $niGrowth['value'],
            'operating_margin' => $margins['operating_margin'],
            'net_margin' => $margins['net_margin'],
            'fcf_margin' => $margins['fcf_margin'],
            'ocf_to_net_income_ratio' => $this->ratio(
                $ocf['value'] !== null ? (float) $ocf['value'] : null,
                $this->fundamentals->metric($stock, 'net_income', 'ttm', $asOf)['value'],
            ),
            'pe_ratio' => $pe['value'],
            'pb_ratio' => $pb['value'],
            'realized_volatility_20d' => $this->realizedVolatility($stockPrices, $asOf->toDateString(), 20, $priceIndex),
            'realized_volatility_63d' => $volatility,
            'volatility_ratio_20_63' => $this->volatilityRatio20_63($stockPrices, $asOf->toDateString(), $priceIndex),
            'volume_trend_20d' => $this->volumeTrend20d($volumes, $asOf->toDateString(), $priceIndex),
            'net_debt_equity' => $this->netDebtEquityFromService($stock, $asOf, $close),
            'current_drawdown_pct' => $this->currentDrawdownPct($stockPrices, $asOf->toDateString(), $priceIndex),
            'distance_52w_high_pct' => $this->distance52wHigh($stockPrices, $asOf->toDateString(), $priceIndex),
            'distance_20d_high_pct' => $this->distanceFromHigh($highs, $stockPrices, $asOf->toDateString(), 20, $priceIndex),
            'volume_ratio_20d' => $this->volumeRatio20d($volumes, $asOf->toDateString(), $priceIndex),
            'atr_pct_14' => $this->atrPct14($highs, $lows, $stockPrices, $asOf->toDateString(), $priceIndex),
            'consolidation_width_20d_pct' => $this->consolidationWidthPct($highs, $lows, $stockPrices, $asOf->toDateString(), 20, $priceIndex),
            'range_position_20d' => $this->rangePosition($highs, $lows, $stockPrices, $asOf->toDateString(), 20, $priceIndex),
            'candle_body_to_range_1d' => $this->candleBodyToRange($highs, $lows, $stockPrices, $asOf->toDateString(), $priceIndex),
            'downside_volatility_20d' => $this->downsideVolatility($stockPrices, $asOf->toDateString(), 20, $priceIndex),
            'up_day_ratio_20d' => $this->upDayRatio($stockPrices, $asOf->toDateString(), 20, $priceIndex),
            'current_ratio' => $this->currentRatioFromService($stock, $asOf),
            'gross_margin' => $this->grossMarginFromService($stock, $asOf),
            ...$this->bankMetrics->metricsForStock($stock, $asOf),
            'sector' => $this->historicalSector($stock, $asOf->toDateString()),
            'as_of' => $asOf->toDateTimeString(),
            'benchmark_symbol' => $benchmark?->symbol ?: 'NIFTY50',
            'benchmark_close' => $benchmarkClose,
        ];
    }

    /** @return array<string,float> */
    private function prices(Stock $stock, Carbon $to): array
    {
        return $this->priceSeries($stock, $to)['features'];
    }

    /** @return list<array<string,mixed>> */
    private function rowsForStock(Stock $stock, array $series, array $benchmarkSeries, array $facts, int $horizonDays, Carbon $cutoff): array
    {
        $prices = $series['features'];
        $benchmarkPrices = $benchmarkSeries['features'];
        $labelPrices = $series['labels'];
        $benchmarkLabelPrices = $benchmarkSeries['labels'];
        $dates = $series['dates'];
        $dateIndex = $series['index'];
        $observations = $this->viableObservations($series, $benchmarkSeries, $horizonDays, $cutoff);
        $rows = [];
        foreach ($observations as $observation) {
            $date = $observation['reference_date'];
            $futureDate = $observation['label_end'];
            $index = $dateIndex[$date];
            $features = $this->featuresForPrices(
                $stock,
                $date,
                $prices,
                $benchmarkPrices,
                $facts,
                $series['index'],
                $benchmarkSeries['index'],
                $series['volumes'] ?? [],
                $series['highs'] ?? [],
                $series['lows'] ?? [],
            );
            $entry = (float) ($labelPrices[$date] ?? 0);
            $future = (float) ($labelPrices[$futureDate] ?? 0);
            $benchmarkEntry = $this->closeAtOrBefore($benchmarkLabelPrices, $date, $benchmarkSeries['dates']);
            $benchmarkFuture = $this->closeAtOrBefore($benchmarkLabelPrices, $futureDate, $benchmarkSeries['dates']);
            $relativeReturn = (($future - $entry) / $entry) - (($benchmarkFuture - $benchmarkEntry) / $benchmarkEntry);
            $maxDrawdown = $this->maxDrawdown($labelPrices, $index, $index + $horizonDays, $entry, $dates);
            $rows[] = [
                'stock_id' => $stock->id,
                'reference_date' => $date,
                'label_end' => $futureDate,
                'features' => $features,
                'label' => (int) ($relativeReturn > 0 && $maxDrawdown >= -0.20),
                'relative_return' => $relativeReturn,
                'max_drawdown' => $maxDrawdown,
            ];
        }

        return $rows;
    }

    /** @return array<string,float|null> */
    private function featuresForPrices(
        Stock $stock,
        string $date,
        array $prices,
        array $benchmarkPrices,
        array $facts,
        array $priceIndex,
        array $benchmarkIndex,
        array $volumes = [],
        array $highs = [],
        array $lows = [],
    ): array {
        $stock1m = $this->returnOver($prices, $date, 21, $priceIndex);
        $benchmark1m = $this->returnOver($benchmarkPrices, $date, 21, $benchmarkIndex);
        $stock3m = $this->returnOver($prices, $date, 63, $priceIndex);
        $benchmark3m = $this->returnOver($benchmarkPrices, $date, 63, $benchmarkIndex);
        $stock6m = $this->returnOver($prices, $date, 126, $priceIndex);
        $benchmark6m = $this->returnOver($benchmarkPrices, $date, 126, $benchmarkIndex);
        $momentum = $stock1m;
        $close = $this->closeAtOrBefore($prices, $date, array_keys($priceIndex));
        $benchmarkClose = $this->closeAtOrBefore($benchmarkPrices, $date, array_keys($benchmarkIndex));
        $sma = $this->sma($prices, $date, 20, $priceIndex);
        $benchmarkSma = $this->sma($benchmarkPrices, $date, 20, $benchmarkIndex);
        $sma50 = $this->sma($prices, $date, 50, $priceIndex);
        $sma200 = $this->sma($prices, $date, 200, $priceIndex);
        $metrics = $this->historicalMetrics($facts, $date, $close);

        return [
            'relative_strength_1m' => $stock1m !== null && $benchmark1m !== null ? $stock1m - $benchmark1m : null,
            'relative_strength_3m' => $stock3m !== null && $benchmark3m !== null ? $stock3m - $benchmark3m : null,
            'relative_strength_6m' => $stock6m !== null && $benchmark6m !== null ? $stock6m - $benchmark6m : null,
            'benchmark_return_3m' => $benchmark3m,
            'benchmark_return_6m' => $benchmark6m,
            'benchmark_trend_score' => $benchmarkClose !== null && $benchmarkSma !== null && $benchmarkSma != 0
                ? (($benchmarkClose / $benchmarkSma) - 1) * 100
                : null,
            'sector_relative_strength_3m' => $this->sectorRelative->relativeStrength3m($stock, $date),
            'market_breadth_nifty' => $this->marketBreadth->pctAboveSma20($date),
            'momentum_score' => $momentum,
            'price_return_1m' => $stock1m,
            'price_return_3m' => $this->returnOver($prices, $date, 63, $priceIndex),
            'price_return_6m' => $this->returnOver($prices, $date, 126, $priceIndex),
            'price_return_12m' => $this->returnOver($prices, $date, 252, $priceIndex),
            'trend_score' => $close !== null && $sma !== null && $sma != 0 ? (($close / $sma) - 1) * 100 : null,
            'trend_score_ma50' => $close !== null && $sma50 !== null && $sma50 != 0 ? (($close / $sma50) - 1) * 100 : null,
            'trend_score_ma200' => $close !== null && $sma200 !== null && $sma200 != 0 ? (($close / $sma200) - 1) * 100 : null,
            'benchmark_realized_volatility_20d' => $this->realizedVolatility($benchmarkPrices, $date, 20, $benchmarkIndex),
            'roe' => $metrics['roe'],
            'debt_equity' => $metrics['debt_equity'],
            'revenue_growth_proxy' => $metrics['revenue_growth'],
            'eps_growth_yoy' => $metrics['eps_growth_yoy'],
            'net_income_growth_yoy' => $metrics['net_income_growth_yoy'],
            'operating_margin' => $metrics['operating_margin'],
            'net_margin' => $metrics['net_margin'],
            'fcf_margin' => $metrics['fcf_margin'],
            'ocf_to_net_income_ratio' => $metrics['ocf_to_net_income_ratio'],
            'pe_ratio' => $metrics['pe_ratio'],
            'pb_ratio' => $metrics['pb_ratio'],
            'realized_volatility_20d' => $this->realizedVolatility($prices, $date, 20, $priceIndex),
            'realized_volatility_63d' => $this->realizedVolatility($prices, $date, 63, $priceIndex),
            'volatility_ratio_20_63' => $this->volatilityRatio20_63($prices, $date, $priceIndex),
            'volume_trend_20d' => $this->volumeTrend20d($volumes, $date, $priceIndex),
            'net_debt_equity' => $metrics['net_debt_equity'],
            'current_drawdown_pct' => $this->currentDrawdownPct($prices, $date, $priceIndex),
            'distance_52w_high_pct' => $this->distance52wHigh($prices, $date, $priceIndex),
            'distance_20d_high_pct' => $this->distanceFromHigh($highs, $prices, $date, 20, $priceIndex),
            'volume_ratio_20d' => $this->volumeRatio20d($volumes, $date, $priceIndex),
            'atr_pct_14' => $this->atrPct14($highs, $lows, $prices, $date, $priceIndex),
            'consolidation_width_20d_pct' => $this->consolidationWidthPct($highs, $lows, $prices, $date, 20, $priceIndex),
            'range_position_20d' => $this->rangePosition($highs, $lows, $prices, $date, 20, $priceIndex),
            'candle_body_to_range_1d' => $this->candleBodyToRange($highs, $lows, $prices, $date, $priceIndex),
            'downside_volatility_20d' => $this->downsideVolatility($prices, $date, 20, $priceIndex),
            'up_day_ratio_20d' => $this->upDayRatio($prices, $date, 20, $priceIndex),
            'current_ratio' => $metrics['current_ratio'],
            'gross_margin' => $metrics['gross_margin'],
            'gross_npa_ratio' => $metrics['gross_npa_ratio'],
            'net_npa_ratio' => $metrics['net_npa_ratio'],
            'capital_adequacy_ratio' => $metrics['capital_adequacy_ratio'],
            'net_interest_margin' => $metrics['net_interest_margin'],
            'sector' => $this->historicalSector($stock, $date),
        ];
    }

    private function historicalSector(Stock $stock, string $referenceDate): string
    {
        return $this->sectorRelative->sectorForDate((int) $stock->id, $referenceDate) ?? '__unknown';
    }

    private function ratio(?float $numerator, ?float $denominator): ?float
    {
        if ($numerator === null || $denominator === null || $denominator == 0.0) {
            return null;
        }

        return round($numerator / $denominator, 6);
    }

    /** @return array{operating_margin:?float,net_margin:?float,fcf_margin:?float} */
    private function marginFeaturesFromService(Stock $stock, Carbon $asOf): array
    {
        $revenue = $this->fundamentals->metric($stock, 'revenue', 'ttm', $asOf);
        $rev = $revenue['value'] !== null ? (float) $revenue['value'] : null;
        if ($rev === null || $rev === 0.0) {
            return ['operating_margin' => null, 'net_margin' => null, 'fcf_margin' => null];
        }
        $op = $this->fundamentals->metric($stock, 'operating_profit', 'ttm', $asOf)['value'];
        $ni = $this->fundamentals->metric($stock, 'net_income', 'ttm', $asOf)['value'];
        $fcf = $this->fundamentals->metric($stock, 'free_cash_flow', 'ttm', $asOf)['value'];

        return [
            'operating_margin' => $op !== null ? ((float) $op / $rev) * 100 : null,
            'net_margin' => $ni !== null ? ((float) $ni / $rev) * 100 : null,
            'fcf_margin' => $fcf !== null ? ((float) $fcf / $rev) * 100 : null,
        ];
    }

    private function realizedVolatility(array $prices, string $date, int $lookback, ?array $index = null): ?float
    {
        $dates = array_keys($prices);
        $position = $index[$date] ?? array_search($date, $dates, true);
        if ($position === false || $position === null || $position < $lookback) {
            return null;
        }

        $returns = [];
        for ($i = $position - $lookback + 1; $i <= $position; $i++) {
            $prev = (float) $prices[$dates[$i - 1]];
            $curr = (float) $prices[$dates[$i]];
            if ($prev <= 0.0) {
                continue;
            }
            $returns[] = log($curr / $prev);
        }

        if (count($returns) < 5) {
            return null;
        }

        $mean = array_sum($returns) / count($returns);
        $variance = 0.0;
        foreach ($returns as $r) {
            $variance += ($r - $mean) ** 2;
        }
        $variance /= count($returns);

        return round(sqrt($variance) * sqrt(252) * 100, 6);
    }

    /** @return array{features:array<string,float>,labels:array<string,float>,dates:list<string>,index:array<string,int>} */
    private function priceSeries(Stock $stock, Carbon $to): array
    {
        $features = [];
        $labels = [];
        $volumes = [];
        $dates = [];
        $highs = [];
        $lows = [];
        StockPrice::query()->where('stock_id', $stock->id)->whereDate('price_date', '<=', $to->toDateString())->orderBy('price_date')->get(['price_date', 'close_price', 'adjusted_close_price', 'high_price', 'low_price', 'volume'])->each(function (StockPrice $price) use (&$features, &$labels, &$volumes, &$dates, &$highs, &$lows): void {
            if ($price->close_price === null) {
                return;
            }
            $date = $price->price_date->toDateString();
            $dates[] = $date;
            $close = (float) $price->close_price;
            $features[$date] = $close;
            $labels[$date] = (float) ($price->adjusted_close_price ?? $price->close_price);
            $volumes[$date] = (int) ($price->volume ?? 0);
            $highs[$date] = (float) ($price->high_price ?? $close);
            $lows[$date] = (float) ($price->low_price ?? $close);
        });

        return ['features' => $features, 'labels' => $labels, 'volumes' => $volumes, 'highs' => $highs, 'lows' => $lows, 'dates' => $dates, 'index' => array_flip($dates)];
    }

    /** @return array<string,int> */
    private function volumeSeries(Stock $stock, Carbon $to): array
    {
        return $this->priceSeries($stock, $to)['volumes'];
    }

    private function currentDrawdownPct(array $prices, string $date, ?array $index = null): ?float
    {
        $dates = array_keys($prices);
        $position = $index[$date] ?? array_search($date, $dates, true);
        if ($position === false || $position === null) {
            return null;
        }
        $window = array_slice(array_values($prices), 0, $position + 1);
        $peak = max($window);
        $close = (float) $prices[$date];
        if ($peak <= 0) {
            return null;
        }

        return round((($close / $peak) - 1) * 100, 6);
    }

    private function distance52wHigh(array $prices, string $date, ?array $index = null): ?float
    {
        $dates = array_keys($prices);
        $position = $index[$date] ?? array_search($date, $dates, true);
        if ($position === false || $position === null) {
            return null;
        }
        $lookback = min(252, $position + 1);
        $window = array_slice(array_values($prices), $position - $lookback + 1, $lookback);
        $high = max($window);
        $close = (float) $prices[$date];
        if ($high <= 0) {
            return null;
        }

        return round((($close / $high) - 1) * 100, 6);
    }

    /** @param array<string,int> $volumes */
    private function volumeRatio20d(array $volumes, string $date, ?array $index = null): ?float
    {
        if ($volumes === []) {
            return null;
        }
        $dates = array_keys($volumes);
        $position = $index[$date] ?? array_search($date, $dates, true);
        if ($position === false || $position === null || $position < 19) {
            return null;
        }
        $slice = array_slice(array_values($volumes), $position - 19, 20);
        $avg = array_sum($slice) / count($slice);
        if ($avg <= 0) {
            return null;
        }

        return round(((float) $volumes[$date]) / $avg, 6);
    }

    /** @param array<string,int> $volumes */
    private function volumeTrend20d(array $volumes, string $date, ?array $index = null): ?float
    {
        if ($volumes === []) {
            return null;
        }
        $dates = array_keys($volumes);
        $position = $index[$date] ?? array_search($date, $dates, true);
        if ($position === false || $position === null || $position < 19) {
            return null;
        }
        $slice = array_slice(array_values($volumes), $position - 19, 20);
        $recent = array_slice($slice, -5);
        $prior = array_slice($slice, 0, 15);
        $recentAvg = array_sum($recent) / max(1, count($recent));
        $priorAvg = array_sum($prior) / max(1, count($prior));
        if ($priorAvg <= 0) {
            return null;
        }

        return round((($recentAvg / $priorAvg) - 1) * 100, 6);
    }

    private function volatilityRatio20_63(array $prices, string $date, ?array $index = null): ?float
    {
        $short = $this->realizedVolatility($prices, $date, 20, $index);
        $long = $this->realizedVolatility($prices, $date, 63, $index);
        if ($short === null || $long === null || $long == 0.0) {
            return null;
        }

        return round($short / $long, 6);
    }

    private function netDebtEquityFromService(Stock $stock, Carbon $asOf, ?float $close): ?float
    {
        $netDebt = $this->fundamentals->metric($stock, 'net_debt', 'ttm', $asOf, $close)['value'];
        $equity = $this->fundamentals->metric($stock, 'equity', 'ttm', $asOf, $close)['value'];
        if ($netDebt === null || $equity === null || (float) $equity == 0.0) {
            return null;
        }

        return round((float) $netDebt / (float) $equity, 6);
    }

    private function currentRatioFromService(Stock $stock, Carbon $asOf): ?float
    {
        $facts = $this->fundamentals->factMap($stock, FundamentalDataService::CADENCE_QUARTERLY, $asOf);
        $assets = isset($facts['current_assets']) ? (float) $facts['current_assets']->value : null;
        $liabilities = isset($facts['current_liabilities']) ? (float) $facts['current_liabilities']->value : null;
        if ($assets === null || $liabilities === null || $liabilities <= 0.0) {
            return null;
        }

        return round($assets / $liabilities, 6);
    }

    private function grossMarginFromService(Stock $stock, Carbon $asOf): ?float
    {
        $revenue = $this->fundamentals->metric($stock, 'revenue', 'ttm', $asOf)['value'];
        $facts = $this->fundamentals->factMap($stock, FundamentalDataService::CADENCE_QUARTERLY, $asOf);
        $gross = null;
        if (isset($facts['gross_profit'])) {
            $gross = (float) $facts['gross_profit']->value;
        }
        if ($revenue === null || $gross === null || (float) $revenue == 0.0) {
            return null;
        }

        return round(($gross / (float) $revenue) * 100, 6);
    }

    /** @param array<string,float> $highs */
    private function distanceFromHigh(array $highs, array $closes, string $date, int $lookback, ?array $index = null): ?float
    {
        if ($highs === []) {
            return null;
        }
        $dates = array_keys($closes);
        $position = $index[$date] ?? array_search($date, $dates, true);
        if ($position === false || $position === null || $position + 1 < $lookback) {
            return null;
        }
        $windowDates = array_slice($dates, $position - $lookback + 1, $lookback);
        $peak = max(array_map(fn (string $d): float => (float) ($highs[$d] ?? $closes[$d]), $windowDates));
        $close = (float) $closes[$date];
        if ($peak <= 0.0) {
            return null;
        }

        return round((($close / $peak) - 1) * 100, 6);
    }

    /** @param array<string,float> $highs @param array<string,float> $lows */
    private function consolidationWidthPct(array $highs, array $lows, array $closes, string $date, int $lookback, ?array $index = null): ?float
    {
        $dates = array_keys($closes);
        $position = $index[$date] ?? array_search($date, $dates, true);
        if ($position === false || $position === null || $position + 1 < $lookback) {
            return null;
        }
        $windowDates = array_slice($dates, $position - $lookback + 1, $lookback);
        $maxHigh = max(array_map(fn (string $d): float => (float) ($highs[$d] ?? $closes[$d]), $windowDates));
        $minLow = min(array_map(fn (string $d): float => (float) ($lows[$d] ?? $closes[$d]), $windowDates));
        $close = (float) $closes[$date];
        if ($close <= 0.0) {
            return null;
        }

        return round((($maxHigh - $minLow) / $close) * 100, 6);
    }

    /** @param array<string,float> $highs @param array<string,float> $lows */
    private function rangePosition(array $highs, array $lows, array $closes, string $date, int $lookback, ?array $index = null): ?float
    {
        $dates = array_keys($closes);
        $position = $index[$date] ?? array_search($date, $dates, true);
        if ($position === false || $position === null || $position + 1 < $lookback) {
            return null;
        }
        $windowDates = array_slice($dates, $position - $lookback + 1, $lookback);
        $maxHigh = max(array_map(fn (string $d): float => (float) ($highs[$d] ?? $closes[$d]), $windowDates));
        $minLow = min(array_map(fn (string $d): float => (float) ($lows[$d] ?? $closes[$d]), $windowDates));
        $close = (float) $closes[$date];
        $span = $maxHigh - $minLow;
        if ($span <= 0.0) {
            return null;
        }

        return round((($close - $minLow) / $span) * 100, 6);
    }

    /** @param array<string,float> $highs @param array<string,float> $lows */
    private function atrPct14(array $highs, array $lows, array $closes, string $date, ?array $index = null, int $period = 14): ?float
    {
        $dates = array_keys($closes);
        $position = $index[$date] ?? array_search($date, $dates, true);
        if ($position === false || $position === null || $position < $period) {
            return null;
        }
        $trs = [];
        for ($i = $position - $period + 1; $i <= $position; $i++) {
            $d = $dates[$i];
            $prev = $dates[$i - 1];
            $high = (float) ($highs[$d] ?? $closes[$d]);
            $low = (float) ($lows[$d] ?? $closes[$d]);
            $prevClose = (float) $closes[$prev];
            $trs[] = max($high - $low, abs($high - $prevClose), abs($low - $prevClose));
        }
        $atr = array_sum($trs) / count($trs);
        $close = (float) $closes[$date];
        if ($close <= 0.0) {
            return null;
        }

        return round(($atr / $close) * 100, 6);
    }

    /** @param array<string,float> $highs @param array<string,float> $lows */
    private function candleBodyToRange(array $highs, array $lows, array $closes, string $date, ?array $index = null): ?float
    {
        $dates = array_keys($closes);
        $position = $index[$date] ?? array_search($date, $dates, true);
        if ($position === false || $position === null || $position < 1) {
            return null;
        }
        $d = $dates[$position];
        $prev = $dates[$position - 1];
        $high = (float) ($highs[$d] ?? $closes[$d]);
        $low = (float) ($lows[$d] ?? $closes[$d]);
        $range = $high - $low;
        if ($range <= 0.0) {
            return null;
        }
        $body = abs((float) $closes[$d] - (float) $closes[$prev]);

        return round($body / $range, 6);
    }

    private function downsideVolatility(array $prices, string $date, int $lookback, ?array $index = null): ?float
    {
        $dates = array_keys($prices);
        $position = $index[$date] ?? array_search($date, $dates, true);
        if ($position === false || $position === null || $position < $lookback) {
            return null;
        }
        $returns = [];
        for ($i = $position - $lookback + 1; $i <= $position; $i++) {
            $prev = (float) $prices[$dates[$i - 1]];
            $curr = (float) $prices[$dates[$i]];
            if ($prev <= 0.0) {
                continue;
            }
            $r = log($curr / $prev);
            if ($r < 0) {
                $returns[] = $r;
            }
        }
        if (count($returns) < 3) {
            return null;
        }
        $mean = array_sum($returns) / count($returns);
        $variance = 0.0;
        foreach ($returns as $r) {
            $variance += ($r - $mean) ** 2;
        }
        $variance /= count($returns);

        return round(sqrt($variance) * sqrt(252) * 100, 6);
    }

    private function upDayRatio(array $prices, string $date, int $lookback, ?array $index = null): ?float
    {
        $dates = array_keys($prices);
        $position = $index[$date] ?? array_search($date, $dates, true);
        if ($position === false || $position === null || $position < $lookback) {
            return null;
        }
        $up = 0;
        $total = 0;
        for ($i = $position - $lookback + 1; $i <= $position; $i++) {
            $prev = (float) $prices[$dates[$i - 1]];
            $curr = (float) $prices[$dates[$i]];
            if ($prev <= 0.0) {
                continue;
            }
            $total++;
            if ($curr > $prev) {
                $up++;
            }
        }
        if ($total === 0) {
            return null;
        }

        return round($up / $total, 6);
    }

    /** @return list<array<string,mixed>> */
    private function fundamentalFacts(Stock $stock, Carbon $cutoff): array
    {
        return FundamentalFact::query()->where('stock_id', $stock->id)->where('cadence', 'quarterly')->whereDate('availability_date', '<=', $cutoff->toDateString())->orderBy('period_end')->orderBy('availability_date')->orderBy('revision_number')->get()->map(fn (FundamentalFact $fact): array => [
            'fact_key' => $fact->fact_key,
            'period_end' => $fact->period_end?->toDateString(),
            'availability_date' => $fact->availability_date?->toDateString(),
            'value' => $fact->value !== null ? (float) $fact->value : null,
            'revision_number' => (int) $fact->revision_number,
        ])->all();
    }

    /** @return array<string,?float> */
    private function historicalMetrics(array $facts, string $asOf, ?float $close = null): array
    {
        $byKeyPeriod = [];
        foreach ($facts as $fact) {
            if (($fact['availability_date'] ?? '') > $asOf || $fact['period_end'] === null) {
                continue;
            }
            $key = $fact['fact_key'].':'.$fact['period_end'];
            $current = $byKeyPeriod[$key] ?? null;
            if ($current === null || [$fact['availability_date'], $fact['revision_number']] > [$current['availability_date'], $current['revision_number']]) {
                $byKeyPeriod[$key] = $fact;
            }
        }
        $byKey = [];
        foreach ($byKeyPeriod as $fact) {
            $byKey[$fact['fact_key']][] = $fact;
        }
        foreach ($byKey as &$items) {
            usort($items, static fn (array $a, array $b): int => strcmp((string) $b['period_end'], (string) $a['period_end']));
        }
        unset($items);
        $sum = fn (string $key): ?float => $this->historicalTtm($byKey[$key] ?? []);
        $netIncome = $sum('net_income');
        $equity = isset($byKey['equity'][0]) ? (float) ($byKey['equity'][0]['value'] ?? 0) : null;
        $debt = isset($byKey['debt'][0]) ? (float) ($byKey['debt'][0]['value'] ?? 0) : null;
        $cash = isset($byKey['cash_and_equivalents'][0]) ? (float) ($byKey['cash_and_equivalents'][0]['value'] ?? 0) : null;
        $netDebt = $debt !== null && $cash !== null ? $debt - $cash : null;
        $revenue = $byKey['revenue'] ?? [];
        $growth = $this->yoyGrowthPercent($revenue);
        $netIncomeSeries = $byKey['net_income'] ?? [];
        $epsSeries = $byKey['eps'] ?? [];
        $revenueTtm = $sum('revenue');
        $operatingProfitTtm = $sum('operating_profit');
        $ocfTtm = $sum('operating_cash_flow');
        $capexTtm = $sum('capital_expenditure');
        $fcfTtm = $ocfTtm !== null && $capexTtm !== null ? $ocfTtm - abs($capexTtm) : null;

        $epsTtm = $sum('eps');
        $grossProfitTtm = $sum('gross_profit');
        $currentAssets = isset($byKey['current_assets'][0]) ? (float) ($byKey['current_assets'][0]['value'] ?? 0) : null;
        $currentLiabilities = isset($byKey['current_liabilities'][0]) ? (float) ($byKey['current_liabilities'][0]['value'] ?? 0) : null;
        $equityLatest = isset($byKey['equity'][0]) ? (float) ($byKey['equity'][0]['value'] ?? 0) : null;

        return [
            'roe' => $netIncome !== null && $equity ? ($netIncome / $equity) * 100 : null,
            'debt_equity' => $debt !== null && $equity ? $debt / $equity : null,
            'net_debt_equity' => $netDebt !== null && $equity && $equity != 0.0 ? $netDebt / $equity : null,
            'revenue_growth' => $growth,
            'eps_growth_yoy' => $this->yoyGrowthPercent($epsSeries),
            'net_income_growth_yoy' => $this->yoyGrowthPercent($netIncomeSeries),
            'operating_margin' => $revenueTtm && $operatingProfitTtm !== null ? ($operatingProfitTtm / $revenueTtm) * 100 : null,
            'net_margin' => $revenueTtm && $netIncome !== null ? ($netIncome / $revenueTtm) * 100 : null,
            'fcf_margin' => $revenueTtm && $fcfTtm !== null ? ($fcfTtm / $revenueTtm) * 100 : null,
            'ocf_to_net_income_ratio' => $netIncome !== null && $ocfTtm !== null && $netIncome != 0.0 ? $ocfTtm / $netIncome : null,
            'pe_ratio' => $close !== null && $epsTtm !== null && $epsTtm > 0 ? $close / $epsTtm : null,
            'pb_ratio' => $close !== null && $equityLatest !== null && $equityLatest > 0 ? $close / $equityLatest : null,
            'current_ratio' => $currentAssets !== null && $currentLiabilities !== null && $currentLiabilities > 0
                ? $currentAssets / $currentLiabilities
                : null,
            'gross_margin' => $revenueTtm && $grossProfitTtm !== null ? ($grossProfitTtm / $revenueTtm) * 100 : null,
            ...$this->bankMetrics->metricsFromFactRows($facts, $asOf),
        ];
    }

    /** @param list<array<string,mixed>> $series */
    private function yoyGrowthPercent(array $series): ?float
    {
        $current = $series[0] ?? null;
        if ($current === null || ! is_numeric($current['value'] ?? null)) {
            return null;
        }
        $previous = $this->historicalPeriodNear($series, Carbon::parse($current['period_end'])->subYear());
        if ($previous === null || ! is_numeric($previous['value'] ?? null) || (float) $previous['value'] === 0.0) {
            return null;
        }
        return (((float) $current['value'] - (float) $previous['value']) / abs((float) $previous['value'])) * 100;
    }

    private function historicalTtm(array $series): ?float
    {
        $current = $series[0] ?? null;
        $sum = 0.0;
        for ($i = 0; $i < 4; $i++) {
            if ($current === null || ! is_numeric($current['value'] ?? null)) {
                return null;
            }
            $sum += (float) $current['value'];
            $current = $this->historicalPeriodNear($series, Carbon::parse($current['period_end'])->subMonths(3));
        }
        return $sum;
    }

    private function historicalPeriodNear(array $series, Carbon $target): ?array
    {
        foreach ($series as $fact) {
            if (abs(Carbon::parse($fact['period_end'])->diffInDays($target)) <= 20) {
                return $fact;
            }
        }
        return null;
    }

    /** @return array{version:string,cadence:string,anchor:string,horizon_days:int} */
    public function samplingPolicy(string $horizon): array
    {
        $horizonDays = ['1m' => 21, '3m' => 63, '6m' => 126][$horizon] ?? throw new RuntimeException('Unsupported ML horizon.');

        return $this->samplingPolicyForHorizon($horizonDays);
    }

    /** @return array{version:string,cadence:string,anchor:string,horizon_days:int} */
    private function samplingPolicyForHorizon(int $horizonDays): array
    {
        return [
            'version' => 'v8-horizon-reference-1',
            'cadence' => $horizonDays === 21 ? 'weekly' : 'monthly',
            'anchor' => 'last_available_trading_day',
            'horizon_days' => $horizonDays,
        ];
    }

    /** @param list<string> $dates @return list<string> */
    private function referenceDatesForHorizon(array $dates, int $horizonDays): array
    {
        $buckets = [];
        foreach ($dates as $date) {
            $key = $horizonDays === 21
                ? Carbon::parse($date)->format('o-\\WW')
                : substr($date, 0, 7);
            $buckets[$key] = $date;
        }
        ksort($buckets);
        return array_values($buckets);
    }

    private function returnOver(array $prices, string $date, int $lookback, ?array $index = null): ?float
    {
        $dates = array_keys($prices);
        $position = $index[$date] ?? array_search($date, $dates, true);
        if ($position === false || $position === null || $position < $lookback || (float) $prices[$dates[$position - $lookback]] == 0.0) {
            return null;
        }

        return (($prices[$date] - $prices[$dates[$position - $lookback]]) / $prices[$dates[$position - $lookback]]) * 100;
    }

    private function sma(array $prices, string $date, int $period, ?array $index = null): ?float
    {
        $dates = array_keys($prices);
        $position = $index[$date] ?? array_search($date, $dates, true);
        if ($position === false || $position === null || $position + 1 < $period) {
            return null;
        }
        return array_sum(array_slice(array_values($prices), $position - $period + 1, $period)) / $period;
    }

    private function closeAtOrBefore(array $prices, string $date, ?array $dates = null): ?float
    {
        $dates ??= array_keys($prices);
        $low = 0;
        $high = count($dates) - 1;
        $best = null;
        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);
            if ($dates[$middle] <= $date) {
                $best = $dates[$middle];
                $low = $middle + 1;
            } else {
                $high = $middle - 1;
            }
        }
        return $best === null ? null : (float) ($prices[$best] ?? null);
    }

    private function maxDrawdown(array $prices, int $start, int $end, float $entry, array $dates = []): float
    {
        $values = $dates === [] ? array_values($prices) : array_map(fn (string $date): float => (float) ($prices[$date] ?? $entry), array_slice($dates, $start, $end - $start + 1));
        $minimum = min($values);
        return ($minimum - $entry) / $entry;
    }

    private function firstAfter(array $dates, string $date): ?string
    {
        foreach ($dates as $candidate) {
            if ($candidate > $date) {
                return $candidate;
            }
        }
        return null;
    }

    public function benchmarkMappingDefinition(): array
    {
        return [
            'version' => 'v7-contextual-benchmark-1',
            'default' => 'NIFTY50',
            'sector_overrides' => (array) config('ml.benchmark_mapping.sector_overrides', []),
            'fallback_reason' => 'no approved sector/index mapping is configured for this universe',
        ];
    }

    public function benchmarkFor(Stock $stock): ?Stock
    {
        $symbol = (array) config('ml.benchmark_mapping.sector_overrides', []);
        $benchmarkSymbol = $symbol[$stock->sector] ?? config('ml.benchmark_mapping.default', 'NIFTY50');

        return Stock::query()->where('symbol', $benchmarkSymbol)->where('is_benchmark', true)->first()
            ?? Stock::query()->where('symbol', 'NIFTY50')->where('is_benchmark', true)->first();
    }

    /** @return array{relative_return:float,max_drawdown:float,success:bool}|null */
    public function realizedOutcome(Stock $stock, Carbon $asOf, string $horizon, Carbon $evaluationDate): ?array
    {
        $days = ['1m' => 21, '3m' => 63, '6m' => 126][$horizon] ?? null;
        $benchmark = $this->benchmarkFor($stock);
        if ($days === null || $benchmark === null) {
            return null;
        }
        $prices = $this->labelPrices($stock, $evaluationDate);
        $benchmarkPrices = $this->labelPrices($benchmark, $evaluationDate);
        $dates = array_keys($prices);
        $index = array_search($asOf->toDateString(), $dates, true);
        if ($index === false || ! isset($dates[$index + $days])) {
            return null;
        }
        $endDate = $dates[$index + $days];
        $entry = $prices[$dates[$index]] ?? null;
        $future = $prices[$endDate] ?? null;
        $benchmarkEntry = $this->closeAtOrBefore($benchmarkPrices, $dates[$index]);
        $benchmarkFuture = $this->closeAtOrBefore($benchmarkPrices, $endDate);
        if (! $entry || ! $future || ! $benchmarkEntry || ! $benchmarkFuture) {
            return null;
        }
        $relative = (($future - $entry) / $entry) - (($benchmarkFuture - $benchmarkEntry) / $benchmarkEntry);
        $drawdown = $this->maxDrawdown($prices, $index, $index + $days, (float) $entry);
        return ['relative_return' => $relative, 'max_drawdown' => $drawdown, 'success' => $relative > 0 && $drawdown >= -0.20];
    }

    private function labelPrices(Stock $stock, Carbon $to): array
    {
        return StockPrice::query()->where('stock_id', $stock->id)->whereDate('price_date', '<=', $to->toDateString())->orderBy('price_date')->get(['price_date', 'adjusted_close_price', 'close_price'])->mapWithKeys(fn (StockPrice $price): array => [$price->price_date->toDateString() => (float) ($price->adjusted_close_price ?? $price->close_price)])->all();
    }
}
