<?php

namespace Tests\Feature\Notification;

use App\Models\NotificationChannelSetting;
use App\Models\User;
use App\Services\Notification\LegacyTelegramChannelMigrator;
use App\Services\Notification\NotificationPublisher;
use App\Services\ProfileSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LegacyTelegramChannelMigratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_unique_legacy_configuration_is_migrated_encrypted_and_used_for_delivery(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        app(ProfileSettingsService::class)->update($profile, [
            'telegram_bot_token' => 'legacy-secret-token',
            'telegram_chat_id' => 'legacy-chat',
            'notifications_enabled' => 'true',
        ]);

        app(NotificationPublisher::class)->publishCondition('legacy:migration', [$user], $this->content());

        $setting = NotificationChannelSetting::query()->sole();
        $this->assertTrue($setting->enabled);
        $this->assertNotNull($setting->verified_at);
        $this->assertSame('legacy-chat', $setting->configuration['chat_id']);
        $this->assertDatabaseCount('portfolio_notification_deliveries', 1);
        $raw = (string) DB::table('portfolio_notification_channel_settings')->value('configuration');
        $this->assertStringNotContainsString('legacy-secret-token', $raw);
    }

    public function test_conflicting_legacy_configurations_are_not_silently_selected(): void
    {
        $user = User::factory()->create();
        $first = $this->defaultPortfolioFor($user);
        $second = $user->portfolios()->create(['name' => 'Second']);
        $settings = app(ProfileSettingsService::class);
        $settings->update($first, ['telegram_bot_token' => 'one', 'telegram_chat_id' => 'chat-one']);
        $settings->update($second, ['telegram_bot_token' => 'two', 'telegram_chat_id' => 'chat-two']);

        $this->assertNull(app(LegacyTelegramChannelMigrator::class)->migrateIfUnambiguous($user));
        $this->assertDatabaseCount('portfolio_notification_channel_settings', 0);
    }

    private function content(): array
    {
        return [
            'notification_type' => 'test.legacy_migration',
            'audience' => 'investor',
            'severity' => 'action_required',
            'title' => 'Legacy migration test',
            'message' => 'Legacy Telegram delivery remains available.',
        ];
    }
}
