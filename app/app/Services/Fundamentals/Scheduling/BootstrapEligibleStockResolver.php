<?php

namespace App\Services\Fundamentals\Scheduling;

/** Read-only bridge to the canonical A engine's eligible-equity resolver. */
interface BootstrapEligibleStockResolver
{
    /** @param list<int> $requestedIds @return list<int> exact IDs that are active eligible equities */
    public function eligibleStockIds(array $requestedIds): array;
}
