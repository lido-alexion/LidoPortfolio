<?php

namespace App\Services\Lending;

/**
 * OD-08 lender ranking (frozen).
 *
 * After eligibility filtering only:
 *   1. available-for-lending percentage descending (amount / allocated_capital; 0 if allocated is 0)
 *   2. available-for-lending amount descending
 *   3. exact tie: strategy_id ascending (deterministic; spec allows any tied lender — no product meaning)
 *
 * Not return-quality ranking, not OD-23 fill order, not OD-09 loan FIFO.
 */
final class LenderRankingService
{
    /**
     * @param  list<array<string, mixed>>  $lenders
     * @return list<array<string, mixed>>
     */
    public function rank(array $lenders): array
    {
        if (count($lenders) <= 1) {
            return array_values($lenders);
        }

        $sorted = $lenders;
        usort($sorted, function (array $a, array $b): int {
            $pctA = $this->lendablePercentage($a);
            $pctB = $this->lendablePercentage($b);
            if ($pctA !== $pctB) {
                return $pctB <=> $pctA;
            }

            $amtA = $this->lendableAmount($a);
            $amtB = $this->lendableAmount($b);
            if ($amtA !== $amtB) {
                return $amtB <=> $amtA;
            }

            return ((int) ($a['strategy_id'] ?? 0)) <=> ((int) ($b['strategy_id'] ?? 0));
        });

        return array_values($sorted);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function lendablePercentage(array $row): float
    {
        $allocated = (float) ($row['strategy_capital_allocation'] ?? 0);
        if ($allocated <= 0) {
            return 0.0;
        }

        return $this->lendableAmount($row) / $allocated;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function lendableAmount(array $row): float
    {
        return (float) ($row['available_for_lending'] ?? 0);
    }
}
