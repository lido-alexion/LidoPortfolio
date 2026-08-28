<?php

namespace App\Services\Broker;

final class BrokerGttRequest
{
    public function __construct(
        public int $userId,
        public int $profileId,
        public string $symbol,
        public string $exchange,
        public float $quantity,
        public float $triggerPrice,
        public float $lastPrice,
        public string $submissionKey,
        public string $protectionType,
        public string $side = 'sell',
        public ?string $product = 'CNC',
    ) {}
}
