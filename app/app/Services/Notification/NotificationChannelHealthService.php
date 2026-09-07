<?php

namespace App\Services\Notification;

use App\Models\User;

class NotificationChannelHealthService
{
    public function __construct(private NotificationPublisher $publisher) {}

    public function failure(User $user, string $channel): void
    {
        $this->publisher->publishCondition(
            "notification-channel-health:{$user->id}:{$channel}",
            [$user],
            [
                'notification_type' => 'notification.channel_health',
                'audience' => $user->is_admin ? 'admin' : 'investor',
                'severity' => 'action_required',
                'title' => ucfirst($channel).' notifications need attention',
                'message' => "StoX could not deliver through the {$channel} channel. Check its configuration or disable it.",
                'context' => ['channel' => $channel, 'excluded_channels' => [$channel]],
            ],
        );
    }

    public function recovered(User $user, string $channel): void
    {
        $this->publisher->resolveCondition("notification-channel-health:{$user->id}:{$channel}");
    }
}
