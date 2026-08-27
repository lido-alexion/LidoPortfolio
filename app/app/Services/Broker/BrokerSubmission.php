<?php

namespace App\Services\Broker;

final class BrokerSubmission
{
    public function __construct(
        public string $brokerOrderId,
        public string $status = 'submitted',
        public ?string $message = null,
    ) {}
}
