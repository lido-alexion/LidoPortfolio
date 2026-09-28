<?php

namespace App\Services\ML;

/**
 * FEAT-057 — repeated chronological validation evidence from partitioned JSONL datasets.
 */
class MlChronologicalValidationGridService
{
    private const BENCHMARK_VOL_FEATURE = 'benchmark_realized_volatility_20d';

    /**
     * @param  array{train:string,validation:string,test:string}  $paths
     * @param  array<string,mixed>  $partitions
     * @return array<string,mixed>
     */
    public function summarize(array $paths, array $partitions): array
    {
        $windows = [];
        foreach (['train', 'validation', 'test'] as $partition) {
            $path = $paths[$partition] ?? null;
            if (! is_string($path) || ! is_file($path)) {
                continue;
            }
            foreach ($this->monthlyWindowsForFile($path, $partition) as $window) {
                $windows[] = $window;
            }
        }

        $testWindows = array_values(array_filter($windows, fn (array $w): bool => $w['partition'] === 'test'));
        $positiveRates = array_map(fn (array $w): float => (float) $w['positive_label_rate'], $testWindows);
        $stability = $this->stabilityScore($positiveRates);
        $regimeSlices = $this->regimeSlicesForTestWindows($testWindows);

        return [
            'version' => 'v8-chrono-grid-2',
            'partition_bounds' => [
                'train_start' => $partitions['train_start'] ?? null,
                'train_end' => $partitions['train_end'] ?? null,
                'validation_start' => $partitions['validation_start'] ?? null,
                'validation_end' => $partitions['validation_end'] ?? null,
                'test_start' => $partitions['test_start'] ?? null,
                'test_end' => $partitions['test_end'] ?? null,
            ],
            'windows' => $windows,
            'test_window_count' => count($testWindows),
            'test_positive_rate_stability' => $stability,
            'regime_slices' => $regimeSlices,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $testWindows
     * @return array<string, mixed>
     */
    private function regimeSlicesForTestWindows(array $testWindows): array
    {
        $vols = [];
        foreach ($testWindows as $window) {
            $vol = $window['mean_benchmark_volatility_20d'] ?? null;
            if (is_numeric($vol)) {
                $vols[] = (float) $vol;
            }
        }
        if ($vols === []) {
            return [
                'benchmark_volatility_feature' => self::BENCHMARK_VOL_FEATURE,
                'status' => 'unavailable',
            ];
        }

        $threshold = array_sum($vols) / count($vols);
        $slices = [
            'high_benchmark_volatility' => ['window_count' => 0, 'mean_positive_label_rate' => null, 'mean_benchmark_relative_return' => null],
            'low_benchmark_volatility' => ['window_count' => 0, 'mean_positive_label_rate' => null, 'mean_benchmark_relative_return' => null],
        ];
        $positiveBuckets = ['high_benchmark_volatility' => [], 'low_benchmark_volatility' => []];
        $returnBuckets = ['high_benchmark_volatility' => [], 'low_benchmark_volatility' => []];

        foreach ($testWindows as $window) {
            $vol = $window['mean_benchmark_volatility_20d'] ?? null;
            if (! is_numeric($vol)) {
                continue;
            }
            $key = (float) $vol >= $threshold ? 'high_benchmark_volatility' : 'low_benchmark_volatility';
            $slices[$key]['window_count']++;
            if (isset($window['positive_label_rate']) && is_numeric($window['positive_label_rate'])) {
                $positiveBuckets[$key][] = (float) $window['positive_label_rate'];
            }
            if (isset($window['mean_benchmark_relative_return']) && is_numeric($window['mean_benchmark_relative_return'])) {
                $returnBuckets[$key][] = (float) $window['mean_benchmark_relative_return'];
            }
        }

        foreach (['high_benchmark_volatility', 'low_benchmark_volatility'] as $key) {
            if ($positiveBuckets[$key] !== []) {
                $slices[$key]['mean_positive_label_rate'] = round(array_sum($positiveBuckets[$key]) / count($positiveBuckets[$key]), 6);
            }
            if ($returnBuckets[$key] !== []) {
                $slices[$key]['mean_benchmark_relative_return'] = round(array_sum($returnBuckets[$key]) / count($returnBuckets[$key]), 6);
            }
        }

        return [
            'benchmark_volatility_feature' => self::BENCHMARK_VOL_FEATURE,
            'status' => 'ok',
            'split_benchmark_volatility_20d' => round($threshold, 6),
            'slices' => $slices,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function monthlyWindowsForFile(string $path, string $partition): array
    {
        $buckets = [];
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }
        try {
            while (($line = fgets($handle)) !== false) {
                $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                $month = substr((string) ($row['reference_date'] ?? ''), 0, 7);
                if ($month === '') {
                    continue;
                }
                if (! isset($buckets[$month])) {
                    $buckets[$month] = [
                        'rows' => 0,
                        'positives' => 0,
                        'vol_sum' => 0.0,
                        'vol_count' => 0,
                        'return_sum' => 0.0,
                        'return_count' => 0,
                    ];
                }
                $buckets[$month]['rows']++;
                if ((int) ($row['label'] ?? 0) === 1) {
                    $buckets[$month]['positives']++;
                }
                $features = is_array($row['features'] ?? null) ? $row['features'] : [];
                $vol = $features[self::BENCHMARK_VOL_FEATURE] ?? null;
                if (is_numeric($vol)) {
                    $buckets[$month]['vol_sum'] += (float) $vol;
                    $buckets[$month]['vol_count']++;
                }
                $relativeReturn = $row['relative_return'] ?? null;
                if (is_numeric($relativeReturn)) {
                    $buckets[$month]['return_sum'] += (float) $relativeReturn;
                    $buckets[$month]['return_count']++;
                }
            }
        } finally {
            fclose($handle);
        }

        $windows = [];
        foreach ($buckets as $month => $stats) {
            $rows = (int) $stats['rows'];
            $windows[] = [
                'partition' => $partition,
                'month' => $month,
                'row_count' => $rows,
                'positive_label_rate' => $rows > 0 ? round($stats['positives'] / $rows, 6) : null,
                'mean_benchmark_volatility_20d' => $stats['vol_count'] > 0
                    ? round($stats['vol_sum'] / $stats['vol_count'], 6)
                    : null,
                'mean_benchmark_relative_return' => $stats['return_count'] > 0
                    ? round($stats['return_sum'] / $stats['return_count'], 6)
                    : null,
            ];
        }
        usort($windows, static fn (array $a, array $b): int => strcmp((string) $a['month'], (string) $b['month']));

        return $windows;
    }

    /**
     * @param  list<float>  $rates
     * @return array{mean:?float,std_dev:?float,sample_count:int}
     */
    private function stabilityScore(array $rates): array
    {
        if ($rates === []) {
            return ['mean' => null, 'std_dev' => null, 'sample_count' => 0];
        }
        $mean = array_sum($rates) / count($rates);
        $variance = 0.0;
        foreach ($rates as $rate) {
            $variance += ($rate - $mean) ** 2;
        }
        $variance /= count($rates);

        return [
            'mean' => round($mean, 6),
            'std_dev' => round(sqrt($variance), 6),
            'sample_count' => count($rates),
        ];
    }
}
