<?php

namespace App\Services\Notification;

use App\Jobs\ProcessNotificationDelivery;
use App\Models\NotificationChannelSetting;
use App\Models\NotificationDelivery;
use App\Models\NotificationEmailDestination;
use App\Models\NotificationSource;
use App\Models\RecipientNotification;
use Illuminate\Support\Facades\DB;

class NotificationDeliveryPlanner
{
    public function __construct(private LegacyTelegramChannelMigrator $legacyTelegram) {}

    public function planInitial(NotificationSource $source, string $kind = 'initial'): int
    {
        if (! $this->requiresExternalDelivery($source)) {
            return 0;
        }

        $created = 0;
        $source->loadMissing('recipients.user');
        foreach ($source->recipients as $recipient) {
            $created += $this->planForRecipient($recipient, $source, $kind, $kind);
        }

        return $created;
    }

    public function planReminder(RecipientNotification $recipient): int
    {
        $recipient->loadMissing('source', 'user');
        if ($recipient->condition_state !== 'active'
            || ! in_array($recipient->source->severity, ['action_required', 'critical'], true)
            || ! $recipient->last_successful_external_delivery_at) {
            return 0;
        }

        return $this->planForRecipient(
            $recipient,
            $recipient->source,
            'reminder',
            'reminder:'.$recipient->last_successful_external_delivery_at->timestamp,
        );
    }

    private function planForRecipient(RecipientNotification $recipient, NotificationSource $source, string $kind, string $generation): int
    {
        $created = 0;
        $excluded = (array) ($source->context['excluded_channels'] ?? []);
        $this->legacyTelegram->migrateIfUnambiguous($recipient->user);
        $settings = NotificationChannelSetting::query()
            ->where('user_id', $recipient->user_id)
            ->where('enabled', true)
            ->whereNotNull('verified_at')
            ->whereNotIn('channel', $excluded)
            ->get();

        foreach ($settings as $setting) {
            foreach ($this->destinations($recipient, $setting) as $destination) {
                $hash = hash('sha256', $destination);
                $delivery = NotificationDelivery::query()->firstOrCreate(
                    ['idempotency_key' => "{$recipient->id}:{$generation}:{$setting->channel}:{$hash}"],
                    [
                        'recipient_notification_id' => $recipient->id,
                        'channel_setting_id' => $setting->id,
                        'channel' => $setting->channel,
                        'delivery_kind' => $kind,
                        'destination' => $destination,
                        'destination_hash' => $hash,
                        'status' => 'queued',
                        'available_at' => now(),
                    ],
                );
                if ($delivery->wasRecentlyCreated) {
                    $created++;
                    DB::afterCommit(fn () => ProcessNotificationDelivery::dispatch($delivery->id)->onQueue('notifications'));
                }
            }
        }

        return $created;
    }

    private function requiresExternalDelivery(NotificationSource $source): bool
    {
        return in_array($source->severity, ['action_required', 'critical'], true)
            || ($source->severity === 'info' && $source->external_info_delivery);
    }

    private function destinations(RecipientNotification $recipient, NotificationChannelSetting $setting): array
    {
        $configuration = $setting->configuration ?? [];

        return match ($setting->channel) {
            'telegram' => array_values(array_filter([(string) ($configuration['chat_id'] ?? '')])),
            'webhook' => array_values(array_filter([(string) ($configuration['url'] ?? '')])),
            'email' => NotificationEmailDestination::query()
                ->where('user_id', $recipient->user_id)
                ->whereNotNull('verified_at')
                ->pluck('email')
                ->all(),
            default => [],
        };
    }
}
