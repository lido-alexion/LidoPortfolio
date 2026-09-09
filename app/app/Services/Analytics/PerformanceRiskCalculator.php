<?php

namespace App\Services\Analytics;

final class PerformanceRiskCalculator
{
    public const MIN_RISK_OBSERVATIONS = 30;

    /**
     * @param  array<int, array{date:string,value:float|int|null,external_flow?:float|int,complete?:bool}>  $observations
     * @return array<string, mixed>
     */
    public function calculate(array $observations, float $annualRiskFreeRate = 0.0, int $annualizationDays = 252): array
    {
        usort($observations, fn (array $a, array $b): int => strcmp($a['date'], $b['date']));

        $incomplete = collect($observations)->contains(
            fn (array $row): bool => ($row['complete'] ?? true) !== true || $row['value'] === null
        );

        $dailyReturns = [];
        $linkedGrowth = 1.0;

        for ($i = 1, $count = count($observations); $i < $count; $i++) {
            $previous = $observations[$i - 1];
            $current = $observations[$i];

            if ($previous['value'] === null || $current['value'] === null || (float) $previous['value'] <= 0.0) {
                $incomplete = true;
                continue;
            }

            // External flows are assumed to occur at the end of the observation day.
            $return = (((float) $current['value'] - (float) ($current['external_flow'] ?? 0.0))
                / (float) $previous['value']) - 1.0;
            $dailyReturns[] = $return;
            $linkedGrowth *= 1.0 + $return;
        }

        $twr = $dailyReturns === [] ? null : ($linkedGrowth - 1.0) * 100.0;
        $maximumDrawdown = $this->maximumDrawdown($dailyReturns);
        $riskReady = count($dailyReturns) >= self::MIN_RISK_OBSERVATIONS;

        $volatility = null;
        $sharpe = null;
        if ($riskReady) {
            $standardDeviation = $this->sampleStandardDeviation($dailyReturns);
            $volatility = $standardDeviation * sqrt($annualizationDays) * 100.0;
            if ($standardDeviation > 0.0) {
                $dailyRiskFreeRate = pow(1.0 + $annualRiskFreeRate, 1.0 / $annualizationDays) - 1.0;
                $sharpe = (($this->mean($dailyReturns) - $dailyRiskFreeRate) / $standardDeviation)
                    * sqrt($annualizationDays);
            }
        }

        return [
            'twr_percent' => $this->rounded($twr),
            'volatility_percent' => $this->rounded($volatility),
            'maximum_drawdown_percent' => $this->rounded($maximumDrawdown),
            'sharpe_ratio' => $this->rounded($sharpe),
            'observation_count' => count($dailyReturns),
            'risk_minimum_observations' => self::MIN_RISK_OBSERVATIONS,
            'annualization_days' => $annualizationDays,
            'annual_risk_free_rate' => $annualRiskFreeRate,
            'completeness' => $incomplete
                ? 'incomplete'
                : ($riskReady ? 'complete' : 'estimate_with_limitations'),
            'limitations' => $riskReady ? [] : ['risk_metrics_require_30_daily_returns'],
        ];
    }

    /** @param array<int, float> $returns */
    private function maximumDrawdown(array $returns): ?float
    {
        if ($returns === []) {
            return null;
        }

        $index = 1.0;
        $peak = 1.0;
        $maximum = 0.0;

        foreach ($returns as $return) {
            $index *= 1.0 + $return;
            $peak = max($peak, $index);
            $maximum = max($maximum, ($peak - $index) / $peak);
        }

        return -$maximum * 100.0;
    }

    /** @param array<int, float> $values */
    private function mean(array $values): float
    {
        return array_sum($values) / count($values);
    }

    /** @param array<int, float> $values */
    private function sampleStandardDeviation(array $values): float
    {
        $mean = $this->mean($values);
        $sum = array_sum(array_map(fn (float $value): float => ($value - $mean) ** 2, $values));

        return sqrt($sum / (count($values) - 1));
    }

    private function rounded(?float $value): ?float
    {
        return $value === null ? null : round($value, 6);
    }
}
