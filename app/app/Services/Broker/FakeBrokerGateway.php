<?php

namespace App\Services\Broker;

/**
 * In-memory broker used in automated tests. Never contacts Zerodha.
 */
class FakeBrokerGateway implements BrokerGateway
{
    public int $placeCalls = 0;

    /** @var list<BrokerOrderRequest> */
    public array $placed = [];

    public bool $nextPlaceAmbiguous = false;

    public bool $nextPlaceRejected = false;

    /** @var array<string, BrokerOrderSnapshot> */
    public array $orders = [];

    protected int $seq = 1;

    public function provider(): string
    {
        return 'kite';
    }

    public function reset(): void
    {
        $this->placeCalls = 0;
        $this->placed = [];
        $this->nextPlaceAmbiguous = false;
        $this->nextPlaceRejected = false;
        $this->orders = [];
        $this->seq = 1;
    }

    public function seedSnapshot(BrokerOrderSnapshot $snapshot): void
    {
        $this->orders[$snapshot->brokerOrderId] = $snapshot;
    }

    public function placeOrder(BrokerOrderRequest $request): BrokerSubmission
    {
        $this->placeCalls++;
        $this->placed[] = $request;

        if ($this->nextPlaceAmbiguous) {
            $this->nextPlaceAmbiguous = false;
            throw new BrokerAmbiguousException('Simulated broker timeout after place.');
        }

        $id = 'fake-'.$this->seq++;
        if ($this->nextPlaceRejected) {
            $this->nextPlaceRejected = false;
            $this->orders[$id] = new BrokerOrderSnapshot($id, 'rejected', 0, $request->quantity, null, 'REJECTED');

            return new BrokerSubmission($id, 'rejected', 'Rejected by fake broker');
        }

        $this->orders[$id] = new BrokerOrderSnapshot($id, 'open', 0, $request->quantity, null, 'OPEN');

        return new BrokerSubmission($id, 'submitted');
    }

    public function fetchOrder(int $userId, string $brokerOrderId): ?BrokerOrderSnapshot
    {
        return $this->orders[$brokerOrderId] ?? null;
    }

    public function cancelOrder(int $userId, string $brokerOrderId): BrokerOrderSnapshot
    {
        $existing = $this->orders[$brokerOrderId] ?? new BrokerOrderSnapshot($brokerOrderId, 'open');
        $cancelled = new BrokerOrderSnapshot(
            $brokerOrderId,
            'cancelled',
            $existing->filledQuantity,
            0,
            $existing->averagePrice,
            'CANCELLED',
        );
        $this->orders[$brokerOrderId] = $cancelled;

        return $cancelled;
    }
}
