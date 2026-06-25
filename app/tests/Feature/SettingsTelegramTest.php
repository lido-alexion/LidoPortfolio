<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Holding;
use App\Models\Stock;
use App\Models\User;
use App\Services\TelegramNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class SettingsTelegramTest extends TestCase
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

    public function test_test_telegram_requires_credentials(): void
    {
        $this->actingAsPortfolioUser();

        $this->postJson('/api/settings/test-telegram', [])
            ->assertUnprocessable();
    }

    public function test_test_telegram_sends_no_alerts_message_when_empty(): void
    {
        $this->actingAsPortfolioUser();

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $this->postJson('/api/settings/test-telegram', [
            'telegram_bot_token' => 'test-token',
            'telegram_chat_id' => '12345',
        ])
            ->assertOk()
            ->assertJsonPath('alert_count', 0);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.telegram.org')
                && $request['text'] === 'No active alerts at this time';
        });
    }

    public function test_test_telegram_sends_active_alerts(): void
    {
        $user = $this->actingAsPortfolioUser();

        $stock = Stock::query()->create([
            'symbol' => 'TGTEST',
            'exchange' => 'NSE',
            'name' => 'Telegram Test',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        Holding::query()->create([
            'user_id' => $user->id,
            'stock_id' => $stock->id,
            'quantity' => 1,
            'avg_buy_price' => 100,
            'invested_amount' => 100,
            'realized_profit' => 0,
            'updated_at' => now(),
        ]);

        Alert::query()->create([
            'user_id' => $user->id,
            'stock_id' => $stock->id,
            'alert_type' => 'stoploss_triggered',
            'message' => 'Stoploss triggered for Telegram Test (TGTEST).',
            'is_sent' => false,
            'created_at' => now(),
        ]);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $this->postJson('/api/settings/test-telegram', [
            'telegram_bot_token' => 'test-token',
            'telegram_chat_id' => '12345',
        ])
            ->assertOk()
            ->assertJsonPath('alert_count', 1);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.telegram.org')
                && str_contains($request['text'], 'TGTEST');
        });
    }

    public function test_test_telegram_returns_422_on_delivery_failure(): void
    {
        $this->actingAsPortfolioUser();

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => false], 400),
        ]);

        $this->postJson('/api/settings/test-telegram', [
            'telegram_bot_token' => 'bad-token',
            'telegram_chat_id' => '12345',
        ])->assertStatus(422);
    }

    public function test_send_test_notification_bypasses_notifications_disabled(): void
    {
        $this->actingAsPortfolioUser();

        $telegram = $this->createMock(TelegramNotificationService::class);
        $telegram->expects($this->once())
            ->method('sendMessageWithCredentials')
            ->with('No active alerts at this time', 'token', 'chat')
            ->willReturn(true);
        $this->app->instance(TelegramNotificationService::class, $telegram);

        $this->postJson('/api/settings/test-telegram', [
            'telegram_bot_token' => 'token',
            'telegram_chat_id' => 'chat',
        ])->assertOk();
    }
}