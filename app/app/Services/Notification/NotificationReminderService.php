<?php

namespace App\Services\Notification;

use App\Models\RecipientNotification;

class NotificationReminderService
{
    public function __construct(private NotificationDeliveryPlanner $deliveries) {}

    public function queueDue(): array
    {
        $checked = 0;
        $queued = 0;

        RecipientNotification::query()
            ->where('condition_state', 'active')
            ->whereNotNull('last_successful_external_delivery_at')
            ->where('last_successful_external_delivery_at', '<=', now()->subHours(48))
            ->whereHas('source', fn ($source) => $source->whereIn('severity', ['action_required', 'critical']))
            ->with(['source', 'user'])
            ->orderBy('id')
            ->chunkById(100, function ($notifications) use (&$checked, &$queued) {
                foreach ($notifications as $notification) {
                    $checked++;
                    $queued += $this->deliveries->planReminder($notification);
                }
            });

        return ['checked' => $checked, 'queued' => $queued];
    }
}
