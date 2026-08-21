<?php

namespace App\Engines\Recommendation\Allocation;

/**
 * V3 sequential capital allocator.
 *
 * Fills candidates in the supplied order (return-quality ranking when computable,
 * OD-23 capital fill order when ranking is unavailable). No score-proportional
 * weighting. No V1 redistribution.
 *
 * Does NOT mutate recommendations, holdings, transactions, or cash.
 * Does NOT call ReturnQualityRankingService or CapitalFillOrderService.
 * The caller/orchestration layer determines candidate ordering.
 *
 * Implements:
 *  - Sequential fill per §4.6 / §5.9
 *  - OD-05 partial funding (target preserved, partial allocation allowed)
 *  - OD-06 atomic capital block (₹5,000, 1% margin, ceil)
 *  - Whole-share executable quantity
 *  - Max-position constraint
 *  - OD-24 lending boundary awareness (does not block own-capital buys)
 */
class ReturnQualityCapitalAllocator implements CapitalAllocationStrategy
{
    public const ATOMIC_BLOCK = 5000;

    public const EXECUTION_MARGIN_PCT = 0.01;

    /**
     * Sequential fill: allocate capital to candidates in supplied order.
     *
     * @param  float  $availableCash  Strategy available capital from WS2 snapshot
     * @param  list<array{
     *     key: int|string,
     *     desired_amount: float,
     *     reference_price: float,
     *     max_position_amount: float|null,
     *     score?: float,
     *     confidence?: float,
     *     priority?: int,
     *     action?: string,
     * }>  $buyDrafts  Already ordered by caller (ranking or OD-23)
     * @return array<int|string, array{
     *     allocated_amount: float,
     *     quantity: int,
     *     target_amount: float,
     *     unfunded_amount: float,
     *     atomic_reservation: float,
     *     funding_status: string,
     * }>
     */
    public function allocate(float $availableCash, array $buyDrafts): array
    {
        $remaining = max(0.0, $availableCash);
        $out = [];

        foreach ($buyDrafts as $draft) {
            $key = $draft['key'];
            $targetAmount = max(0.0, (float) ($draft['desired_amount'] ?? 0));
            $maxPos = $draft['max_position_amount'] ?? null;
            if ($maxPos !== null) {
                $targetAmount = min($targetAmount, max(0.0, (float) $maxPos));
            }

            $price = (float) ($draft['reference_price'] ?? 0);

            if ($price <= 0 || $targetAmount <= 0) {
                $out[$key] = $this->buildResult($targetAmount, 0.0, 0, 0.0);

                continue;
            }

            $atomicReservation = self::atomicAllocation($targetAmount);
            $fundable = min($remaining, $targetAmount);

            if ($fundable < $price) {
                $out[$key] = $this->buildResult($targetAmount, 0.0, 0, $atomicReservation);

                continue;
            }

            $qty = (int) floor($fundable / $price);
            if ($qty < 1) {
                $out[$key] = $this->buildResult($targetAmount, 0.0, 0, $atomicReservation);

                continue;
            }

            $allocated = round($qty * $price, 4);
            $remaining = round($remaining - $allocated, 4);

            $out[$key] = $this->buildResult($targetAmount, $allocated, $qty, $atomicReservation);
        }

        return $out;
    }

    /**
     * OD-06: atomic capital block with 1% execution-price margin.
     * ceil(requirement × 1.01 / 5000) × 5000
     */
    public static function atomicAllocation(float $calculatedRequirement): float
    {
        if ($calculatedRequirement <= 0) {
            return 0.0;
        }

        $adjusted = $calculatedRequirement * (1 + self::EXECUTION_MARGIN_PCT);

        return (float) (ceil($adjusted / self::ATOMIC_BLOCK) * self::ATOMIC_BLOCK);
    }

    /**
     * @return array{allocated_amount: float, quantity: int, target_amount: float, unfunded_amount: float, atomic_reservation: float, funding_status: string}
     */
    private function buildResult(float $target, float $allocated, int $qty, float $atomicReservation): array
    {
        $unfunded = round(max(0.0, $target - $allocated), 4);

        if ($allocated <= 0) {
            $status = 'UNFUNDED';
        } elseif ($unfunded > 0.01) {
            $status = 'PARTIALLY_FUNDED';
        } else {
            $status = 'FULLY_FUNDED';
        }

        return [
            'allocated_amount' => $allocated,
            'quantity' => $qty,
            'target_amount' => round($target, 4),
            'unfunded_amount' => $unfunded,
            'atomic_reservation' => $atomicReservation,
            'funding_status' => $status,
        ];
    }
}
