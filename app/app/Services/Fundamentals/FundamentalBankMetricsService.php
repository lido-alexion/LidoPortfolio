<?php

namespace App\Services\Fundamentals;

use App\Models\Stock;
use Carbon\Carbon;

/**
 * FEAT-054 §6.2 / FEAT-057 §8.11 — bank/NBFC metrics without imputing zeros when absent.
 */
class FundamentalBankMetricsService
{
    /** @var list<string> */
    public const METRIC_KEYS = [
        'gross_npa_ratio',
        'net_npa_ratio',
        'capital_adequacy_ratio',
        'net_interest_margin',
    ];

    /** @var list<string> */
    public const CANONICAL_FACT_KEYS = [
        'interest_income',
        'interest_expense',
        'net_interest_income',
        'gross_npa',
        'gross_npa_ratio',
        'net_npa',
        'net_npa_ratio',
        'capital_adequacy_ratio',
        'provisions',
        'net_interest_margin',
    ];

    public function __construct(
        private readonly FundamentalDataService $fundamentals,
    ) {}

    /**
     * @return array{
     *   gross_npa_ratio:?float,
     *   net_npa_ratio:?float,
     *   capital_adequacy_ratio:?float,
     *   net_interest_margin:?float
     * }
     */
    public function metricsForStock(Stock $stock, Carbon $asOf): array
    {
        $facts = $this->fundamentals->factMap($stock, FundamentalDataService::CADENCE_QUARTERLY, $asOf);
        $ttm = fn (string $key): ?float => $this->fundamentals->ttmFlowSum($stock, $key, $asOf);

        return $this->resolve($facts, $ttm);
    }

    /**
     * Point-in-time metrics from streamed training fact rows.
     *
     * @param  list<array<string,mixed>>  $facts
     * @return array{
     *   gross_npa_ratio:?float,
     *   net_npa_ratio:?float,
     *   capital_adequacy_ratio:?float,
     *   net_interest_margin:?float
     * }
     */
    public function metricsFromFactRows(array $facts, string $asOf): array
    {
        $latestByKey = $this->latestFactsByKey($facts, $asOf);
        $seriesByKey = $this->periodSeriesByKey($facts, $asOf);
        $ttm = fn (string $key): ?float => $this->ttmSumFromSeries($seriesByKey[$key] ?? []);

        return $this->resolve($latestByKey, $ttm);
    }

    /**
     * @param  array<string,\App\Models\V7\FundamentalFact|array<string,mixed>>  $latestByKey
     * @param  callable(string): ?float  $ttm
     * @return array{
     *   gross_npa_ratio:?float,
     *   net_npa_ratio:?float,
     *   capital_adequacy_ratio:?float,
     *   net_interest_margin:?float
     * }
     */
    private function resolve(array $latestByKey, callable $ttm): array
    {
        $read = function (string $key) use ($latestByKey): ?float {
            $row = $latestByKey[$key] ?? null;
            if ($row === null) {
                return null;
            }
            $value = is_array($row) ? ($row['value'] ?? null) : $row->value;

            return is_numeric($value) ? (float) $value : null;
        };

        $grossNpaRatio = $read('gross_npa_ratio');
        $netNpaRatio = $read('net_npa_ratio');
        $car = $read('capital_adequacy_ratio');
        $nim = $read('net_interest_margin');

        if ($nim === null) {
            $nii = $ttm('net_interest_income');
            $interestIncome = $ttm('interest_income');
            if ($nii !== null && $interestIncome !== null && $interestIncome > 0) {
                $nim = ($nii / $interestIncome) * 100;
            }
        }

        return [
            'gross_npa_ratio' => $grossNpaRatio,
            'net_npa_ratio' => $netNpaRatio,
            'capital_adequacy_ratio' => $car,
            'net_interest_margin' => $nim,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $facts
     * @return array<string, array<string,mixed>>
     */
    private function latestFactsByKey(array $facts, string $asOf): array
    {
        $byKeyPeriod = [];
        foreach ($facts as $fact) {
            if (($fact['availability_date'] ?? '') > $asOf || ($fact['period_end'] ?? null) === null) {
                continue;
            }
            $key = ($fact['fact_key'] ?? '').':'.($fact['period_end'] ?? '');
            $current = $byKeyPeriod[$key] ?? null;
            if ($current === null || [$fact['availability_date'], $fact['revision_number'] ?? 0] > [$current['availability_date'], $current['revision_number'] ?? 0]) {
                $byKeyPeriod[$key] = $fact;
            }
        }
        $byKey = [];
        foreach ($byKeyPeriod as $fact) {
            $factKey = (string) ($fact['fact_key'] ?? '');
            $existing = $byKey[$factKey] ?? null;
            if ($existing === null || strcmp((string) $fact['period_end'], (string) $existing['period_end']) > 0) {
                $byKey[$factKey] = $fact;
            }
        }

        return $byKey;
    }

    /**
     * @param  list<array<string,mixed>>  $facts
     * @return array<string, list<array<string,mixed>>>
     */
    private function periodSeriesByKey(array $facts, string $asOf): array
    {
        $byKeyPeriod = [];
        foreach ($facts as $fact) {
            if (($fact['availability_date'] ?? '') > $asOf || ($fact['period_end'] ?? null) === null) {
                continue;
            }
            $key = ($fact['fact_key'] ?? '').':'.($fact['period_end'] ?? '');
            $current = $byKeyPeriod[$key] ?? null;
            if ($current === null || [$fact['availability_date'], $fact['revision_number'] ?? 0] > [$current['availability_date'], $current['revision_number'] ?? 0]) {
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

        return $byKey;
    }

    /**
     * @param  list<array<string,mixed>>  $series
     */
    private function ttmSumFromSeries(array $series): ?float
    {
        if (count($series) < 4) {
            return null;
        }
        for ($i = 1; $i < 4; $i++) {
            if (! isset($series[$i - 1]['period_end'], $series[$i]['period_end'])) {
                return null;
            }
            $expected = Carbon::parse((string) $series[$i - 1]['period_end'])->subMonths(3);
            $actual = Carbon::parse((string) $series[$i]['period_end']);
            if ($expected->diffInDays($actual) > 20) {
                return null;
            }
        }
        $sum = 0.0;
        for ($i = 0; $i < 4; $i++) {
            if (! is_numeric($series[$i]['value'] ?? null)) {
                return null;
            }
            $sum += (float) $series[$i]['value'];
        }

        return $sum;
    }
}
