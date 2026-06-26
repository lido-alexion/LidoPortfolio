<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Holding;
use App\Models\Stock;
use App\Models\User;
use App\Services\AlertNotificationService;
use App\Services\TelegramNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AlertNotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduled_notifications_skip_silently_when_no_alerts(): void
    {
        $user = User::query()->create([
            'name' => 'Alert Notify',
            'email' => 'alert-empty-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);

        app(\App\Services\NotificationScheduleService::class)->persistForProfile($profile, ['10:00']);
        app(\App\Services\ProfileSettingsService::class)->update($profile, [
            'notifications_enabled' => 'true',
            'telegram_bot_token' => 'token',
            'telegram_chat_id' => 'chat',
        ]);

        $telegram = $this->createMock(TelegramNotificationService::class);
        $telegram->expects($this->never())->method('sendMessageForProfile');
        $this->app->instance(TelegramNotificationService::class, $telegram);

        $result = app(AlertNotificationService::class)->sendScheduledNotificationsAt('10:00');

        $this->assertTrue($result['skipped']);
        $this->assertSame(0, $result['alert_count']);
        $this->assertFalse($result['sent']);
    }

    public function test_scheduled_notifications_send_active_alerts(): void
    {
        $user = User::query()->create([
            'name' => 'Alert Notify',
            'email' => 'alert-notify-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);

        $stock = Stock::query()->create([
            'symbol' => 'ALERT',
            'exchange' => 'NSE',
            'name' => 'Alert Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'quantity' => 1,
            'avg_buy_price' => 100,
            'invested_amount' => 100,
            'realized_profit' => 0,
            'updated_at' => now(),
        ]);

        Alert::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'alert_type' => 'stoploss_triggered',
            'message' => 'Stoploss triggered for Alert Stock (ALERT). Latest close: 90.00',
            'is_sent' => false,
            'created_at' => now(),
        ]);

        $telegram = $this->createMock(TelegramNotificationService::class);
        $telegram->expects($this->once())
            ->method('sendMessageForProfile')
            ->with(
                $this->callback(fn ($p) => $p->id === $profile->id),
                $this->stringContains('ALERT'),
            )
            ->willReturn(true);
        $this->app->instance(TelegramNotificationService::class, $telegram);

        app(\App\Services\NotificationScheduleService::class)->persistForProfile($profile, ['10:00']);
        app(\App\Services\ProfileSettingsService::class)->update($profile, [
            'notifications_enabled' => 'true',
            'telegram_bot_token' => 'token',
            'telegram_chat_id' => 'chat',
        ]);

        $result = app(AlertNotificationService::class)->sendScheduledNotificationsAt('10:00');

        $this->assertFalse($result['skipped']);
        $this->assertSame(1, $result['alert_count']);
        $this->assertTrue($result['sent']);
    }
}



