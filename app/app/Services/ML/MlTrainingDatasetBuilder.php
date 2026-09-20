<?php

namespace App\Services\ML;

use App\Models\Stock;
use App\Models\StockPrice;
use App\Services\Fundamentals\FundamentalDataService;
use App\Services\Backtest\AsOfFactorScorer;
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

    public function __construct(
        private readonly FundamentalDataService $fundamentals,
        private readonly AsOfFactorScorer $baselineScorer,
    ) {}

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
        $stocks = Stock::query()
            ->where(function ($query): void {
                $query->where('is_benchmark', false)->orWhereNull('is_benchmark');
            })
            ->where('exchange', 'NSE')
            ->whereExists(fn ($query) => $query->selectRaw('1')
                ->from('portfolio_stock_prices')
                ->whereColumn('portfolio_stock_prices.stock_id', 'portfolio_stocks.id'))
            ->orderBy('id')
            ->get();
        $rows = [];
        foreach ($stocks as $stock) {
            $rows = [...$rows, ...$this->rowsForStock($stock, $benchmark, $horizonDays, $cutoff)];
        }
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
            ],
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

    /** @return list<array<string,mixed>> */
    private function rowsForStock(Stock $stock, Stock $benchmark, int $horizonDays, Carbon $cutoff): array
    {
        $prices = $this->prices($stock, $cutoff);
        $benchmarkPrices = $this->prices($benchmark, $cutoff);
        $labelPrices = $this->labelPrices($stock, $cutoff);
        $benchmarkLabelPrices = $this->labelPrices($benchmark, $cutoff);
        $dates = array_keys($prices);
        $rows = [];
        foreach ($dates as $index => $date) {
            if ($index < 63 || ! isset($dates[$index + $horizonDays])) {
                continue;
            }
            $futureDate = $dates[$index + $horizonDays];
            if ($futureDate > $cutoff->toDateString()) {
                continue;
            }
            $features = $this->featuresForPrices($stock, $date, $prices, $benchmarkPrices);
            $entry = (float) ($labelPrices[$date] ?? 0);
            $future = (float) ($labelPrices[$futureDate] ?? 0);
            $benchmarkEntry = $this->closeAtOrBefore($benchmarkLabelPrices, $date);
            $benchmarkFuture = $this->closeAtOrBefore($benchmarkLabelPrices, $futureDate);
            if ($entry <= 0 || $benchmarkEntry === null || $benchmarkFuture === null) {
                continue;
            }
            $relativeReturn = (($future - $entry) / $entry) - (($benchmarkFuture - $benchmarkEntry) / $benchmarkEntry);
            $maxDrawdown = $this->maxDrawdown($labelPrices, $index, $index + $horizonDays, $entry);
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
    private function featuresForPrices(Stock $stock, string $date, array $prices, array $benchmarkPrices): array
    {
        $stock3m = $this->returnOver($prices, $date, 63);
        $benchmark3m = $this->returnOver($benchmarkPrices, $date, 63);
        $momentum = $this->returnOver($prices, $date, 21);
        $close = $this->closeAtOrBefore($prices, $date);
        $sma = $this->sma($prices, $date, 20);
        $asOf = Carbon::parse($date);
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
        ];
    }

    /** @return array<string,float> */
    private function prices(Stock $stock, Carbon $to): array
    {
        return StockPrice::query()->where('stock_id', $stock->id)->whereDate('price_date', '<=', $to->toDateString())->orderBy('price_date')->get(['price_date', 'close_price'])->mapWithKeys(fn (StockPrice $price): array => [$price->price_date->toDateString() => (float) $price->close_price])->all();
    }

    private function returnOver(array $prices, string $date, int $lookback): ?float
    {
        $dates = array_keys($prices);
        $index = array_search($date, $dates, true);
        if ($index === false || $index < $lookback || (float) $prices[$dates[$index - $lookback]] == 0.0) {
            return null;
        }

        return (($prices[$date] - $prices[$dates[$index - $lookback]]) / $prices[$dates[$index - $lookback]]) * 100;
    }

    private function sma(array $prices, string $date, int $period): ?float
    {
        $dates = array_keys($prices);
        $index = array_search($date, $dates, true);
        if ($index === false || $index + 1 < $period) {
            return null;
        }
        return array_sum(array_slice(array_values($prices), $index - $period + 1, $period)) / $period;
    }

    private function closeAtOrBefore(array $prices, string $date): ?float
    {
        $value = null;
        foreach ($prices as $priceDate => $close) {
            if ($priceDate > $date) {
                break;
            }
            $value = (float) $close;
        }
        return $value;
    }

    private function maxDrawdown(array $prices, int $start, int $end, float $entry): float
    {
        $minimum = min(array_slice(array_values($prices), $start, $end - $start + 1));
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

    public function deterministicScore(int $stockId, string $date): ?float
    {
        $result = $this->baselineScorer->score($stockId, $date);
        return $result['skipped'] ? null : (float) $result['score'];
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
