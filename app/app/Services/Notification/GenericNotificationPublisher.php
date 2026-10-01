<?php

namespace App\Services\Notification;

use App\Models\NotificationSource;
use App\Models\User;

/** Additive façade for V9 producers; FEAT-055 remains on its existing paths. */
final class GenericNotificationPublisher
{
    public function __construct(private NotificationPublisher $publisher) {}

    /** @param iterable<User> $recipients */
    public function publish(iterable $recipients, NotificationEvent $event): NotificationSource
    {
        return $this->publisher->publishEvent($recipients, $event->toPublisherPayload());
    }

    /** @param iterable<User> $recipients */
    public function publishCondition(string $key, iterable $recipients, NotificationEvent $event): NotificationSource
    {
        return $this->publisher->publishCondition($key, $recipients, $event->toPublisherPayload());
    }
}
