<?php

namespace App\Console\Commands;

use App\Jobs\ProcessNotificationDelivery;
use App\Models\NotificationChannelSetting;
use App\Models\NotificationDelivery;
use App\Models\NotificationDigestMembership;
use App\Models\NotificationEmailDestination;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SendNotificationDigestsCommand extends Command
{
    protected $signature = 'portfolio:send-notification-digests';

    protected $description = 'Queue account-level daily notification digests';

    public function handle(): int
    {
        $groups = NotificationDigestMembership::query()->whereNull('delivery_id')->whereNull('delivered_at')
            ->select('user_id', 'digest_date')->distinct()->get();
        $queued = 0;
        foreach ($groups as $group) {
            $setting = NotificationChannelSetting::query()->where('user_id', $group->user_id)->where('channel', 'email')->first();
            $preferences = (array) (($setting?->configuration ?? [])['optional_email_preferences'] ?? []);
            if (! $setting?->enabled || ! $setting->verified_at || ! ($preferences['enabled'] ?? false)) continue;
            $localNow = now()->timezone($preferences['timezone'] ?? config('app.timezone', 'UTC'));
            if ($group->digest_date->greaterThan($localNow->toDateString())) continue;
            if ($group->digest_date->isSameDay($localNow) && $localNow->format('H:i') < ($preferences['digest_time'] ?? '09:00')) continue;
            $destination = NotificationEmailDestination::query()->where('user_id', $group->user_id)
                ->where('is_account_email', true)->whereNotNull('verified_at')->first();
            if (! $destination) continue;

            DB::transaction(function () use ($group, $setting, $destination, &$queued): void {
                $memberships = NotificationDigestMembership::query()->where('user_id', $group->user_id)
                    ->whereDate('digest_date', $group->digest_date)->whereNull('delivery_id')->whereNull('delivered_at')
                    ->with('recipientNotification.source')->lockForUpdate()->get();
                if ($memberships->isEmpty()) return;
                $first = $memberships->first();
                $date = $group->digest_date->format('Y-m-d');
                $hash = hash('sha256', $destination->email);
                $delivery = NotificationDelivery::query()->firstOrCreate(
                    ['idempotency_key' => "digest:{$group->user_id}:{$date}:email:{$hash}"],
                    [
                        'recipient_notification_id' => $first->recipient_notification_id,
                        'channel_setting_id' => $setting->id,
                        'channel' => 'email',
                        'delivery_kind' => 'digest',
                        'destination' => $destination->email,
                        'destination_hash' => $hash,
                        'status' => 'queued',
                        'available_at' => now(),
                    ],
                );
                NotificationDigestMembership::query()->whereIn('id', $memberships->pluck('id'))
                    ->update(['delivery_id' => $delivery->id, 'updated_at' => now()]);
                if ($delivery->wasRecentlyCreated) {
                    $queued++;
                    DB::afterCommit(fn () => ProcessNotificationDelivery::dispatch($delivery->id)->onQueue('notifications'));
                }
            });
        }

        $this->info("Queued {$queued} daily digest email(s).");

        return self::SUCCESS;
    }
}
