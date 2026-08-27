<?php

namespace App\Services\Broker;

interface BrokerGateway
{
    public function provider(): string;

    public function placeOrder(BrokerOrderRequest $request): BrokerSubmission;

    public function fetchOrder(int $userId, string $brokerOrderId): ?BrokerOrderSnapshot;

    public function cancelOrder(int $userId, string $brokerOrderId): BrokerOrderSnapshot;
}
