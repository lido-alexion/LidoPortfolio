<?php

namespace App\Services\Broker;

final class BrokerOrderSnapshot
{
    public function __construct(
        public string $brokerOrderId,
        public string $status,
        public float $filledQuantity = 0,
        public float $pendingQuantity = 0,
        public ?float $averagePrice = null,
        public ?string $rawStatus = null,
    ) {}
}
