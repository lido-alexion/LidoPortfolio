<?php

namespace App\Contracts;

use Carbon\Carbon;

interface PriceProviderInterface
{
    public function getName(): string;

    /**
     * @return array<int, array{price_date: string, open_price: ?float, high_price: ?float, low_price: ?float, close_price: float, volume: ?int}>
     */
    public function fetchHistorical(string $symbol, Carbon $from, Carbon $to, ?string $providerSymbol = null): array;

    /**
     * @return array{price_date: string, open_price: ?float, high_price: ?float, low_price: ?float, close_price: float, volume: ?int}|null
     */
    public function fetchLatest(string $symbol): ?array;
}
