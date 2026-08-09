<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\CalendarEvent;
use App\Models\Holding;
use App\Models\Stock;
use App\Models\User;
use App\Services\AlertNotificationService;
use App\Services\TelegramNotificationService;
use App\Support\TradingCalendar;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AlertNotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-07 10:00:00', 'Asia/Kolkata'));
        TradingCalendar::clearHolidayCache();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        TradingCalendar::clearHolidayCache();
        parent::tearDown();
    }

    public function test_scheduled_notifications_send_clear_ping_when_flag_enabled_and_no_alerts(): void
    {
        \App\Models\Setting::setValue('admin_ops_telegram_ping_when_clear', 'true');

        $user = User::query()->create([
            'name' => 'Alert Notify',
            'email' => 'alert-clear-ping-'.Str::random(8).'@example.com',
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
        $telegram->expects($this->once())
            ->method('sendMessageForProfile')
            ->with(
                $this->callback(fn ($p) => $p->id === $profile->id),
                $this->callback(fn (string $message) => str_contains(strtolower($message), 'no active alerts')
                    && str_contains($message, '10:00')),
            )
            ->willReturn(true);
        $this->app->instance(TelegramNotificationService::class, $telegram);

        $result = app(AlertNotificationService::class)->sendScheduledNotificationsAt('10:00');

        $this->assertFalse($result['skipped']);
        $this->assertSame(0, $result['alert_count']);
        $this->assertTrue($result['sent']);
        $this->assertSame(1, $result['profiles_notified']);
    }

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

    public function test_scheduled_notifications_skip_on_weekend(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01 10:00:00', 'Asia/Kolkata'));

        $user = User::query()->create([
            'name' => 'Weekend Skip',
            'email' => 'alert-weekend-'.Str::random(8).'@example.com',
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
        $this->assertSame('weekend', $result['skip_reason'] ?? null);
    }

    public function test_scheduled_notifications_skip_on_trade_holiday(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-02 10:00:00', 'Asia/Kolkata'));

        CalendarEvent::query()->create([
            'profile_id' => null,
            'category' => CalendarEvent::CATEGORY_TRADE_HOLIDAY,
            'title' => 'Gandhi Jayanti',
            'color' => CalendarEvent::TRADE_HOLIDAY_DEFAULT_COLOR,
            'anchor_date' => '2026-10-02',
            'recurrence_type' => CalendarEvent::RECURRENCE_NONE,
            'reminder_enabled' => false,
            'is_active' => true,
        ]);
        TradingCalendar::clearHolidayCache();

        $user = User::query()->create([
            'name' => 'Holiday Skip',
            'email' => 'alert-holiday-'.Str::random(8).'@example.com',
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
        $this->assertSame('trade_holiday', $result['skip_reason'] ?? null);
    }

    public function test_scheduled_notifications_repeat_digest_for_same_active_alert(): void
    {
        $user = User::query()->create([
            'name' => 'Repeat Digest',
            'email' => 'alert-repeat-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);

        $stock = Stock::query()->create([
            'symbol' => 'RPTA',
            'exchange' => 'NSE',
            'name' => 'Repeat Alert Stock',
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
            'alert_type' => 'policy',
            'message' => 'Still active for RPTA',
            'is_sent' => false,
            'created_at' => now(),
        ]);

        $telegram = $this->createMock(TelegramNotificationService::class);
        $telegram->expects($this->exactly(2))
            ->method('sendMessageForProfile')
            ->with(
                $this->callback(fn ($p) => $p->id === $profile->id),
                $this->stringContains('RPTA'),
            )
            ->willReturn(true);
        $this->app->instance(TelegramNotificationService::class, $telegram);

        app(\App\Services\NotificationScheduleService::class)->persistForProfile($profile, ['10:00', '15:00']);
        app(\App\Services\ProfileSettingsService::class)->update($profile, [
            'notifications_enabled' => 'true',
            'telegram_bot_token' => 'token',
            'telegram_chat_id' => 'chat',
        ]);

        $first = app(AlertNotificationService::class)->sendScheduledNotificationsAt('10:00');
        $second = app(AlertNotificationService::class)->sendScheduledNotificationsAt('15:00');

        $this->assertTrue($first['sent']);
        $this->assertTrue($second['sent']);
        $this->assertSame(1, $first['alert_count']);
        $this->assertSame(1, $second['alert_count']);
        $this->assertNull(Alert::query()->first()->expired_at);
        $this->assertFalse(Alert::query()->first()->is_sent);
    }

    public function test_empty_notification_schedule_does_not_send_digest(): void
    {
        $user = User::query()->create([
            'name' => 'No Schedule',
            'email' => 'alert-nosched-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);

        $stock = Stock::query()->create([
            'symbol' => 'NOSC',
            'exchange' => 'NSE',
            'name' => 'No Schedule Stock',
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
            'alert_type' => 'policy',
            'message' => 'In-app only',
            'is_sent' => false,
            'created_at' => now(),
        ]);

        app(\App\Services\NotificationScheduleService::class)->persistForProfile($profile, []);
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
        $this->assertSame(1, Alert::query()->whereNull('expired_at')->count());
    }
}
