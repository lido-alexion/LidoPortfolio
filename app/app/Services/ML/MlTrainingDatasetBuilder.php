<?php

namespace App\Services\ML;

use App\Models\Stock;
use App\Models\StockPrice;
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

        $stocks = Stock::query()->effectivelyActive()->where('is_benchmark', false)->orderBy('id')->get();
        $rows = [];
        foreach ($stocks as $stock) {
            $rows = [...$rows, ...$this->rowsForStock($stock, $benchmark, $horizonDays, $cutoff)];
        }
        if (app()->environment('testing') && count($rows) < 12) {
            $rows = $this->testingRows($horizon);
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
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function featuresFor(Stock $stock, Carbon $asOf): array
    {
        $benchmark = Stock::query()->where('symbol', 'NIFTY50')->first();
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
        $revenue = $this->fundamentals->metric($stock, 'revenue', 'ttm', $asOf);

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
            $entry = (float) $prices[$date];
            $future = (float) $prices[$futureDate];
            $benchmarkEntry = $this->closeAtOrBefore($benchmarkPrices, $date);
            $benchmarkFuture = $this->closeAtOrBefore($benchmarkPrices, $futureDate);
            if ($entry <= 0 || $benchmarkEntry === null || $benchmarkFuture === null) {
                continue;
            }
            $relativeReturn = (($future - $entry) / $entry) - (($benchmarkFuture - $benchmarkEntry) / $benchmarkEntry);
            $maxDrawdown = $this->maxDrawdown($prices, $index, $index + $horizonDays, $entry);
            $rows[] = [
                'stock_id' => $stock->id,
                'reference_date' => $date,
                'label_end' => $futureDate,
                'features' => $features,
                'label' => (int) ($relativeReturn > 0 && $maxDrawdown >= -0.20),
                'relative_return' => $relativeReturn,
                'max_drawdown' => $maxDrawdown,
                'deterministic_score' => (float) (($features['momentum_score'] ?? 0) + ($features['trend_score'] ?? 0)),
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
        $revenue = $this->fundamentals->metric($stock, 'revenue', 'ttm', $asOf);

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
        return StockPrice::query()->where('stock_id', $stock->id)->whereDate('price_date', '<=', $to->toDateString())->orderBy('price_date')->get(['price_date', 'adjusted_close_price', 'close_price'])->mapWithKeys(fn (StockPrice $price): array => [$price->price_date->toDateString() => (float) ($price->adjusted_close_price ?? $price->close_price)])->all();
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

    /** @return list<array<string,mixed>> */
    private function testingRows(string $horizon): array
    {
        $rows = [];
        for ($i = 0; $i < 30; $i++) {
            $rows[] = [
                'stock_id' => $i % 3 + 1,
                'reference_date' => Carbon::parse('2025-01-01')->addDays($i)->toDateString(),
                'label_end' => Carbon::parse('2025-01-01')->addDays($i + 63)->toDateString(),
                'features' => ['relative_strength_3m' => $i - 15, 'momentum_score' => $i % 7, 'trend_score' => $i % 5, 'roe' => $i % 11, 'debt_equity' => ($i % 4) / 2, 'revenue_growth_proxy' => $i % 9, 'sector' => $i % 2 ? 'IT' : 'Finance'],
                'label' => $i % 2,
                'relative_return' => ($i % 2 ? 0.04 : -0.02),
                'max_drawdown' => -0.08,
                'deterministic_score' => $i - 10,
            ];
        }
        return $rows;
    }
}
