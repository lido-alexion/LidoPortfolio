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

    public int $gttPlaceCalls = 0;

    public int $gttModifyCalls = 0;

    public int $gttCancelCalls = 0;

    /** @var list<BrokerGttRequest> */
    public array $gttsPlaced = [];

    public bool $supportsModify = true;

    public bool $nextGttPlaceAmbiguous = false;

    public bool $nextGttModifyAmbiguous = false;

    public bool $nextGttPlaceRejected = false;

    public bool $nextGttModifyUnsupported = false;

    public int $gttFailRemaining = 0;

    /** @var array<string, array{snapshot: BrokerGttSnapshot, request: BrokerGttRequest}> */
    public array $gtts = [];

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
        $this->gttPlaceCalls = 0;
        $this->gttModifyCalls = 0;
        $this->gttCancelCalls = 0;
        $this->gttsPlaced = [];
        $this->supportsModify = true;
        $this->nextGttPlaceAmbiguous = false;
        $this->nextGttModifyAmbiguous = false;
        $this->nextGttPlaceRejected = false;
        $this->nextGttModifyUnsupported = false;
        $this->gttFailRemaining = 0;
        $this->gtts = [];
        $this->seq = 1;
    }

    public function seedSnapshot(BrokerOrderSnapshot $snapshot): void
    {
        $this->orders[$snapshot->brokerOrderId] = $snapshot;
    }

    public function seedGttFill(string $brokerGttId, float $filledQuantity, float $averagePrice, bool $stillActive = true): void
    {
        $existing = $this->gtts[$brokerGttId] ?? null;
        if ($existing === null) {
            return;
        }
        $snap = $existing['snapshot'];
        $status = $stillActive ? 'active' : 'triggered';
        $this->gtts[$brokerGttId]['snapshot'] = new BrokerGttSnapshot(
            $brokerGttId,
            $status,
            $snap->quantity,
            $snap->triggerPrice,
            $filledQuantity,
            $averagePrice,
            'child-'.$brokerGttId,
            strtoupper($status),
        );
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

    public function placeGtt(BrokerGttRequest $request): BrokerSubmission
    {
        $this->gttPlaceCalls++;
        $this->gttsPlaced[] = $request;

        if ($this->nextGttPlaceAmbiguous) {
            $this->nextGttPlaceAmbiguous = false;
            throw new BrokerAmbiguousException('Simulated GTT place timeout.');
        }

        if ($this->gttFailRemaining > 0) {
            $this->gttFailRemaining--;
            throw new \App\Exceptions\DomainException('Simulated GTT place failure.', 'BROKER_REJECTED', 422);
        }

        $id = 'gtt-'.$this->seq++;
        if ($this->nextGttPlaceRejected) {
            $this->nextGttPlaceRejected = false;

            return new BrokerSubmission($id, 'rejected', 'Rejected by fake broker');
        }

        $this->gtts[$id] = [
            'request' => $request,
            'snapshot' => new BrokerGttSnapshot(
                $id,
                'active',
                $request->quantity,
                $request->triggerPrice,
                0,
                null,
                null,
                'ACTIVE',
            ),
        ];

        return new BrokerSubmission($id, 'submitted');
    }

    public function modifyGtt(int $userId, string $brokerGttId, BrokerGttRequest $request): BrokerSubmission
    {
        $this->gttModifyCalls++;

        if ($this->nextGttModifyAmbiguous) {
            $this->nextGttModifyAmbiguous = false;
            throw new BrokerAmbiguousException('Simulated GTT modify timeout.');
        }

        if ($this->nextGttModifyUnsupported || ! $this->supportsModify) {
            $this->nextGttModifyUnsupported = false;
            throw new \App\Exceptions\DomainException(
                'Broker does not support modifying this GTT.',
                'MODIFY_UNSUPPORTED',
                422,
            );
        }

        if ($this->gttFailRemaining > 0) {
            $this->gttFailRemaining--;
            throw new \App\Exceptions\DomainException('Simulated GTT modify failure.', 'BROKER_REJECTED', 422);
        }

        $existing = $this->gtts[$brokerGttId] ?? null;
        if ($existing === null) {
            throw new \App\Exceptions\DomainException('GTT not found.', 'BROKER_REJECTED', 422);
        }

        $prev = $existing['snapshot'];
        $this->gtts[$brokerGttId] = [
            'request' => $request,
            'snapshot' => new BrokerGttSnapshot(
                $brokerGttId,
                $prev->status,
                $request->quantity,
                $request->triggerPrice,
                $prev->filledQuantity,
                $prev->averagePrice,
                $prev->childOrderId,
                $prev->rawStatus,
            ),
        ];

        return new BrokerSubmission($brokerGttId, 'submitted');
    }

    public function fetchGtt(int $userId, string $brokerGttId): ?BrokerGttSnapshot
    {
        return $this->gtts[$brokerGttId]['snapshot'] ?? null;
    }

    public function cancelGtt(int $userId, string $brokerGttId): BrokerGttSnapshot
    {
        $this->gttCancelCalls++;
        $existing = $this->gtts[$brokerGttId]['snapshot'] ?? new BrokerGttSnapshot($brokerGttId, 'active');
        $cancelled = new BrokerGttSnapshot(
            $brokerGttId,
            'cancelled',
            $existing->quantity,
            $existing->triggerPrice,
            $existing->filledQuantity,
            $existing->averagePrice,
            $existing->childOrderId,
            'CANCELLED',
        );
        $this->gtts[$brokerGttId]['snapshot'] = $cancelled;

        return $cancelled;
    }
}
