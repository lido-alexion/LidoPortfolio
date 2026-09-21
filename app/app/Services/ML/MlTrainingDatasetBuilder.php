<?php

namespace App\Services\ML;

use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\V7\FundamentalFact;
use App\Services\Fundamentals\FundamentalDataService;
use Carbon\Carbon;
use RuntimeException;

class MlTrainingDatasetBuilder
{
    public const NUMERIC_FEATURES = [
        'relative_strength_3m',
        'momentum_score',
        'trend_score',
        'roe',
        'debt_equity',
        'revenue_growth_proxy',
    ];

    public const CATEGORICAL_FEATURES = ['sector'];

    public function __construct(private readonly FundamentalDataService $fundamentals) {}

    /** @return array{rows:list<array<string,mixed>>,partitions:array<string,mixed>,feature_definitions:array<string,mixed>} */
    public function build(string $horizon, Carbon $cutoff): array
    {
        $horizonDays = ['1m' => 21, '3m' => 63, '6m' => 126][$horizon] ?? throw new RuntimeException('Unsupported ML horizon.');
        $benchmark = Stock::query()->where('symbol', 'NIFTY50')->first();
        if ($benchmark === null) {
            throw new RuntimeException('Primary benchmark NIFTY50 is unavailable.');
        }

        // Historical training must not apply today's active flag.  A security
        // with valid historical observations remains eligible for its observed
        // period, while benchmark/index rows are never issuer examples.
        $stockQuery = Stock::query()
            ->where(function ($query): void {
                $query->where('is_benchmark', false)->orWhereNull('is_benchmark');
            })
            ->where('exchange', 'NSE')
            ->whereExists(fn ($query) => $query->selectRaw('1')
                ->from('portfolio_stock_prices')
                ->whereColumn('portfolio_stock_prices.stock_id', 'portfolio_stocks.id'))
            ->orderBy('id');
        $rows = [];
        $benchmarkSeries = $this->priceSeries($benchmark, $cutoff);
        $stockQuery->chunkById(100, function ($stockChunk) use (&$rows, $benchmarkSeries, $horizonDays, $cutoff): void {
            foreach ($stockChunk as $stock) {
                $series = $this->priceSeries($stock, $cutoff);
                if ($series['dates'] === []) {
                    continue;
                }
                $facts = $this->fundamentalFacts($stock, $cutoff);
                foreach ($this->rowsForStock($stock, $series, $benchmarkSeries, $facts, $horizonDays, $cutoff) as $row) {
                    $rows[] = $row;
                }
            }
        }, 'id');
        if (count($rows) < 12) {
            throw new RuntimeException('Insufficient point-in-time training examples.');
        }

        usort($rows, fn (array $a, array $b): int => strcmp($a['reference_date'], $b['reference_date']) ?: ($a['stock_id'] <=> $b['stock_id']));
        $dates = array_values(array_unique(array_column($rows, 'reference_date')));
        $trainEnd = $dates[max(0, (int) floor(count($dates) * 0.70) - 1)];
        $validationEnd = $dates[max(0, (int) floor(count($dates) * 0.85) - 1)];
        foreach ($rows as &$row) {
            $row['partition'] = $row['reference_date'] <= $trainEnd ? 'train' : ($row['reference_date'] <= $validationEnd ? 'validation' : 'test');
        }
        unset($row);

        $partitions = [
            'train_start' => $dates[0],
            'train_end' => $trainEnd,
            'validation_start' => $this->firstAfter($dates, $trainEnd),
            'validation_end' => $validationEnd,
            'test_start' => $this->firstAfter($dates, $validationEnd),
            'test_end' => $dates[array_key_last($dates)],
            'row_counts' => collect($rows)->countBy('partition')->all(),
            'cutoff_date' => $cutoff->toDateString(),
        ];

        return [
            'rows' => array_values($rows),
            'partitions' => $partitions,
            'feature_definitions' => [
                'version' => 'v7-features-1',
                'numeric' => array_fill_keys(self::NUMERIC_FEATURES, ['missing' => 'median_with_missingness_flags', 'as_of' => 'reference_date']),
                'categorical' => ['sector' => ['encoding' => 'training_partition_categories', 'unknown' => '__unknown']],
                'price_semantics' => [
                    'features' => 'unadjusted_close_as_of_reference_date',
                    'labels' => 'adjusted_close_as_of_observed_label_window',
                    'reason' => 'adjusted_close is retroactively changed by later corporate-action repair; features cannot consume that series.',
                ],
                'universe' => [
                    'version' => 'v7-historical-eligible-nse-1',
                    'rule' => 'non-benchmark NSE stocks with historical price observations through the cutoff, independent of current is_active state',
                ],
                'benchmark_mapping' => $this->benchmarkMappingDefinition(),
                'sampling' => [
                    'version' => 'v7-monthly-reference-1',
                    'cadence' => 'monthly',
                    'anchor' => 'last_available_trading_day',
                ],
            ],
        ];
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
        $stockQuery = Stock::query()
            ->where(function ($query): void { $query->where('is_benchmark', false)->orWhereNull('is_benchmark'); })
            ->where('exchange', 'NSE')
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('portfolio_stock_prices')->whereColumn('portfolio_stock_prices.stock_id', 'portfolio_stocks.id'));
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
            'sampling' => ['version' => 'v7-monthly-reference-1', 'cadence' => 'monthly', 'anchor' => 'last_available_trading_day'],
            'estimated_reference_rows' => $stockCount * $months,
            'date_range' => ['start' => $first, 'end' => $last],
            'expected_partitions' => ['train' => '70%', 'validation' => '15%', 'test' => '15%'],
        ];
    }

    /** @return array<string,mixed> */
    public function featuresFor(Stock $stock, Carbon $asOf): array
    {
        $benchmark = $this->benchmarkFor($stock);
        $stockPrices = $this->prices($stock, $asOf);
        $benchmarkPrices = $benchmark ? $this->prices($benchmark, $asOf) : [];
        $close = $this->closeAtOrBefore($stockPrices, $asOf->toDateString());
        $benchmarkClose = $this->closeAtOrBefore($benchmarkPrices, $asOf->toDateString());
        $stock3m = $this->returnOver($stockPrices, $asOf->toDateString(), 63);
        $benchmark3m = $this->returnOver($benchmarkPrices, $asOf->toDateString(), 63);
        $momentum = $this->returnOver($stockPrices, $asOf->toDateString(), 21);
        $sma = $this->sma($stockPrices, $asOf->toDateString(), 20);
        $roe = $this->fundamentals->metric($stock, 'roe', 'ttm', $asOf);
        $debtEquity = $this->fundamentals->metric($stock, 'debt_equity', 'ttm', $asOf);
        $revenue = $this->fundamentals->growthMetric($stock, 'revenue', 'quarterly', $asOf);

        return [
            'relative_strength_3m' => $stock3m !== null && $benchmark3m !== null ? $stock3m - $benchmark3m : null,
            'momentum_score' => $momentum,
            'trend_score' => $close !== null && $sma !== null && $sma != 0 ? (($close / $sma) - 1) * 100 : null,
            'roe' => $roe['value'],
            'debt_equity' => $debtEquity['value'],
            'revenue_growth_proxy' => $revenue['value'],
            'sector' => $stock->sector ?: '__unknown',
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
        $sampleDates = $this->monthlyReferenceDates($dates);
        $rows = [];
        foreach ($sampleDates as $date) {
            $index = $dateIndex[$date] ?? null;
            if ($index === null) {
                continue;
            }
            if ($index < 63 || ! isset($dates[$index + $horizonDays])) {
                continue;
            }
            $futureDate = $dates[$index + $horizonDays];
            if ($futureDate > $cutoff->toDateString()) {
                continue;
            }
            $features = $this->featuresForPrices($stock, $date, $prices, $benchmarkPrices, $facts, $series['index'], $benchmarkSeries['index']);
            $entry = (float) ($labelPrices[$date] ?? 0);
            $future = (float) ($labelPrices[$futureDate] ?? 0);
            $benchmarkEntry = $this->closeAtOrBefore($benchmarkLabelPrices, $date, $benchmarkSeries['dates']);
            $benchmarkFuture = $this->closeAtOrBefore($benchmarkLabelPrices, $futureDate, $benchmarkSeries['dates']);
            if ($entry <= 0 || $benchmarkEntry === null || $benchmarkFuture === null) {
                continue;
            }
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
    private function featuresForPrices(Stock $stock, string $date, array $prices, array $benchmarkPrices, array $facts, array $priceIndex, array $benchmarkIndex): array
    {
        $stock3m = $this->returnOver($prices, $date, 63, $priceIndex);
        $benchmark3m = $this->returnOver($benchmarkPrices, $date, 63, $benchmarkIndex);
        $momentum = $this->returnOver($prices, $date, 21, $priceIndex);
        $close = $this->closeAtOrBefore($prices, $date, array_keys($priceIndex));
        $sma = $this->sma($prices, $date, 20, $priceIndex);
        $asOf = Carbon::parse($date);
        $metrics = $this->historicalMetrics($facts, $date);

        return [
            'relative_strength_3m' => $stock3m !== null && $benchmark3m !== null ? $stock3m - $benchmark3m : null,
            'momentum_score' => $momentum,
            'trend_score' => $close !== null && $sma !== null && $sma != 0 ? (($close / $sma) - 1) * 100 : null,
            'roe' => $metrics['roe'],
            'debt_equity' => $metrics['debt_equity'],
            'revenue_growth_proxy' => $metrics['revenue_growth'],
            'sector' => $stock->sector ?: '__unknown',
        ];
    }

    /** @return array{features:array<string,float>,labels:array<string,float>,dates:list<string>,index:array<string,int>} */
    private function priceSeries(Stock $stock, Carbon $to): array
    {
        $features = [];
        $labels = [];
        $dates = [];
        StockPrice::query()->where('stock_id', $stock->id)->whereDate('price_date', '<=', $to->toDateString())->orderBy('price_date')->get(['price_date', 'close_price', 'adjusted_close_price'])->each(function (StockPrice $price) use (&$features, &$labels, &$dates): void {
            if ($price->close_price === null) {
                return;
            }
            $date = $price->price_date->toDateString();
            $dates[] = $date;
            $features[$date] = (float) $price->close_price;
            $labels[$date] = (float) ($price->adjusted_close_price ?? $price->close_price);
        });
        return ['features' => $features, 'labels' => $labels, 'dates' => $dates, 'index' => array_flip($dates)];
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

    /** @return array{roe:?float,debt_equity:?float,revenue_growth:?float} */
    private function historicalMetrics(array $facts, string $asOf): array
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
        $sum = static fn (string $key): ?float => isset($byKey[$key]) && $byKey[$key] !== [] ? array_sum(array_slice(array_map(static fn (array $row): float => (float) ($row['value'] ?? 0), $byKey[$key]), 0, 4)) : null;
        $netIncome = $sum('net_income');
        $equity = isset($byKey['equity'][0]) ? (float) ($byKey['equity'][0]['value'] ?? 0) : null;
        $debt = isset($byKey['debt'][0]) ? (float) ($byKey['debt'][0]['value'] ?? 0) : null;
        $revenue = $byKey['revenue'] ?? [];
        $growth = count($revenue) >= 2 && (float) ($revenue[1]['value'] ?? 0) !== 0.0
            ? (((float) ($revenue[0]['value'] ?? 0) - (float) ($revenue[1]['value'] ?? 0)) / abs((float) $revenue[1]['value'])) * 100
            : null;
        return [
            'roe' => $netIncome !== null && $equity ? ($netIncome / $equity) * 100 : null,
            'debt_equity' => $debt !== null && $equity ? $debt / $equity : null,
            'revenue_growth' => $growth,
        ];
    }

    /** @param list<string> $dates */
    private function monthlyReferenceDates(array $dates): array
    {
        $monthly = [];
        foreach ($dates as $date) {
            $monthly[substr($date, 0, 7)] = $date;
        }
        return array_values($monthly);
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
