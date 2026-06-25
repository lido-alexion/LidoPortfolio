<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\UserSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class UserSettingsTest extends TestCase
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

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertOk();

        return $user;
    }

    public function test_user_settings_are_isolated_between_users(): void
    {
        $userA = User::query()->create([
            'name' => 'User A',
            'email' => 'user-a-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $userB = User::query()->create([
            'name' => 'User B',
            'email' => 'user-b-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);

        $userSettings = app(UserSettingsService::class);
        $userSettings->update($userA, [
            'default_stoploss_percent' => '12',
            'telegram_bot_token' => 'token-a',
            'telegram_chat_id' => '111',
            'notification_schedules' => ['08:00'],
        ]);
        $userSettings->update($userB, [
            'default_stoploss_percent' => '15',
            'telegram_bot_token' => 'token-b',
            'telegram_chat_id' => '222',
            'notification_schedules' => ['20:00'],
        ]);

        $this->assertSame('12', $userSettings->get($userA, 'default_stoploss_percent'));
        $this->assertSame('15', $userSettings->get($userB, 'default_stoploss_percent'));
        $this->assertSame(['08:00'], $userSettings->all($userA)['notification_schedules']);
        $this->assertSame(['20:00'], $userSettings->all($userB)['notification_schedules']);
    }

    public function test_settings_api_persists_user_scoped_fields(): void
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

        $this->assertSame('10', app(UserSettingsService::class)->get($other, 'default_stoploss_percent'));
        $this->assertNotNull($user);
    }
}
