<?php

namespace App\Services\Notification;

use InvalidArgumentException;

final readonly class NotificationEvent
{
    public function __construct(
        public string $type,
        public string $audience,
        public string $severity,
        public string $title,
        public string $message,
        public array $context = [],
        public ?array $primaryAction = null,
        public bool $externalInfoDelivery = false,
    ) {
        if ($this->type === '' || $this->title === '' || $this->message === '') {
            throw new InvalidArgumentException('Notification event type, title, and message are required.');
        }
    }

    public function toPublisherPayload(): array
    {
        return [
            'notification_type' => $this->type,
            'audience' => $this->audience,
            'severity' => $this->severity,
            'title' => $this->title,
            'message' => $this->message,
            'context' => $this->context,
            'primary_action' => $this->primaryAction,
            'external_info_delivery' => $this->externalInfoDelivery,
        ];
    }
}
