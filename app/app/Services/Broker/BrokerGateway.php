<?php

namespace App\Services\Broker;

interface BrokerGateway
{
    public function provider(): string;

    public function placeOrder(BrokerOrderRequest $request): BrokerSubmission;

    public function fetchOrder(int $userId, string $brokerOrderId): ?BrokerOrderSnapshot;

    public function cancelOrder(int $userId, string $brokerOrderId): BrokerOrderSnapshot;

    public function placeGtt(BrokerGttRequest $request): BrokerSubmission;

    public function modifyGtt(int $userId, string $brokerGttId, BrokerGttRequest $request): BrokerSubmission;

    public function fetchGtt(int $userId, string $brokerGttId): ?BrokerGttSnapshot;

    public function cancelGtt(int $userId, string $brokerGttId): BrokerGttSnapshot;
}
