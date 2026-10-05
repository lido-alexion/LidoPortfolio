<?php

namespace App\Services\Notification;

use App\Jobs\ProcessNotificationDelivery;
use App\Models\NotificationChannelSetting;
use App\Models\NotificationDelivery;
use App\Models\NotificationEmailDestination;
use App\Models\NotificationDigestMembership;
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

    public function requeueFailedForSourceChannel(NotificationSource $source, string $channel): int
    {
        $deliveries = NotificationDelivery::query()
            ->whereHas('recipientNotification', fn ($query) => $query->where('source_id', $source->id))
            ->where('channel', $channel)
            ->where('status', 'failed')
            ->get();

        foreach ($deliveries as $delivery) {
            $delivery->update(['status' => 'queued', 'available_at' => now(), 'last_error_code' => null]);
            DB::afterCommit(fn () => ProcessNotificationDelivery::dispatch($delivery->id)->onQueue('notifications'));
        }

        return $deliveries->count();
    }

    public function requeueFailedDelivery(NotificationDelivery $delivery): bool
    {
        if ($delivery->status !== 'failed') {
            return false;
        }

        $delivery->update([
            'status' => 'queued',
            'available_at' => now(),
            'last_error_code' => null,
        ]);
        DB::afterCommit(fn () => ProcessNotificationDelivery::dispatch($delivery->id)->onQueue('notifications'));

        return true;
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
        $mandatory = $this->isMandatory($source);
        if ($mandatory && ! in_array('email', $excluded, true) && $recipient->user?->email_verified_at) {
            $emailSetting = NotificationChannelSetting::query()->firstOrCreate(
                ['user_id' => $recipient->user_id, 'channel' => 'email'],
                ['enabled' => false, 'configuration' => [], 'health_status' => 'healthy', 'verified_at' => $recipient->user->email_verified_at],
            );
            if (! $emailSetting->verified_at) $emailSetting->update(['verified_at' => $recipient->user->email_verified_at]);
            if (! $settings->contains('id', $emailSetting->id)) $settings->push($emailSetting);
            NotificationEmailDestination::query()->updateOrCreate(
                ['user_id' => $recipient->user_id, 'email' => $recipient->user->email],
                ['is_account_email' => true, 'verified_at' => $recipient->user->email_verified_at],
            );
        }

        foreach ($settings as $setting) {
            $emailPreferences = $setting->channel === 'email' ? $this->emailPreference($recipient, $source) : null;
            if ($setting->channel === 'email' && ! ($emailPreferences['allowed'] ?? false)) continue;
            if ($setting->channel === 'email' && ($emailPreferences['digest'] ?? false)) {
                $this->queueDigestMembership($recipient, $emailPreferences['preferences']);
                continue;
            }
            foreach ($this->destinations($recipient, $setting, $mandatory) as $destination) {
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
                        'available_at' => $setting->channel === 'email' ? $this->emailAvailableAt($recipient, $source) : now(),
                    ],
                );
                if ($delivery->wasRecentlyCreated) {
                    $created++;
                    DB::afterCommit(fn () => ProcessNotificationDelivery::dispatch($delivery->id)
                        ->onQueue('notifications')->delay($delivery->available_at));
                }
            }
        }

        return $created;
    }

    /** @return array{allowed: bool, digest: bool, preferences: array<string, mixed>} */
    private function emailPreference(RecipientNotification $recipient, NotificationSource $source): array
    {
        $type = strtolower($source->notification_type);
        if ($this->isMandatory($source)) return ['allowed' => true, 'digest' => false, 'preferences' => []];
        $configuration = NotificationChannelSetting::query()
            ->where('user_id', $recipient->user_id)->where('channel', 'email')->first()?->configuration ?? [];
        $preferences = (array) ($configuration['optional_email_preferences'] ?? []);
        if (! ($preferences['enabled'] ?? false)) return ['allowed' => false, 'digest' => false, 'preferences' => $preferences];
        $category = match (true) {
            str_contains($type, 'recommend') => 'recommendation',
            str_contains($type, 'order'), str_contains($type, 'execution') => 'order_execution',
            str_contains($type, 'connection'), str_contains($type, 'kite'), str_contains($type, 'broker') => 'connection',
            default => 'operations',
        };
        $allowed = (bool) ($preferences['categories'][$category] ?? false);
        $critical = in_array($source->severity, ['critical', 'action_required'], true);
        return [
            'allowed' => $allowed,
            'digest' => $allowed && ! $critical && (($preferences['modes'][$category] ?? 'immediate') === 'digest'),
            'preferences' => $preferences,
        ];
    }

    private function queueDigestMembership(RecipientNotification $recipient, array $preferences): void
    {
        $timezone = $preferences['timezone'] ?? config('app.timezone', 'UTC');
        $local = now()->timezone($timezone);
        $digestTime = $preferences['digest_time'] ?? '09:00';
        $date = $local->format('H:i') >= $digestTime ? $local->copy()->addDay() : $local;
        NotificationDigestMembership::query()->firstOrCreate([
            'recipient_notification_id' => $recipient->id,
            'digest_date' => $date->toDateString(),
        ], ['user_id' => $recipient->user_id]);
    }

    private function emailAvailableAt(RecipientNotification $recipient, NotificationSource $source): \Illuminate\Support\Carbon
    {
        $type = strtolower($source->notification_type);
        if (in_array($source->severity, ['critical', 'action_required'], true)
            || str_starts_with($type, 'account.') || str_starts_with($type, 'security.')) return now();
        $configuration = NotificationChannelSetting::query()
            ->where('user_id', $recipient->user_id)->where('channel', 'email')->first()?->configuration ?? [];
        $preferences = (array) ($configuration['optional_email_preferences'] ?? []);
        $start = $preferences['quiet_start'] ?? null;
        $end = $preferences['quiet_end'] ?? null;
        if (! $start || ! $end || $start === $end) return now();
        $timezone = $preferences['timezone'] ?? config('app.timezone', 'UTC');
        $local = now()->timezone($timezone);
        $clock = $local->format('H:i');
        $quiet = $start < $end ? ($clock >= $start && $clock < $end) : ($clock >= $start || $clock < $end);
        if (! $quiet) return now();
        $candidate = $local->copy()->setTimeFromTimeString($end);
        if ($start > $end && $clock >= $start) $candidate->addDay();
        return $candidate;
    }

    private function requiresExternalDelivery(NotificationSource $source): bool
    {
        return $this->isMandatory($source)
            || in_array($source->severity, ['action_required', 'critical'], true)
            || ($source->severity === 'info' && $source->external_info_delivery);
    }

    private function isMandatory(NotificationSource $source): bool
    {
        $type = strtolower($source->notification_type);

        return str_starts_with($type, 'account.') || str_starts_with($type, 'security.');
    }

    private function destinations(RecipientNotification $recipient, NotificationChannelSetting $setting, bool $mandatory = false): array
    {
        $configuration = $setting->configuration ?? [];

        return match ($setting->channel) {
            'telegram' => array_values(array_filter([(string) ($configuration['chat_id'] ?? '')])),
            'webhook' => array_values(array_filter([(string) ($configuration['url'] ?? '')])),
            'email' => (function () use ($recipient, $mandatory): array {
                $query = NotificationEmailDestination::query()->where('user_id', $recipient->user_id)->whereNotNull('verified_at');
                if ($mandatory) {
                    $query->where('is_account_email', true)->where('email', $recipient->user->email);
                }

                return $query->pluck('email')->all();
            })(),
            default => [],
        };
    }
}
