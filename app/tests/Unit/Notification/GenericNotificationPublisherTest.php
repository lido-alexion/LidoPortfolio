<?php

namespace Tests\Unit\Notification;

use App\Services\Notification\NotificationEvent;
use PHPUnit\Framework\TestCase;

class GenericNotificationPublisherTest extends TestCase
{
    public function test_normalizes_a_generic_notification_event_for_the_existing_publisher_contract(): void
    {
        $event = new NotificationEvent(
            type: 'v9.test',
            audience: 'admin',
            severity: 'info',
            title: 'Test',
            message: 'Message',
            context: ['source' => 'test'],
        );

        $payload = $event->toPublisherPayload();
        $this->assertSame('v9.test', $payload['notification_type']);
        $this->assertSame('admin', $payload['audience']);
        $this->assertSame(['source' => 'test'], $payload['context']);
    }
}
