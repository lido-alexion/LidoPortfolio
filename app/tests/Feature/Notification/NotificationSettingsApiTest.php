<?php

namespace Tests\Feature\Notification;

use App\Mail\NotificationChannelTestMail;
use App\Mail\NotificationEmailVerificationMail;
use App\Models\NotificationChannelSetting;
use App\Models\User;
use App\Services\Notification\NotificationChannelSettingsService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
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

    public function test_verified_telegram_can_be_enabled_without_resending_its_token(): void
    {
        $user = User::factory()->create();
        $service = app(NotificationChannelSettingsService::class);
        $service->update($user, 'telegram', ['bot_token' => 'secret-bot-token', 'chat_id' => 'chat-42'], false);
        $service->markVerified($user, 'telegram');

        $this->actingAs($user)->putJson('/api/notification-settings/telegram', [
            'enabled' => true,
            'chat_id' => 'chat-42',
        ])->assertOk()->assertJsonPath('data.enabled', true);
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

    public function test_webhook_rejects_local_destinations_and_reveals_generated_secret_once(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->putJson('/api/notification-settings/webhook', [
            'enabled' => false,
            'url' => 'https://127.0.0.1/hook',
        ])->assertUnprocessable()->assertJsonValidationErrors('url');

        $created = $this->actingAs($user)->putJson('/api/notification-settings/webhook', [
            'enabled' => false,
            'url' => 'https://hooks.example.test/stox',
        ])->assertOk();
        $this->assertSame(64, strlen((string) $created->json('data.signing_secret_once')));
        $this->actingAs($user)->getJson('/api/notification-settings')
            ->assertOk()
            ->assertJsonMissingPath('data.3.signing_secret_once');
    }

    public function test_telegram_test_exercises_adapter_and_verifies_without_creating_notification(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $user = User::factory()->create();
        $this->actingAs($user)->putJson('/api/notification-settings/telegram', [
            'enabled' => false,
            'bot_token' => 'telegram-secret',
            'chat_id' => 'chat-99',
        ])->assertOk();

        $this->actingAs($user)->postJson('/api/notification-settings/telegram/test')
            ->assertOk()
            ->assertJsonPath('data.successful', true);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'telegram-secret')
            && $request['chat_id'] === 'chat-99');
        $this->assertNotNull(NotificationChannelSetting::query()->firstOrFail()->verified_at);
        $this->assertDatabaseCount('portfolio_notification_sources', 0);
    }

    public function test_failed_explicit_test_is_sanitized_and_does_not_create_health_notification(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['description' => 'secret diagnostic'], 400)]);
        $user = User::factory()->create();
        $this->actingAs($user)->putJson('/api/notification-settings/telegram', [
            'enabled' => false,
            'bot_token' => 'bad-secret',
            'chat_id' => 'bad-chat',
        ])->assertOk();

        $response = $this->actingAs($user)->postJson('/api/notification-settings/telegram/test')
            ->assertUnprocessable()
            ->assertJsonPath('data.successful', false);
        $this->assertStringNotContainsString('secret diagnostic', $response->getContent());
        $this->assertDatabaseCount('portfolio_notification_sources', 0);
    }

    public function test_webhook_test_is_versioned_and_hmac_signed(): void
    {
        Http::fake(['https://hooks.example.test/stox' => Http::response([], 204)]);
        $user = User::factory()->create();
        $this->actingAs($user)->putJson('/api/notification-settings/webhook', [
            'enabled' => false,
            'url' => 'https://hooks.example.test/stox',
        ])->assertOk();
        $secret = NotificationChannelSetting::query()->firstOrFail()->configuration['signing_secret'];

        $this->actingAs($user)->postJson('/api/notification-settings/webhook/test')->assertOk();

        $request = Http::recorded()->first()[0];
        $this->assertSame('https://hooks.example.test/stox', $request->url());
        $this->assertSame('1.0', $request->header('X-StoX-Schema-Version')[0]);
        $this->assertSame(
            'sha256='.hash_hmac('sha256', $request->body(), $secret),
            $request->header('X-StoX-Signature')[0],
        );
    }

    public function test_email_test_uses_account_email_as_individual_recipient(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'investor@example.test']);
        $this->actingAs($user)->putJson('/api/notification-settings/email', ['enabled' => false])->assertOk();

        $this->actingAs($user)->postJson('/api/notification-settings/email/test')->assertOk();

        Mail::assertSent(NotificationChannelTestMail::class, fn ($mail) => $mail->hasTo('investor@example.test'));
    }

    public function test_optional_email_destination_requires_signed_verification_and_can_be_removed(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $created = $this->actingAs($user)->postJson('/api/notification-settings/email-destinations', [
            'email' => 'alerts@example.test',
        ])->assertCreated()->assertJsonPath('data.email', 'alerts@example.test');

        $destinationId = $created->json('data.id');
        $this->assertDatabaseHas('portfolio_notification_email_destinations', [
            'id' => $destinationId,
            'email' => 'alerts@example.test',
            'verified_at' => null,
        ]);
        Mail::assertSent(NotificationEmailVerificationMail::class, fn ($mail) => $mail->hasTo('alerts@example.test'));

        $mail = null;
        Mail::assertSent(NotificationEmailVerificationMail::class, function ($sent) use (&$mail) {
            $mail = $sent;
            return true;
        });
        parse_str((string) parse_url($mail->verificationUrl, PHP_URL_QUERY), $query);
        $this->getJson('/api/notification-settings/email-destinations/'.$destinationId.'/verify?'.http_build_query($query))
            ->assertOk()->assertJsonPath('data.verified', true);

        $this->actingAs($user)->deleteJson('/api/notification-settings/email-destinations/'.$destinationId)
            ->assertOk()->assertJsonPath('data.deleted', true);
    }

    public function test_account_email_cannot_be_removed(): void
    {
        $user = User::factory()->create(['email' => 'account@example.test']);
        $destination = app(NotificationChannelSettingsService::class)->emailDestinations($user)[0];

        $this->actingAs($user)->deleteJson('/api/notification-settings/email-destinations/'.$destination['id'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
    }
}
