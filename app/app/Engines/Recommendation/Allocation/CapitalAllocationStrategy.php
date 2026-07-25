<?php

namespace App\Engines\Recommendation\Allocation;

/**
 * Pluggable capital allocation strategy (SD-026).
 * Implementations distribute available investable cash across ranked buy drafts.
 */
interface CapitalAllocationStrategy
{
    /**
     * @param  list<array{
     *     key: int|string,
     *     score: float,
     *     confidence: float,
     *     priority: int,
     *     desired_amount: float,
     *     reference_price: float,
     *     action: string,
     *     max_position_amount: float|null
     * }>  $buyDrafts  Highest score first preferred; strategy may re-sort.
     * @return array<int|string, array{allocated_amount: float, quantity: int}>
     */
    public function allocate(float $availableCash, array $buyDrafts): array;
}
