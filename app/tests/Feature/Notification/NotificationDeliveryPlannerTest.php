<?php

namespace Tests\Feature\Notification;

use App\Jobs\ProcessNotificationDelivery;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Services\Notification\NotificationChannelSettingsService;
use App\Services\Notification\NotificationPublisher;
use App\Services\Notification\NotificationReminderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class NotificationDeliveryPlannerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_action_required_fans_out_durable_work_to_each_enabled_verified_channel(): void
    {
        $user = User::factory()->create(['email' => 'delivery@example.test']);
        $this->enableChannels($user);

        app(NotificationPublisher::class)->publishCondition('delivery:test', [$user], $this->content('action_required'));

        $this->assertDatabaseCount('portfolio_notification_deliveries', 3);
        $this->assertSame(
            ['email', 'telegram', 'webhook'],
            NotificationDelivery::query()->orderBy('channel')->pluck('channel')->all(),
        );
        $this->assertSame(['queued'], NotificationDelivery::query()->pluck('status')->unique()->all());
        $rawDestinations = DB::table('portfolio_notification_deliveries')->pluck('destination')->implode('|');
        $this->assertStringNotContainsString('delivery@example.test', $rawDestinations);
        $this->assertStringNotContainsString('https://hooks.example.test/stox', $rawDestinations);
        Queue::assertPushed(ProcessNotificationDelivery::class, 3);
    }

    public function test_info_is_in_app_only_unless_explicit_external_exception_is_frozen(): void
    {
        $user = User::factory()->create();
        $settings = app(NotificationChannelSettingsService::class);
        $settings->update($user, 'telegram', ['bot_token' => 'token', 'chat_id' => 'chat'], false);
        $settings->markVerified($user, 'telegram');
        $settings->update($user, 'telegram', ['bot_token' => 'token', 'chat_id' => 'chat'], true);

        app(NotificationPublisher::class)->publishEvent([$user], $this->content('info'));
        $this->assertDatabaseCount('portfolio_notification_deliveries', 0);

        app(NotificationPublisher::class)->publishEvent([$user], [
            ...$this->content('info'),
            'external_info_delivery' => true,
        ]);
        $this->assertDatabaseCount('portfolio_notification_deliveries', 1);
    }

    public function test_redetection_does_not_duplicate_delivery_but_critical_escalation_queues_once(): void
    {
        $user = User::factory()->create();
        $settings = app(NotificationChannelSettingsService::class);
        $settings->update($user, 'telegram', ['bot_token' => 'token', 'chat_id' => 'chat'], false);
        $settings->markVerified($user, 'telegram');
        $settings->update($user, 'telegram', ['bot_token' => 'token', 'chat_id' => 'chat'], true);
        $publisher = app(NotificationPublisher::class);

        $publisher->publishCondition('escalation:test', [$user], $this->content('action_required'));
        $publisher->publishCondition('escalation:test', [$user], $this->content('action_required'));
        $this->assertDatabaseCount('portfolio_notification_deliveries', 1);

        $publisher->publishCondition('escalation:test', [$user], $this->content('critical'));
        $publisher->publishCondition('escalation:test', [$user], $this->content('critical'));
        $this->assertDatabaseCount('portfolio_notification_deliveries', 2);
        $this->assertSame(['initial', 'escalation'], NotificationDelivery::query()->orderBy('id')->pluck('delivery_kind')->all());
    }

    public function test_reminder_is_queued_after_48_hours_from_last_success_and_is_idempotent(): void
    {
        $user = User::factory()->create();
        $settings = app(NotificationChannelSettingsService::class);
        $settings->update($user, 'telegram', ['bot_token' => 'token', 'chat_id' => 'chat'], false);
        $settings->markVerified($user, 'telegram');
        $settings->update($user, 'telegram', ['bot_token' => 'token', 'chat_id' => 'chat'], true);
        $source = app(NotificationPublisher::class)->publishCondition('reminder:test', [$user], $this->content('critical'));
        $recipient = $source->recipients->sole();
        $recipient->update(['last_successful_external_delivery_at' => now()->subHours(49)]);

        $service = app(NotificationReminderService::class);
        $this->assertSame(1, $service->queueDue()['queued']);
        $this->assertSame(0, $service->queueDue()['queued']);
        $this->assertSame(['initial', 'reminder'], NotificationDelivery::query()->orderBy('id')->pluck('delivery_kind')->all());
    }

    private function enableChannels(User $user): void
    {
        $settings = app(NotificationChannelSettingsService::class);
        foreach ([
            'telegram' => ['bot_token' => 'token', 'chat_id' => 'chat'],
            'email' => [],
            'webhook' => ['url' => 'https://hooks.example.test/stox'],
        ] as $channel => $configuration) {
            $settings->update($user, $channel, $configuration, false);
            $settings->markVerified($user, $channel);
            $settings->update($user, $channel, $configuration, true);
        }
    }

    private function content(string $severity): array
    {
        return [
            'notification_type' => 'test.delivery',
            'audience' => 'investor',
            'severity' => $severity,
            'title' => 'Delivery test',
            'message' => 'Delivery planning test.',
        ];
    }
}
