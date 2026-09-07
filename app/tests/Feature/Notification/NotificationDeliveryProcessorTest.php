<?php

namespace Tests\Feature\Notification;

use App\Mail\NotificationDeliveryMail;
use App\Models\NotificationDelivery;
use App\Models\NotificationSource;
use App\Models\User;
use App\Services\Notification\NotificationChannelHealthService;
use App\Services\Notification\NotificationChannelSettingsService;
use App\Services\Notification\NotificationDeliveryProcessor;
use App\Services\Notification\NotificationPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class NotificationDeliveryProcessorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_successful_delivery_records_attempt_and_updates_notification_clock(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $delivery = $this->delivery('telegram');

        $result = app(NotificationDeliveryProcessor::class)->process($delivery->id);

        $this->assertSame(['retry' => false, 'status' => 'delivered'], $result);
        $this->assertDatabaseHas('portfolio_notification_delivery_attempts', [
            'delivery_id' => $delivery->id,
            'attempt_number' => 1,
            'status' => 'succeeded',
        ]);
        $this->assertNotNull($delivery->fresh()->recipientNotification->last_successful_external_delivery_at);
    }

    public function test_retryable_failures_are_bounded_and_evidence_is_sanitized(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['description' => 'sensitive provider body'], 503)]);
        $delivery = $this->delivery('telegram');
        $processor = app(NotificationDeliveryProcessor::class);

        $this->assertTrue($processor->process($delivery->id)['retry']);
        $this->assertTrue($processor->process($delivery->id)['retry']);
        $this->assertSame('failed', $processor->process($delivery->id)['status']);

        $this->assertSame(3, $delivery->attempts()->count());
        $this->assertSame('TELEGRAM_DELIVERY_FAILED', $delivery->fresh()->last_error_code);
        $this->assertStringNotContainsString('sensitive provider body', json_encode($delivery->fresh()->toArray()));
    }

    public function test_permanent_http_failure_does_not_retry(): void
    {
        Http::fake(['https://hooks.example.test/stox' => Http::response([], 400)]);
        $delivery = $this->delivery('webhook');

        $result = app(NotificationDeliveryProcessor::class)->process($delivery->id);

        $this->assertSame(['retry' => false, 'status' => 'failed'], $result);
        $this->assertSame(1, $delivery->attempts()->count());
    }

    public function test_persistent_failure_creates_one_deduplicated_channel_health_condition_without_recursion(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response([], 400)]);
        $delivery = $this->delivery('telegram', 'health:test');

        app(NotificationDeliveryProcessor::class)->process($delivery->id);
        app(NotificationDeliveryProcessor::class)->process($delivery->id);

        $this->assertDatabaseHas('portfolio_notification_sources', [
            'notification_type' => 'notification.channel_health',
            'condition_state' => 'active',
        ]);
        $this->assertSame(1, NotificationSource::query()->where('notification_type', 'notification.channel_health')->count());
        $this->assertSame(1, NotificationSource::query()->where('notification_type', 'notification.channel_health')->first()->occurrence_count);
    }

    public function test_channel_recovery_resolves_existing_health_condition(): void
    {
        $user = User::factory()->create();
        $health = app(NotificationChannelHealthService::class);
        $health->failure($user, 'telegram');
        $health->recovered($user, 'telegram');

        $this->assertDatabaseHas('portfolio_notification_sources', [
            'notification_type' => 'notification.channel_health',
            'condition_state' => 'resolved',
        ]);
    }

    public function test_resolved_condition_is_suppressed_before_network_send(): void
    {
        Http::fake();
        $delivery = $this->delivery('telegram', 'resolved-before-send');
        app(NotificationPublisher::class)->resolveCondition('resolved-before-send');

        $result = app(NotificationDeliveryProcessor::class)->process($delivery->id);

        $this->assertSame(['retry' => false, 'status' => 'terminal'], $result);
        $this->assertSame('suppressed', $delivery->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_email_adapter_delivers_individually_to_persisted_destination(): void
    {
        Mail::fake();
        $delivery = $this->delivery('email');

        app(NotificationDeliveryProcessor::class)->process($delivery->id);

        Mail::assertSent(NotificationDeliveryMail::class, fn ($mail) => $mail->hasTo($delivery->destination));
    }

    private function delivery(string $channel, string $conditionKey = 'processor:test'): NotificationDelivery
    {
        $user = User::factory()->create(['email' => 'processor@example.test']);
        $settings = app(NotificationChannelSettingsService::class);
        $configuration = match ($channel) {
            'telegram' => ['bot_token' => 'token', 'chat_id' => 'chat'],
            'webhook' => ['url' => 'https://hooks.example.test/stox'],
            default => [],
        };
        $settings->update($user, $channel, $configuration, false);
        $settings->markVerified($user, $channel);
        $settings->update($user, $channel, $configuration, true);
        app(NotificationPublisher::class)->publishCondition($conditionKey, [$user], [
            'notification_type' => 'test.processor',
            'audience' => 'investor',
            'severity' => 'critical',
            'title' => 'Processor test',
            'message' => 'Delivery processor test.',
        ]);

        return NotificationDelivery::query()->firstOrFail();
    }
}
