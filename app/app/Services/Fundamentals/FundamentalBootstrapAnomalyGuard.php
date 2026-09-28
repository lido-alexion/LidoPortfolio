<?php

namespace App\Services\Fundamentals;

class FundamentalBootstrapAnomalyGuard
{
    /**
     * @param  array<string, mixed>  $row
     */
    public function accepts(array $row): bool
    {
        $value = $row['value'] ?? null;
        if ($value === null || $value === '') {
            return false;
        }

        if (! is_numeric($value)) {
            return false;
        }

        $numeric = (float) $value;
        if (! is_finite($numeric)) {
            return false;
        }

        $factKey = (string) ($row['fact_key'] ?? '');
        if (str_contains($factKey, 'margin') || str_contains($factKey, 'ratio') || str_contains($factKey, 'yield')) {
            if ($numeric < -500 || $numeric > 500) {
                return false;
            }
        }

        if (str_contains($factKey, 'shares') && $numeric <= 0) {
            return false;
        }

        $periodEnd = (string) ($row['period_end'] ?? '');
        if ($periodEnd === '' || strtotime($periodEnd) === false) {
            return false;
        }

        $cadence = (string) ($row['cadence'] ?? '');
        if (! in_array($cadence, [FundamentalDataService::CADENCE_QUARTERLY, FundamentalDataService::CADENCE_ANNUAL], true)) {
            return false;
        }

        return true;
    }
}
