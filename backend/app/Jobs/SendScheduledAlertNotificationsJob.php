<?php

namespace App\Jobs;

use App\Services\AlertNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendScheduledAlertNotificationsJob implements ShouldQueue
{
    use Queueable;

    public function handle(AlertNotificationService $notifications): array
    {
        return $notifications->sendScheduledNotifications();
    }
}
