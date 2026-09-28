<?php

namespace App\Services\Fundamentals\Historical;

use App\Models\Stock;

interface FundamentalHistoricalSource
{
    public function id(): string;

    /** Lower number = higher priority (NSE before BSE before Yahoo). */
    public function priority(): int;

    public function supports(Stock $stock): bool;

    /**
     * @return list<array<string, mixed>>
     */
    public function fetch(Stock $stock, string $cadence): array;
}
