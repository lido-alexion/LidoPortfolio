<?php

namespace App\Services\Notification;

use App\Models\NotificationDelivery;
use App\Models\TosNotification;
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
            if ($delivery->channel === 'email' && ! $this->optionalEmailStillAllowed($delivery)) {
                $delivery->update(['status' => 'suppressed', 'suppressed_at' => now(), 'last_error_code' => null]);

                return null;
            }
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
                $delivery->digestMemberships()->whereNull('delivered_at')->update(['delivered_at' => now()]);
                $delivery->recipientNotification()->update(['last_successful_external_delivery_at' => now()]);
                if (! str_starts_with($delivery->recipientNotification->source->notification_type, 'notification.channel_health')) {
                    $this->health->recovered($delivery->recipientNotification->user, $delivery->channel);
                }
                $this->syncLegacyTosStatus($delivery, 'delivered');

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
            $this->syncLegacyTosStatus($delivery, $retry ? 'queued' : 'failed', $result['error_code']);

            return ['retry' => $retry, 'status' => $retry ? 'queued' : 'failed'];
        });
    }

    private function optionalEmailStillAllowed(NotificationDelivery $delivery): bool
    {
        $type = strtolower($delivery->recipientNotification->source->notification_type);
        if (str_starts_with($type, 'account.') || str_starts_with($type, 'security.')) return true;
        $configuration = $delivery->channelSetting?->configuration ?? [];
        $preferences = (array) ($configuration['optional_email_preferences'] ?? []);
        if (! ($preferences['enabled'] ?? false)) return false;
        $category = match (true) {
            str_contains($type, 'recommend') => 'recommendation',
            str_contains($type, 'order'), str_contains($type, 'execution') => 'order_execution',
            str_contains($type, 'connection'), str_contains($type, 'kite'), str_contains($type, 'broker') => 'connection',
            default => 'operations',
        };

        return (bool) ($preferences['categories'][$category] ?? false);
    }

    private function syncLegacyTosStatus(NotificationDelivery $delivery, string $status, ?string $error = null): void
    {
        if ($delivery->channel !== 'telegram') {
            return;
        }

        $legacyId = (int) ($delivery->recipientNotification->source->context['legacy_tos_notification_id'] ?? 0);
        if ($legacyId <= 0) {
            return;
        }

        TosNotification::query()->whereKey($legacyId)->update([
            'status' => $status,
            'delivered_at' => $status === 'delivered' ? now() : null,
            'last_error' => $error,
        ]);
    }
}
