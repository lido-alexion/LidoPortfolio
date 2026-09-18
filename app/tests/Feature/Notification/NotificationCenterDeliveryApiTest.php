<?php

namespace Tests\Feature\Notification;

use App\Models\NotificationDelivery;
use App\Models\User;
use App\Services\Notification\NotificationChannelSettingsService;
use App\Services\Notification\NotificationDeliveryProcessor;
use App\Services\Notification\NotificationPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class NotificationCenterDeliveryApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_detail_exposes_safe_per_channel_delivery_summary(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([], 400),
            'https://hooks.example.test/*' => Http::response([], 200),
        ]);
        $investor = User::factory()->create();
        $this->enable($investor, 'telegram', ['bot_token' => 'token', 'chat_id' => 'private-chat']);
        $this->enable($investor, 'webhook', ['url' => 'https://hooks.example.test/stox']);
        $source = app(NotificationPublisher::class)->publishCondition('api:delivery-detail', [$investor], $this->content());

        foreach (NotificationDelivery::query()->get() as $delivery) {
            app(NotificationDeliveryProcessor::class)->process($delivery->id);
        }

        $response = $this->actingAs($investor)->getJson('/api/notification-center/'.$source->recipients->sole()->id);
        $response->assertOk()
            ->assertJsonCount(2, 'data.deliveries')
            ->assertJsonPath('data.deliveries.0.channel', 'telegram')
            ->assertJsonPath('data.deliveries.0.status', 'failed')
            ->assertJsonPath('data.deliveries.0.attempt_count', 1)
            ->assertJsonPath('data.deliveries.0.last_error_code', 'TELEGRAM_DELIVERY_FAILED')
            ->assertJsonPath('data.deliveries.1.channel', 'webhook')
            ->assertJsonPath('data.deliveries.1.status', 'delivered');
        $this->assertStringNotContainsString('private-chat', $response->getContent());
        $this->assertStringNotContainsString('token', $response->getContent());
    }

    public function test_detail_preserves_authoritative_primary_action_without_adding_sensitive_fields(): void
    {
        $investor = User::factory()->create();
        $source = app(NotificationPublisher::class)->publishEvent([$investor], [
            ...$this->content(),
            'primary_action' => ['label' => 'Open recommendations', 'route' => '/recommendations'],
        ]);

        $response = $this->actingAs($investor)->getJson('/api/notification-center/'.$source->recipients->sole()->id);
        $response->assertOk()
            ->assertJsonPath('data.primary_action.label', 'Open recommendations')
            ->assertJsonPath('data.primary_action.route', '/recommendations');
        $this->assertStringNotContainsString('token', $response->getContent());
    }

    public function test_owner_can_retry_failed_delivery_and_repeated_retry_is_rejected(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response([], 400)]);
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->enable($owner, 'telegram', ['bot_token' => 'token', 'chat_id' => 'private-chat']);
        $recipient = app(NotificationPublisher::class)->publishCondition('api:retry', [$owner], $this->content())->recipients->sole();
        $delivery = NotificationDelivery::query()->sole();
        app(NotificationDeliveryProcessor::class)->process($delivery->id);

        $this->actingAs($other)->postJson("/api/notification-center/{$recipient->id}/deliveries/{$delivery->id}/retry")
            ->assertNotFound();

        $this->actingAs($owner)->postJson("/api/notification-center/{$recipient->id}/deliveries/{$delivery->id}/retry")
            ->assertOk()
            ->assertJsonPath('data.deliveries.0.status', 'queued')
            ->assertJsonPath('data.deliveries.0.retryable', false);

        $this->actingAs($owner)->postJson("/api/notification-center/{$recipient->id}/deliveries/{$delivery->id}/retry")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only failed deliveries can be retried.');
        $this->assertSame(1, NotificationDelivery::query()->count());
    }

    public function test_delivered_and_suppressed_deliveries_are_not_retryable(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
        $investor = User::factory()->create();
        $this->enable($investor, 'telegram', ['bot_token' => 'token', 'chat_id' => 'private-chat']);
        $publisher = app(NotificationPublisher::class);

        $deliveredRecipient = $publisher->publishCondition('api:delivered', [$investor], $this->content())->recipients->sole();
        $delivered = NotificationDelivery::query()->sole();
        app(NotificationDeliveryProcessor::class)->process($delivered->id);
        $this->actingAs($investor)->postJson("/api/notification-center/{$deliveredRecipient->id}/deliveries/{$delivered->id}/retry")
            ->assertStatus(422);

        $suppressedRecipient = $publisher->publishCondition('api:suppressed', [$investor], $this->content())->recipients->sole();
        $suppressed = NotificationDelivery::query()->where('recipient_notification_id', $suppressedRecipient->id)->sole();
        $publisher->resolveCondition('api:suppressed');
        app(NotificationDeliveryProcessor::class)->process($suppressed->id);
        $this->actingAs($investor)->postJson("/api/notification-center/{$suppressedRecipient->id}/deliveries/{$suppressed->id}/retry")
            ->assertStatus(422);
    }

    private function enable(User $user, string $channel, array $configuration): void
    {
        $settings = app(NotificationChannelSettingsService::class);
        $settings->update($user, $channel, $configuration, false);
        $settings->markVerified($user, $channel);
        $settings->update($user, $channel, $configuration, true);
    }

    private function content(): array
    {
        return [
            'notification_type' => 'api.delivery',
            'audience' => 'investor',
            'severity' => 'action_required',
            'title' => 'Delivery API test',
            'message' => 'Delivery detail and retry API test.',
        ];
    }
}
