<?php

namespace Tests\Feature\V8;

use App\Models\CalendarEvent;
use App\Models\User;
use App\Services\Broker\BrokerConnectionService;
use App\Services\Microstructure\MicrostructureCollectorControlService;
use App\Services\Microstructure\MicrostructureCollectorKiteAuthReminderService;
use App\Services\Notification\NotificationPublisher;
use Illuminate\Support\Carbon;
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
        Carbon::setTestNow(Carbon::parse('2026-07-07 09:00:00', 'Asia/Kolkata'));
        config([
            'microstructure_collector.enabled' => true,
            'microstructure_collector.kite_auth_reminder_time' => '09:00',
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
                $this->callback(fn ($payload) => ($payload['primary_action']['route'] ?? '') === '/kite-connect'),
            );
        $this->app->instance(NotificationPublisher::class, $publisher);

        $result = app(MicrostructureCollectorKiteAuthReminderService::class)->sendDue();
        $this->assertTrue($result['sent']);
        $repeat = app(MicrostructureCollectorKiteAuthReminderService::class)->sendDue(
            Carbon::parse('2026-07-07 09:00:30', 'Asia/Kolkata'),
        );
        $this->assertSame('already_sent', $repeat['reason']);
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

    public function test_alerts_when_a_usable_session_has_no_live_socket_or_packets_after_grace(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-07 09:20:00', 'Asia/Kolkata'));
        $user = User::query()->create([
            'name' => 'Collector Op',
            'email' => 'op-'.Str::random(6).'@example.com',
            'password' => Hash::make('password123'),
        ]);
        config(['microstructure_collector.kite_user_id' => $user->id]);
        $connections = $this->createMock(BrokerConnectionService::class);
        $connections->expects($this->once())->method('status')->willReturn(['usable' => true]);
        $this->app->instance(BrokerConnectionService::class, $connections);
        $collector = $this->createMock(MicrostructureCollectorControlService::class);
        $collector->expects($this->once())->method('operationalStatus')->willReturn([
            'websocket_connected' => false, 'last_packet_at' => null,
        ]);
        $this->app->instance(MicrostructureCollectorControlService::class, $collector);

        $publisher = $this->createMock(NotificationPublisher::class);
        $publisher->expects($this->once())->method('publishCondition')->with(
            MicrostructureCollectorKiteAuthReminderService::PACKET_ALERT_KEY,
            $this->callback(fn ($users) => count($users) === 1 && $users[0]->id === $user->id),
            $this->callback(fn ($payload) => ($payload['primary_action']['route'] ?? '') === '/kite-connect'),
        );
        $publisher->expects($this->once())->method('resolveCondition')->with(MicrostructureCollectorKiteAuthReminderService::CONDITION_KEY);
        $this->app->instance(NotificationPublisher::class, $publisher);

        app(MicrostructureCollectorKiteAuthReminderService::class)->sendDue();
    }
}
