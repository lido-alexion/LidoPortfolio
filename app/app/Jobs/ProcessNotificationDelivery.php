<?php

namespace App\Jobs;

use App\Services\Notification\NotificationDeliveryProcessor;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

class ProcessNotificationDelivery implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $deliveryId) {}

    public function uniqueId(): string
    {
        return (string) $this->deliveryId;
    }

    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(NotificationDeliveryProcessor $processor): void
    {
        $result = $processor->process($this->deliveryId);
        if ($result['retry']) {
            throw new RuntimeException('Retryable notification delivery failure.');
        }
    }
}
