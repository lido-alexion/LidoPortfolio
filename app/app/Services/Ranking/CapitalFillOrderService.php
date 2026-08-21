<?php

namespace App\Services\Ranking;

/**
 * OD-23 capital fill order (frozen).
 *
 * Applies ONLY when V3 return-quality ranking is NOT computable.
 * This is a deterministic capital fill order, NOT V3 ranking.
 *
 * Order:
 *   1. target_amount DESC  (conviction / target investment amount)
 *   2. fit_score DESC      (strategy fit score)
 *   3. symbol ASC          (stock listing symbol, alphabetical)
 *
 * Does NOT:
 *  - calculate return quality or query backtest data
 *  - allocate capital or mutate recommendations/holdings/cash
 *  - use conviction sub-score (removed from V3 spec)
 *  - use ranking statistical confidence as a tie-break
 */
final class CapitalFillOrderService
{
    /**
     * Sort candidates into OD-23 capital fill order.
     *
     * Each candidate MUST have keys: target_amount, fit_score, symbol.
     * Additional keys are preserved but not used for ordering.
     *
     * Null target_amount is treated as 0 (lowest priority).
     * Null fit_score is treated as -1 (lowest priority within same target).
     *
     * @param  list<array{target_amount: float|null, fit_score: float|null, symbol: string, ...}>  $candidates
     * @return list<array> Candidates in OD-23 fill order (new array; input not mutated)
     */
    public function order(array $candidates): array
    {
        if (count($candidates) <= 1) {
            return array_values($candidates);
        }

        $sorted = $candidates;

        usort($sorted, function (array $a, array $b): int {
            $targetA = $this->resolveTargetAmount($a);
            $targetB = $this->resolveTargetAmount($b);

            if ($targetA !== $targetB) {
                return $targetB <=> $targetA;
            }

            $fitA = $this->resolveFitScore($a);
            $fitB = $this->resolveFitScore($b);

            if ($fitA !== $fitB) {
                return $fitB <=> $fitA;
            }

            return $this->resolveSymbol($a) <=> $this->resolveSymbol($b);
        });

        return array_values($sorted);
    }

    /**
     * Resolve the OD-23 primary key: target investment amount / conviction amount.
     *
     * Maps to the recommendation draft's `position_size` / `suggested_investment_amount`
     * — the ₹ amount derived from score-band allocation × portfolio value, capped by
     * max position size. This is the existing "conviction amount" per the spec.
     */
    private function resolveTargetAmount(array $candidate): float
    {
        return (float) ($candidate['target_amount'] ?? 0.0);
    }

    /**
     * Resolve the OD-23 secondary key: strategy fit score.
     */
    private function resolveFitScore(array $candidate): float
    {
        return (float) ($candidate['fit_score'] ?? -1.0);
    }

    /**
     * Resolve the OD-23 tertiary key: stock listing symbol (case-insensitive ascending).
     */
    private function resolveSymbol(array $candidate): string
    {
        return strtoupper(trim((string) ($candidate['symbol'] ?? '')));
    }
}
