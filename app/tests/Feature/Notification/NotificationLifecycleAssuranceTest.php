<?php

namespace Tests\Feature\Notification;

use App\Models\NotificationDelivery;
use App\Models\NotificationSource;
use App\Models\User;
use App\Services\Notification\NotificationChannelSettingsService;
use App\Services\Notification\NotificationDeliveryPlanner;
use App\Services\Notification\NotificationDeliveryProcessor;
use App\Services\Notification\NotificationPublisher;
use App\Services\Notification\NotificationReminderService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class NotificationLifecycleAssuranceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_current_model_lifecycle_preserves_attention_condition_and_delivery_evidence(): void
    {
        Http::fakeSequence()
            ->push(['description' => 'temporary provider outage'], 503)
            ->push(['ok' => true], 200);

        $investor = User::factory()->create(['email' => 'lifecycle@example.test']);
        $this->enableTelegram($investor);
        $publisher = app(NotificationPublisher::class);
        $source = $publisher->publishCondition('assurance:lifecycle', [$investor], $this->content());
        $recipient = $source->recipients->sole();
        $delivery = NotificationDelivery::query()->where('recipient_notification_id', $recipient->id)->sole();

        $this->assertSame('active', $source->condition_state);
        $this->assertSame('unread', $recipient->attention_state);
        $this->assertSame('queued', $delivery->status);
        $this->assertSame('initial', $delivery->delivery_kind);
        $this->assertSame(1, NotificationSource::query()->count());

        app(NotificationDeliveryPlanner::class)->planInitial($source);
        $this->assertSame(1, NotificationDelivery::query()->count());

        $processor = app(NotificationDeliveryProcessor::class);
        $first = $processor->process($delivery->id);
        $this->assertSame(['retry' => true, 'status' => 'queued'], $first);
        $this->assertDatabaseHas('portfolio_notification_delivery_attempts', [
            'delivery_id' => $delivery->id,
            'attempt_number' => 1,
            'status' => 'failed',
            'error_code' => 'TELEGRAM_DELIVERY_FAILED',
            'response_status' => 503,
        ]);
        $this->assertSame('active', $source->fresh()->condition_state);
        $this->assertSame('unread', $recipient->fresh()->attention_state);

        $second = $processor->process($delivery->id);
        $this->assertSame(['retry' => false, 'status' => 'delivered'], $second);
        $this->assertDatabaseHas('portfolio_notification_delivery_attempts', [
            'delivery_id' => $delivery->id,
            'attempt_number' => 2,
            'status' => 'succeeded',
            'response_status' => 200,
        ]);
        $this->assertNotNull($delivery->fresh()->delivered_at);
        $this->assertNotNull($recipient->fresh()->last_successful_external_delivery_at);

        $terminal = $processor->process($delivery->id);
        $this->assertSame(['retry' => false, 'status' => 'terminal'], $terminal);
        $this->assertSame(2, $delivery->attempts()->count());

        $this->actingAs($investor)->getJson("/api/notification-center/{$recipient->id}")
            ->assertOk()
            ->assertJsonPath('data.attention_state', 'unread')
            ->assertJsonPath('data.condition_state', 'active')
            ->assertJsonCount(1, 'data.timeline');

        $this->actingAs($investor)->postJson("/api/notification-center/{$recipient->id}/read")
            ->assertOk()
            ->assertJsonPath('data.attention_state', 'read')
            ->assertJsonPath('data.condition_state', 'active');
        $this->actingAs($investor)->postJson("/api/notification-center/{$recipient->id}/read")
            ->assertOk()
            ->assertJsonPath('data.attention_state', 'read');

        $resolved = $publisher->resolveCondition('assurance:lifecycle');
        $this->assertSame('resolved', $resolved->condition_state);
        $this->assertSame('resolved', $recipient->fresh()->condition_state);
        $this->assertSame('read', $recipient->fresh()->attention_state);
        $this->assertDatabaseHas('portfolio_notification_occurrences', [
            'source_id' => $source->id,
            'activity_type' => 'resolved',
        ]);
        $this->assertSame('delivered', $delivery->fresh()->status);
        $this->assertSame(2, $delivery->attempts()->count());
    }

    public function test_reminder_is_a_new_generation_only_after_the_48_hour_threshold(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
        $investor = User::factory()->create();
        $this->enableTelegram($investor);
        $publisher = app(NotificationPublisher::class);
        $source = $publisher->publishCondition('assurance:reminder', [$investor], $this->content());
        $delivery = NotificationDelivery::query()->sole();
        app(NotificationDeliveryProcessor::class)->process($delivery->id);
        $successfulAt = $delivery->fresh()->recipientNotification->last_successful_external_delivery_at;

        Carbon::setTestNow($successfulAt->copy()->addHours(47));
        $this->assertSame(0, app(NotificationReminderService::class)->queueDue()['queued']);
        $this->assertSame(1, NotificationDelivery::query()->count());

        Carbon::setTestNow($successfulAt->copy()->addHours(49));
        $this->assertSame(1, app(NotificationReminderService::class)->queueDue()['queued']);
        $this->assertSame(['initial', 'reminder'], NotificationDelivery::query()->orderBy('id')->pluck('delivery_kind')->all());
        $reminder = NotificationDelivery::query()->where('delivery_kind', 'reminder')->sole();
        $this->assertNotSame($delivery->id, $reminder->id);
        $this->assertSame(0, $reminder->attempts()->count());
        $this->assertSame(0, app(NotificationReminderService::class)->queueDue()['queued']);

        Carbon::setTestNow();
    }

    public function test_resolved_queued_delivery_is_suppressed_and_disabled_channel_is_not_a_failure(): void
    {
        Http::fake();
        $investor = User::factory()->create();
        $this->enableTelegram($investor);
        $publisher = app(NotificationPublisher::class);
        $source = $publisher->publishCondition('assurance:suppressed', [$investor], $this->content());
        $publisher->resolveCondition('assurance:suppressed');
        $delivery = NotificationDelivery::query()->sole();

        $result = app(NotificationDeliveryProcessor::class)->process($delivery->id);
        $this->assertSame(['retry' => false, 'status' => 'terminal'], $result);
        $this->assertSame('suppressed', $delivery->fresh()->status);
        $this->assertSame(0, $delivery->attempts()->count());
        Http::assertNothingSent();

        app(NotificationChannelSettingsService::class)->update($investor, 'telegram', [], false);
        $unconfigured = $publisher->publishCondition('assurance:disabled', [$investor], $this->content());
        $this->assertNotSame($source->id, $unconfigured->id);
        $this->assertSame(1, NotificationDelivery::query()->count());
    }

    public function test_two_channels_keep_delivery_failure_channel_specific(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([], 503),
            'https://hooks.example.test/*' => Http::response([], 200),
        ]);
        $investor = User::factory()->create();
        $this->enableTelegram($investor);
        $settings = app(NotificationChannelSettingsService::class);
        $settings->update($investor, 'webhook', ['url' => 'https://hooks.example.test/stox'], false);
        $settings->markVerified($investor, 'webhook');
        $settings->update($investor, 'webhook', ['url' => 'https://hooks.example.test/stox'], true);

        $source = app(NotificationPublisher::class)->publishCondition('assurance:channels', [$investor], $this->content());
        $deliveries = NotificationDelivery::query()->orderBy('channel')->get();
        $this->assertSame(['telegram', 'webhook'], $deliveries->pluck('channel')->all());

        $processor = app(NotificationDeliveryProcessor::class);
        $telegram = $deliveries->firstWhere('channel', 'telegram');
        $webhook = $deliveries->firstWhere('channel', 'webhook');
        $this->assertSame(['retry' => true, 'status' => 'queued'], $processor->process($telegram->id));
        $this->assertSame(['retry' => false, 'status' => 'delivered'], $processor->process($webhook->id));
        $this->assertSame('queued', $telegram->fresh()->status);
        $this->assertSame('delivered', $webhook->fresh()->status);
        $this->assertSame('active', $source->fresh()->condition_state);
        $this->assertSame(1, NotificationSource::query()->where('id', $source->id)->count());
    }

    public function test_duplicate_condition_and_planning_are_idempotent(): void
    {
        $investor = User::factory()->create();
        $this->enableTelegram($investor);
        $publisher = app(NotificationPublisher::class);
        $first = $publisher->publishCondition('assurance:duplicate', [$investor], $this->content());
        $second = $publisher->publishCondition('assurance:duplicate', [$investor], $this->content());
        app(NotificationDeliveryPlanner::class)->planInitial($second);
        app(NotificationDeliveryPlanner::class)->planInitial($second);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(2, $second->fresh()->occurrence_count);
        $this->assertSame(1, $second->recipients()->count());
        $this->assertSame(1, NotificationDelivery::query()->count());
    }

    private function enableTelegram(User $user): void
    {
        $settings = app(NotificationChannelSettingsService::class);
        $configuration = ['bot_token' => 'test-token', 'chat_id' => 'test-chat'];
        $settings->update($user, 'telegram', $configuration, false);
        $settings->markVerified($user, 'telegram');
        $settings->update($user, 'telegram', $configuration, true);
    }

    private function content(): array
    {
        return [
            'notification_type' => 'assurance.condition',
            'audience' => 'investor',
            'severity' => 'action_required',
            'title' => 'Lifecycle assurance condition',
            'message' => 'A deterministic notification lifecycle is being verified.',
            'context' => ['assurance' => true],
        ];
    }
}
