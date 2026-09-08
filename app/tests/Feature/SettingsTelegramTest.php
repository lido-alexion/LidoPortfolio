<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SettingsTelegramTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_portfolio_telegram_test_endpoint_points_to_account_notification_settings(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $this->defaultPortfolioFor($user);

        $this->actingAs($user)->postJson('/api/settings/test-telegram', [
            'telegram_bot_token' => 'legacy-token',
            'telegram_chat_id' => 'legacy-chat',
        ])->assertStatus(410)
            ->assertJsonPath('notification_settings_url', '/settings/notifications');

        Http::assertNothingSent();
    }
}
