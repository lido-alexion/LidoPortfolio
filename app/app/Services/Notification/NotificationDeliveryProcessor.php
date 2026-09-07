<?php

namespace App\Services\Notification;

use App\Models\NotificationDelivery;
use Illuminate\Support\Facades\DB;

class NotificationDeliveryProcessor
{
    public function __construct(
        private NotificationDeliveryAdapter $adapter,
        private NotificationChannelHealthService $health,
    ) {}

    /** @return array{retry: bool, status: string} */
    public function process(int $deliveryId): array
    {
        $delivery = DB::transaction(function () use ($deliveryId) {
            $delivery = NotificationDelivery::query()->lockForUpdate()->findOrFail($deliveryId);
            if (in_array($delivery->status, ['delivered', 'failed', 'suppressed'], true)) {
                return null;
            }

            $delivery->load('recipientNotification.source', 'recipientNotification.user');
            if ($delivery->recipientNotification->condition_state === 'resolved'
                && $delivery->recipientNotification->source->condition_key !== null) {
                $delivery->update(['status' => 'suppressed', 'suppressed_at' => now()]);

                return null;
            }

            $delivery->update(['status' => 'processing']);

            return $delivery;
        });

        if (! $delivery) {
            return ['retry' => false, 'status' => 'terminal'];
        }

        $delivery->load(['channelSetting', 'recipientNotification.source', 'recipientNotification.user']);
        $result = $this->adapter->send($delivery);

        return DB::transaction(function () use ($deliveryId, $result) {
            $delivery = NotificationDelivery::query()->lockForUpdate()->findOrFail($deliveryId);
            $attempt = ((int) $delivery->attempts()->max('attempt_number')) + 1;
            $delivery->attempts()->create([
                'attempt_number' => $attempt,
                'status' => $result['successful'] ? 'succeeded' : 'failed',
                'error_code' => $result['error_code'],
                'response_status' => $result['response_status'],
                'attempted_at' => now(),
            ]);

            if ($result['successful']) {
                $delivery->update(['status' => 'delivered', 'delivered_at' => now(), 'last_error_code' => null]);
                $delivery->recipientNotification()->update(['last_successful_external_delivery_at' => now()]);
                if (! str_starts_with($delivery->recipientNotification->source->notification_type, 'notification.channel_health')) {
                    $this->health->recovered($delivery->recipientNotification->user, $delivery->channel);
                }

                return ['retry' => false, 'status' => 'delivered'];
            }

            $retry = $result['retryable'] && $attempt < 3;
            $delivery->update([
                'status' => $retry ? 'queued' : 'failed',
                'last_error_code' => $result['error_code'],
            ]);

            if (! $retry
                && ! str_starts_with($delivery->recipientNotification->source->notification_type, 'notification.channel_health')) {
                $this->health->failure($delivery->recipientNotification->user, $delivery->channel);
            }

            return ['retry' => $retry, 'status' => $retry ? 'queued' : 'failed'];
        });
    }
}
