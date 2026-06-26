<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ProfileSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProfileSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        $this->withHeaders([
            'Origin' => 'http://localhost',
            'Referer' => 'http://localhost',
        ]);
    }

    protected function actingAsPortfolioUser(): User
    {
        $user = User::query()->create([
            'name' => 'Settings User',
            'email' => 'settings-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $this->defaultPortfolioFor($user);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertOk();

        return $user;
    }

    public function test_profile_settings_are_isolated_between_profiles(): void
    {
        $userA = User::query()->create([
            'name' => 'User A',
            'email' => 'user-a-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profileA = $this->defaultPortfolioFor($userA);
        $userB = User::query()->create([
            'name' => 'User B',
            'email' => 'user-b-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profileB = $this->defaultPortfolioFor($userB);

        $settings = app(ProfileSettingsService::class);
        $settings->update($profileA, [
            'default_stoploss_percent' => '12',
            'telegram_bot_token' => 'token-a',
            'telegram_chat_id' => '111',
            'notification_schedules' => ['08:00'],
        ]);
        $settings->update($profileB, [
            'default_stoploss_percent' => '15',
            'telegram_bot_token' => 'token-b',
            'telegram_chat_id' => '222',
            'notification_schedules' => ['20:00'],
        ]);

        $this->assertSame('12', $settings->get($profileA, 'default_stoploss_percent'));
        $this->assertSame('15', $settings->get($profileB, 'default_stoploss_percent'));
        $this->assertSame(['08:00'], $settings->all($profileA)['notification_schedules']);
        $this->assertSame(['20:00'], $settings->all($profileB)['notification_schedules']);
    }

    public function test_settings_api_persists_profile_scoped_fields(): void
    {
        $user = $this->actingAsPortfolioUser();

        $this->putJson('/api/settings', [
            'default_stoploss_percent' => '8',
            'telegram_bot_token' => 'my-token',
            'telegram_chat_id' => '999',
            'notification_schedules' => ['07:30', '19:00'],
        ])->assertOk()
            ->assertJsonPath('data.default_stoploss_percent', '8')
            ->assertJsonPath('data.telegram_bot_token', 'my-token')
            ->assertJsonPath('data.notification_schedules', ['07:30', '19:00']);

        $other = User::query()->create([
            'name' => 'Other',
            'email' => 'other-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $otherProfile = $this->defaultPortfolioFor($other);

        $this->assertSame('10', app(ProfileSettingsService::class)->get($otherProfile, 'default_stoploss_percent'));
        $this->assertNotNull($user);
    }
}
