<?php

namespace App\Services\Broker;

final class BrokerOrderRequest
{
    public function __construct(
        public int $userId,
        public int $profileId,
        public int $recommendationId,
        public string $symbol,
        public string $exchange,
        public string $side,
        public float $quantity,
        public string $submissionKey,
        public ?string $product = 'CNC',
        public ?string $orderType = 'MARKET',
    ) {}
}
