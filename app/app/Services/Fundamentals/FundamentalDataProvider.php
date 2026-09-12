<?php

namespace App\Services\Fundamentals;

use App\Models\Stock;

interface FundamentalDataProvider
{
    /**
     * @return list<array<string,mixed>>
     */
    public function fetch(Stock $stock, string $cadence): array;
}
