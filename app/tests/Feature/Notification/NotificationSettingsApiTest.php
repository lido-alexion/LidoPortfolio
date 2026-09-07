<?php

namespace Tests\Feature\Notification;

use App\Models\NotificationChannelSetting;
use App\Models\User;
use App\Services\Notification\NotificationChannelSettingsService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NotificationSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_settings_are_account_scoped_and_in_app_is_mandatory(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/notification-settings')
            ->assertOk()
            ->assertJsonPath('data.0.channel', 'in_app')
            ->assertJsonPath('data.0.enabled', true)
            ->assertJsonPath('data.0.can_disable', false)
            ->assertJsonCount(4, 'data');

        $this->actingAs($user)->putJson('/api/notification-settings/in_app', ['enabled' => false])
            ->assertNotFound();
    }

    public function test_external_channel_cannot_be_enabled_before_successful_verification(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->putJson('/api/notification-settings/telegram', [
            'enabled' => true,
            'bot_token' => 'secret-bot-token',
            'chat_id' => 'chat-42',
        ])->assertUnprocessable()->assertJsonValidationErrors('enabled');
    }

    public function test_verified_channel_can_be_enabled_and_secrets_are_encrypted_and_hidden(): void
    {
        $user = User::factory()->create();
        $payload = ['enabled' => false, 'bot_token' => 'secret-bot-token', 'chat_id' => 'chat-42'];
        $this->actingAs($user)->putJson('/api/notification-settings/telegram', $payload)->assertOk();
        app(NotificationChannelSettingsService::class)->markVerified($user, 'telegram');

        $this->actingAs($user)->putJson('/api/notification-settings/telegram', [...$payload, 'enabled' => true])
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.bot_token_configured', true)
            ->assertJsonMissingPath('data.bot_token');

        $raw = (string) DB::table('portfolio_notification_channel_settings')->value('configuration');
        $this->assertStringNotContainsString('secret-bot-token', $raw);
    }

    public function test_material_destination_change_returns_channel_to_unverified_and_disabled(): void
    {
        $user = User::factory()->create();
        $service = app(NotificationChannelSettingsService::class);
        $service->update($user, 'telegram', ['bot_token' => 'token', 'chat_id' => 'first'], false);
        $service->markVerified($user, 'telegram');
        $service->update($user, 'telegram', ['bot_token' => 'token', 'chat_id' => 'first'], true);

        $changed = $service->update($user, 'telegram', ['bot_token' => 'token', 'chat_id' => 'second'], null);

        $this->assertFalse($changed['enabled']);
        $this->assertSame('unverified', $changed['health_status']);
        $this->assertNull(NotificationChannelSetting::query()->firstOrFail()->verified_at);
    }

    public function test_webhook_requires_https_and_admin_settings_need_no_portfolio(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->putJson('/api/notification-settings/webhook', [
            'enabled' => false,
            'url' => 'http://example.com/hook',
        ])->assertUnprocessable()->assertJsonValidationErrors('url');
        $this->actingAs($admin)->getJson('/api/notification-settings')->assertOk();
        $this->assertDatabaseMissing('portfolio_profiles', ['user_id' => $admin->id]);
    }
}
