<?php

namespace Tests\Feature\Notification;

use App\Models\NotificationDelivery;
use App\Models\NotificationChannelSetting;
use App\Models\NotificationSource;
use App\Models\RecipientNotification;
use App\Models\User;
use App\Services\ProfileSettingsService;
use App\Services\TelegramNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SyncFailureNotificationMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_failure_is_published_only_to_admins_through_the_notification_outbox(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['is_admin' => true]);
        $investor = User::factory()->create(['is_admin' => false]);
        $this->configureLegacyTelegram($admin, 'admin-chat');
        $this->configureLegacyTelegram($investor, 'investor-chat');

        $this->assertTrue(app(TelegramNotificationService::class)->sendSyncFailureAlert('all providers unavailable'));

        $source = NotificationSource::query()->sole();
        $this->assertSame('operations.price_sync_failure', $source->notification_type);
        $this->assertSame('admin', $source->audience);
        $this->assertSame('critical', $source->severity);
        $this->assertStringContainsString('all providers unavailable', $source->message);
        $this->assertSame([$admin->id], RecipientNotification::query()->pluck('user_id')->all());
        $this->assertDatabaseCount('portfolio_notification_deliveries', 1);
        $this->assertSame('admin-chat', NotificationDelivery::query()->sole()->destination);
    }

    public function test_sync_failure_without_an_admin_does_not_create_an_orphan_source(): void
    {
        User::factory()->create(['is_admin' => false]);

        $this->assertFalse(app(TelegramNotificationService::class)->sendSyncFailureAlert('failure'));
        $this->assertDatabaseCount('portfolio_notification_sources', 0);
    }

    public function test_admin_operational_warning_uses_the_durable_account_channel(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['is_admin' => true]);
        NotificationChannelSetting::query()->create([
            'user_id' => $admin->id,
            'channel' => 'telegram',
            'enabled' => true,
            'configuration' => ['bot_token' => 'account-token', 'chat_id' => 'account-chat'],
            'health_status' => 'healthy',
            'verified_at' => now(),
        ]);

        $result = app(TelegramNotificationService::class)->sendAdminOperationalAlert(
            "Lido Portfolio — ops alert\n[WARNING] Provider rate limits detected\nReview provider configuration.",
        );

        $this->assertSame(['sent' => true, 'recipients' => 1], $result);
        $source = NotificationSource::query()->sole();
        $this->assertSame('operations.alert', $source->notification_type);
        $this->assertSame('action_required', $source->severity);
        $this->assertSame('Provider rate limits detected', $source->title);
        $this->assertSame('account-chat', NotificationDelivery::query()->sole()->destination);
        $this->assertSame(1, app(TelegramNotificationService::class)->countAdminTelegramRecipients());
    }

    public function test_admin_operational_alert_without_an_external_channel_reports_not_sent(): void
    {
        User::factory()->create(['is_admin' => true]);

        $result = app(TelegramNotificationService::class)->sendAdminOperationalAlert(
            "Lido Portfolio — ops alert\n[CRITICAL] Daily sync failed\nReview the failed run.",
        );

        $this->assertSame(['sent' => false, 'recipients' => 0], $result);
        $this->assertDatabaseCount('portfolio_notification_sources', 1);
        $this->assertDatabaseCount('portfolio_recipient_notifications', 1);
        $this->assertDatabaseCount('portfolio_notification_deliveries', 0);
    }

    private function configureLegacyTelegram(User $user, string $chatId): void
    {
        $profile = $this->defaultPortfolioFor($user);
        app(ProfileSettingsService::class)->update($profile, [
            'notifications_enabled' => 'true',
            'telegram_bot_token' => 'token-'.$chatId,
            'telegram_chat_id' => $chatId,
        ]);
    }
}
