<?php

namespace Tests\Feature\Notification;

use App\Jobs\ProcessNotificationDelivery;
use App\Models\NotificationDelivery;
use App\Models\NotificationDigestMembership;
use App\Models\User;
use App\Services\Notification\NotificationChannelSettingsService;
use App\Services\Notification\NotificationPublisher;
use App\Services\Notification\NotificationReminderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Artisan;
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
        app(NotificationChannelSettingsService::class)->updateOptionalEmailPreferences($user, [
            'enabled' => true,
            'categories' => ['operations' => true],
            'modes' => ['operations' => 'immediate'],
        ]);
    }

    public function test_optional_email_default_off_and_digest_membership_is_idempotent(): void
    {
        $user = User::factory()->create();
        $settings = app(NotificationChannelSettingsService::class);
        $settings->update($user, 'email', [], false);
        $settings->markVerified($user, 'email');
        $settings->update($user, 'email', [], true);
        $content = [...$this->content('info'), 'notification_type' => 'recommendation.status_changed', 'external_info_delivery' => true];
        app(NotificationPublisher::class)->publishEvent([$user], $content);
        $this->assertDatabaseCount('portfolio_notification_deliveries', 0);

        $settings->updateOptionalEmailPreferences($user, ['enabled' => true, 'categories' => ['recommendation' => true], 'modes' => ['recommendation' => 'digest'], 'digest_time' => '09:00', 'timezone' => 'UTC']);
        app(NotificationPublisher::class)->publishEvent([$user], [...$content, 'title' => 'Recommendation changed']);
        $this->assertDatabaseCount('portfolio_notification_deliveries', 0);
        $this->assertDatabaseCount('portfolio_notification_digest_memberships', 1);
        $this->assertDatabaseCount('portfolio_notification_digest_memberships', 1);

        $this->travelTo(now()->addDay()->setTime(10, 0));
        Artisan::call('portfolio:send-notification-digests');
        $this->assertDatabaseCount('portfolio_notification_deliveries', 1);
        $delivery = NotificationDelivery::query()->sole();
        $this->assertSame('digest', $delivery->delivery_kind);
        $this->assertSame(1, $delivery->digestMemberships()->count());
        Artisan::call('portfolio:send-notification-digests');
        $this->assertDatabaseCount('portfolio_notification_deliveries', 1);
        Mail::fake();
        app(\App\Services\Notification\NotificationDeliveryProcessor::class)->process($delivery->id);
        Mail::assertSent(\App\Mail\NotificationDeliveryMail::class, fn ($mail) => count($mail->items) === 1);
        $this->assertNotNull(NotificationDigestMembership::query()->sole()->delivered_at);
    }

    public function test_mandatory_security_email_bypasses_optional_controls(): void
    {
        $user = User::factory()->create();
        $settings = app(NotificationChannelSettingsService::class);
        $settings->update($user, 'email', [], false);
        $settings->markVerified($user, 'email');
        $settings->update($user, 'email', [], true);
        app(NotificationPublisher::class)->publishEvent([$user], [
            'notification_type' => 'security.password_changed', 'audience' => 'investor', 'severity' => 'info',
            'title' => 'Password changed', 'message' => 'Your password changed.', 'external_info_delivery' => true,
        ]);
        $this->assertDatabaseHas('portfolio_notification_deliveries', ['channel' => 'email']);
    }

    public function test_noncritical_optional_email_waits_until_quiet_hours_end_in_account_timezone(): void
    {
        $user = User::factory()->create();
        $settings = app(NotificationChannelSettingsService::class);
        $settings->update($user, 'email', [], false);
        $settings->markVerified($user, 'email');
        $settings->update($user, 'email', [], true);
        $now = now()->setTimezone('Asia/Kolkata')->setTime(23, 0);
        $this->travelTo($now->copy());
        $settings->updateOptionalEmailPreferences($user, ['enabled' => true, 'categories' => ['operations' => true], 'modes' => ['operations' => 'immediate'], 'quiet_start' => '22:00', 'quiet_end' => '07:00', 'digest_time' => '09:00', 'timezone' => 'Asia/Kolkata']);
        app(NotificationPublisher::class)->publishEvent([$user], [...$this->content('info'), 'external_info_delivery' => true]);
        $delivery = NotificationDelivery::query()->sole();
        $expected = now()->timezone('Asia/Kolkata')->addDay()->setTime(7, 0)->utc();
        $this->assertSame($expected->timestamp, $delivery->available_at->timestamp);
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
