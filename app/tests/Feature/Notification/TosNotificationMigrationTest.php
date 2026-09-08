<?php

namespace Tests\Feature\Notification;

use App\Engines\Notification\NotificationEngine;
use App\Models\NotificationDelivery;
use App\Models\NotificationSource;
use App\Models\TosNotification;
use App\Models\User;
use App\Services\Notification\NotificationDeliveryProcessor;
use App\Services\ProfileSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TosNotificationMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_legacy_history_row_is_linked_once_and_mirrors_successful_telegram_delivery(): void
    {
        [$notification] = $this->legacyNotification();
        $engine = app(NotificationEngine::class);

        $queued = $engine->send($notification);
        $engine->send($queued);

        $this->assertSame('queued', $queued->status);
        $this->assertDatabaseCount('portfolio_tos_notifications', 1);
        $this->assertDatabaseCount('portfolio_notification_sources', 1);
        $this->assertDatabaseCount('portfolio_notification_deliveries', 1);
        $source = NotificationSource::query()->sole();
        $this->assertSame($source->id, $queued->payload['notification_source_id']);
        $this->assertSame($queued->id, $source->context['legacy_tos_notification_id']);

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        app(NotificationDeliveryProcessor::class)->process(NotificationDelivery::query()->sole()->id);

        $this->assertSame('delivered', $queued->fresh()->status);
        $this->assertNotNull($queued->fresh()->delivered_at);
    }

    public function test_explicit_legacy_retry_requeues_the_same_failed_outbox_row(): void
    {
        [$notification, $profile] = $this->legacyNotification();
        $engine = app(NotificationEngine::class);
        $queued = $engine->send($notification);
        $delivery = NotificationDelivery::query()->sole();

        Http::fake(['api.telegram.org/*' => Http::response([], 400)]);
        app(NotificationDeliveryProcessor::class)->process($delivery->id);
        $this->assertSame('failed', $queued->fresh()->status);

        $retried = $engine->retry($profile, $queued->id);

        $this->assertNotNull($retried);
        $this->assertSame('queued', $retried->status);
        $this->assertDatabaseCount('portfolio_notification_sources', 2); // linked event + channel-health condition
        $this->assertDatabaseCount('portfolio_notification_deliveries', 1);
        $this->assertSame('queued', $delivery->fresh()->status);
    }

    /** @return array{TosNotification, \App\Models\PortfolioProfile} */
    private function legacyNotification(): array
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        app(ProfileSettingsService::class)->update($profile, [
            'notifications_enabled' => 'true',
            'telegram_bot_token' => 'legacy-token',
            'telegram_chat_id' => 'legacy-chat',
        ]);

        return [TosNotification::query()->create([
            'profile_id' => $profile->id,
            'notification_type' => 'recall_requested',
            'channel' => 'telegram',
            'recipient' => 'legacy-chat',
            'payload' => ['message' => 'Recall requested for testing'],
            'status' => 'queued',
            'idempotency_key' => 'legacy-test-key',
            'attempt_count' => 0,
        ]), $profile];
    }
}
