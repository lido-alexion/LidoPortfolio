<?php

namespace App\Services\Fundamentals\Historical;

use App\Models\Stock;
use App\Services\Fundamentals\FundamentalDataProvider;

class YahooFundamentalHistoricalSource implements FundamentalHistoricalSource
{
    public function __construct(
        protected FundamentalDataProvider $provider,
    ) {}

    public function id(): string
    {
        return 'yahoo';
    }

    public function priority(): int
    {
        return 100;
    }

    public function supports(Stock $stock): bool
    {
        return true;
    }

    public function fetch(Stock $stock, string $cadence): array
    {
        $rows = $this->provider->fetch($stock, $cadence);
        foreach ($rows as &$row) {
            $row['provider'] = $this->id();
        }

        return $rows;
    }
}
