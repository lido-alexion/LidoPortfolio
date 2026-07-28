<?php

namespace App\Services\Analytics;

/**
 * Backfill market-depth snapshots for the last N trading days (max 7).
 */
class MarketDepthBackfillService
{
    public function __construct(
        protected MarketDepthService $marketDepth,
    ) {}

    /**
     * @return array{dates: list<string>, saved: int, failed: list<string>}
     */
    public function backfill(?int $days = null): array
    {
        return $this->marketDepth->backfillLastTradingDays($days);
    }
}
