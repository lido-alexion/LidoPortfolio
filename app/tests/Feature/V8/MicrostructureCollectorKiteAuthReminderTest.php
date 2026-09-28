<?php

namespace Tests\Feature\V8;

use App\Models\CalendarEvent;
use App\Models\User;
use App\Services\Microstructure\MicrostructureCollectorKiteAuthReminderService;
use App\Services\Notification\NotificationPublisher;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class MicrostructureCollectorKiteAuthReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-07 08:45:00', 'Asia/Kolkata'));
        config([
            'microstructure_collector.enabled' => true,
            'microstructure_collector.kite_auth_reminder_time' => '08:45',
            'microstructure_collector.market_session_start' => '09:15',
            'microstructure_collector.market_session_end' => '15:30',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_sends_premarket_reminder_when_kite_missing(): void
    {
        $user = User::query()->create([
            'name' => 'Collector Op',
            'email' => 'op-'.Str::random(6).'@example.com',
            'password' => Hash::make('password123'),
        ]);
        config(['microstructure_collector.kite_user_id' => $user->id]);

        $publisher = $this->createMock(NotificationPublisher::class);
        $publisher->expects($this->once())
            ->method('publishCondition')
            ->with(
                MicrostructureCollectorKiteAuthReminderService::CONDITION_KEY,
                $this->callback(fn ($users) => count($users) === 1 && $users[0]->id === $user->id),
                $this->callback(fn ($payload) => ($payload['primary_action']['route'] ?? '') === '/dashboard'),
            );
        $this->app->instance(NotificationPublisher::class, $publisher);

        $result = app(MicrostructureCollectorKiteAuthReminderService::class)->sendDue();
        $this->assertTrue($result['sent']);
    }

    public function test_skips_when_collector_disabled(): void
    {
        config(['microstructure_collector.enabled' => false]);
        $result = app(MicrostructureCollectorKiteAuthReminderService::class)->sendDue();
        $this->assertTrue($result['skipped']);
        $this->assertSame('disabled', $result['reason']);
    }

    public function test_skips_without_auth_reminder_on_weekend(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-11 08:45:00', 'Asia/Kolkata'));
        config(['microstructure_collector.kite_user_id' => 999]);
        $result = app(MicrostructureCollectorKiteAuthReminderService::class)->sendDue();
        $this->assertTrue($result['skipped']);
        $this->assertSame('not_trading_day', $result['reason']);
    }

    public function test_skips_without_auth_reminder_on_fixed_exchange_holiday(): void
    {
        CalendarEvent::query()->create([
            'profile_id' => null,
            'category' => CalendarEvent::CATEGORY_TRADE_HOLIDAY,
            'title' => 'Fixed test holiday',
            'color' => CalendarEvent::TRADE_HOLIDAY_DEFAULT_COLOR,
            'anchor_date' => '2026-11-09',
            'recurrence_type' => CalendarEvent::RECURRENCE_NONE,
            'reminder_enabled' => false,
            'is_active' => true,
        ]);
        Carbon::setTestNow(Carbon::parse('2026-11-09 08:45:00', 'Asia/Kolkata'));
        config(['microstructure_collector.kite_user_id' => 999]);
        $result = app(MicrostructureCollectorKiteAuthReminderService::class)->sendDue();
        $this->assertTrue($result['skipped']);
        $this->assertSame('not_trading_day', $result['reason']);
    }
}
