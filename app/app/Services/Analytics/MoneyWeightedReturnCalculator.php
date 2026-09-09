<?php

namespace App\Services\Analytics;

use Carbon\CarbonImmutable;

final class MoneyWeightedReturnCalculator
{
    /**
     * Positive external flows are contributions; negative flows are withdrawals.
     *
     * @param array<int, array{date:string,amount:float|int}> $externalFlows
     */
    public function calculate(string $periodStart, string $periodEnd, float $openingValue, float $closingValue, array $externalFlows): ?float
    {
        if ($periodStart >= $periodEnd || $openingValue < 0.0 || $closingValue < 0.0) {
            return null;
        }

        $flows = [['date' => $periodStart, 'amount' => -$openingValue]];
        foreach ($externalFlows as $flow) {
            if ($flow['date'] <= $periodStart || $flow['date'] > $periodEnd || (float) $flow['amount'] === 0.0) {
                continue;
            }
            $flows[] = ['date' => $flow['date'], 'amount' => -(float) $flow['amount']];
        }
        $flows[] = ['date' => $periodEnd, 'amount' => $closingValue];

        usort($flows, fn (array $a, array $b): int => strcmp($a['date'], $b['date']));
        if (! collect($flows)->contains(fn (array $flow): bool => $flow['amount'] < 0.0)
            || ! collect($flows)->contains(fn (array $flow): bool => $flow['amount'] > 0.0)) {
            return null;
        }

        $origin = CarbonImmutable::parse($flows[0]['date']);
        $rate = 0.1;
        for ($iteration = 0; $iteration < 100; $iteration++) {
            $npv = 0.0;
            $derivative = 0.0;
            foreach ($flows as $flow) {
                $years = $origin->diffInDays(CarbonImmutable::parse($flow['date'])) / 365.0;
                $base = 1.0 + $rate;
                if ($base <= 0.0) {
                    return null;
                }
                $denominator = $base ** $years;
                $npv += $flow['amount'] / $denominator;
                $derivative -= ($years * $flow['amount']) / ($denominator * $base);
            }

            if (abs($npv) < 0.0000001) {
                return round($rate * 100.0, 6);
            }
            if (abs($derivative) < 0.0000000001) {
                return null;
            }
            $rate = max(-0.9999, $rate - ($npv / $derivative));
        }

        return null;
    }
}
