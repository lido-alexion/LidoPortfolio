<?php

namespace App\Services\Broker;

final class BrokerGttSnapshot
{
    public function __construct(
        public string $brokerGttId,
        public string $status,
        public float $quantity = 0,
        public float $triggerPrice = 0,
        public float $filledQuantity = 0,
        public ?float $averagePrice = null,
        public ?string $childOrderId = null,
        public ?string $rawStatus = null,
    ) {}
}
