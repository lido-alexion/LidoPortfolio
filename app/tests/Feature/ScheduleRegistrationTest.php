<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\UniversePriceSyncService;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduleRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        putenv('TRADING_OS_ENABLED');
        putenv('TRADING_OS_PIPELINE_SCHEDULE');
        putenv('TRADING_OS_PIPELINE_TIME');
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function refreshScheduleApp(): void
    {
        $this->refreshApplication();
        $this->artisan('migrate', ['--force' => true]);
    }

    public function test_universe_maintenance_schedule_uses_cron_timezone_from_settings(): void
    {
        config(['portfolio.universe_price_sync.enabled' => true]);

        $this->refreshScheduleApp();

        Setting::setValue('cron_timezone', 'Asia/Kolkata');

        $event = $this->findUniverseMaintenanceEvent();
        $this->assertNotNull($event, 'universe-maintenance schedule event not registered');
        $this->assertSame('Asia/Kolkata', $event->timezone);
        $this->assertSame('* * * * *', $event->getExpression());
    }

    public function test_universe_maintenance_not_due_at_five_am_ist_when_timezone_is_kolkata(): void
    {
        config(['portfolio.universe_price_sync.enabled' => true]);

        $this->refreshScheduleApp();

        Setting::setValue('cron_timezone', 'Asia/Kolkata');

        $event = $this->findUniverseMaintenanceEvent();
        $this->assertNotNull($event);

        Carbon::setTestNow(Carbon::parse('2026-07-07 05:00:04', 'Asia/Kolkata'));

        $service = app(UniversePriceSyncService::class);
        $this->assertFalse(
            $service->isMaintenanceWindowDue(),
            'Universe maintenance window must be closed at 05:00 IST',
        );

        Carbon::setTestNow(Carbon::parse('2026-07-07 19:15:00', 'Asia/Kolkata'));

        $this->assertTrue(
            $service->isMaintenanceWindowDue(),
            'Universe maintenance window should be open at 19:15 IST',
        );

        $this->assertTrue(
            $event->isDue($this->app),
            'Universe maintenance should be due at 19:15 IST',
        );

        Carbon::setTestNow();
    }

    public function test_universe_maintenance_window_helper_uses_scheduler_timezone(): void
    {
        config(['portfolio.universe_price_sync.enabled' => true]);

        $this->refreshScheduleApp();

        Setting::setValue('cron_timezone', 'Asia/Kolkata');

        $service = app(UniversePriceSyncService::class);

        $this->assertFalse($service->isMaintenanceWindowDue(Carbon::parse('2026-07-07 02:15:00', 'Asia/Kolkata')));
        $this->assertTrue($service->isMaintenanceWindowDue(Carbon::parse('2026-07-07 19:20:00', 'Asia/Kolkata')));
        $this->assertFalse($service->isMaintenanceWindowDue(Carbon::parse('2026-07-07 19:17:00', 'Asia/Kolkata')));
        // Saturday — skipped when prior Friday had no failures.
        $this->assertFalse($service->isMaintenanceWindowDue(Carbon::parse('2026-08-01 19:20:00', 'Asia/Kolkata')));
        $this->assertSame(58, $service->maintenanceRunsPerNight());
        $this->assertSame(7250, $service->maintenanceNightlyCapacity(125));
    }

    public function test_schedule_registers_heartbeat_probe(): void
    {
        config(['portfolio.universe_price_sync.enabled' => true]);

        $this->refreshScheduleApp();

        /** @var Schedule $schedule */
        $schedule = app(Schedule::class);
        $foundHeartbeat = false;
        $foundProbe = false;

        foreach ($schedule->events() as $event) {
            $command = (string) $event->command;
            if (str_contains($command, 'portfolio:universe-maintenance-probe')
                && str_contains($command, 'write-heartbeat')) {
                $foundHeartbeat = true;
            }
            if (str_contains($command, 'portfolio:universe-maintenance-probe')
                && str_contains($command, 'explain')) {
                $foundProbe = true;
            }
        }

        $this->assertTrue($foundHeartbeat, 'heartbeat probe must be registered every minute');
        $this->assertTrue($foundProbe, 'explain probe must be registered on maintenance due ticks');
    }

    public function test_universe_maintenance_probe_writes_heartbeat_and_explain(): void
    {
        config(['portfolio.universe_price_sync.enabled' => true]);

        Setting::setValue('cron_timezone', 'Asia/Kolkata');
        Carbon::setTestNow(Carbon::parse('2026-07-08 20:00:00', 'Asia/Kolkata'));

        $this->artisan('portfolio:universe-maintenance-probe', [
            '--write-heartbeat' => true,
            '--explain' => true,
        ])->assertSuccessful();

        $this->assertNotNull(Setting::getValue(\App\Console\Commands\UniverseMaintenanceProbeCommand::KEY_SCHEDULE_HEARTBEAT_AT));
        $probe = json_decode((string) Setting::getValue(\App\Console\Commands\UniverseMaintenanceProbeCommand::KEY_MAINTENANCE_PROBE_JSON), true);
        $this->assertIsArray($probe);
        $this->assertTrue($probe['is_maintenance_window_due']);
        $this->assertSame('none_should_run', $probe['would_skip_reason']);

        Carbon::setTestNow();
    }

    public function test_daily_market_sync_skips_non_session_days(): void
    {
        Setting::setValue('cron_timezone', 'Asia/Kolkata');
        $this->refreshScheduleApp();

        $event = $this->findScheduleEvent('portfolio:daily-sync');
        $this->assertNotNull($event, 'daily-market-data schedule event must be registered');

        Carbon::setTestNow(Carbon::parse('2026-08-08 18:30:00', 'Asia/Kolkata'));
        $this->assertFalse(
            \App\Support\TradingCalendar::isScheduledMarketDataDay(timezone: 'Asia/Kolkata'),
            'Saturday must not run scheduled market-data sync'
        );

        Carbon::setTestNow(Carbon::parse('2026-08-07 18:30:00', 'Asia/Kolkata'));
        $this->assertTrue(
            \App\Support\TradingCalendar::isScheduledMarketDataDay(timezone: 'Asia/Kolkata'),
            'Friday must allow scheduled market-data sync'
        );

        Carbon::setTestNow();
    }

    public function test_decision_pipeline_registered_by_default_for_unattended_production(): void
    {
        putenv('TRADING_OS_ENABLED');
        putenv('TRADING_OS_PIPELINE_SCHEDULE');
        putenv('TRADING_OS_PIPELINE_TIME');
        $this->refreshScheduleApp();

        $event = $this->findScheduleEvent('portfolio:decision-pipeline');
        $this->assertNotNull($event, 'V4-FEAT-010: daily pipeline must be on Laravel schedule:run by default');
        $this->assertStringContainsString('scheduled', (string) $event->command);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(45, $event->expiresAt);
        $this->assertSame('0 19 * * *', $event->getExpression());
    }

    public function test_decision_pipeline_not_registered_when_schedule_disabled(): void
    {
        putenv('TRADING_OS_ENABLED=true');
        putenv('TRADING_OS_PIPELINE_SCHEDULE=false');
        $this->refreshScheduleApp();

        $this->assertNull($this->findScheduleEvent('portfolio:decision-pipeline'));
    }

    public function test_decision_pipeline_registered_when_schedule_enabled(): void
    {
        putenv('TRADING_OS_ENABLED=true');
        putenv('TRADING_OS_PIPELINE_SCHEDULE=true');
        putenv('TRADING_OS_PIPELINE_TIME=19:15');
        $this->refreshScheduleApp();

        $event = $this->findScheduleEvent('portfolio:decision-pipeline');
        $this->assertNotNull($event, 'trading-os-decision-pipeline schedule event must be registered');
        $this->assertStringContainsString('portfolio:decision-pipeline', (string) $event->command);
        $this->assertStringContainsString('scheduled', (string) $event->command);
        $this->assertSame('15 19 * * *', $event->getExpression());
        $this->assertTrue($event->withoutOverlapping, 'scheduled pipeline must use withoutOverlapping');
        $this->assertSame(45, $event->expiresAt);
    }

    public function test_broker_reconcile_and_automatic_submit_are_scheduled_without_overlapping(): void
    {
        $this->refreshScheduleApp();

        $reconcile = $this->findScheduleEvent('tos:reconcile-broker-orders');
        $this->assertNotNull($reconcile, 'tos:reconcile-broker-orders must be on Laravel schedule:run');
        $this->assertTrue($reconcile->withoutOverlapping);
        $this->assertSame(5, $reconcile->expiresAt);
        $this->assertSame('*/5 * * * *', $reconcile->getExpression());

        $submit = $this->findScheduleEvent('tos:submit-automatic-orders');
        $this->assertNotNull($submit, 'tos:submit-automatic-orders must be on Laravel schedule:run');
        $this->assertTrue($submit->withoutOverlapping);
        $this->assertSame(5, $submit->expiresAt);
        $this->assertSame('*/5 * * * *', $submit->getExpression());
    }

    protected function findUniverseMaintenanceEvent(): ?Event
    {
        return $this->findScheduleEvent('portfolio:run-universe-maintenance');
    }

    protected function findScheduleEvent(string $needle): ?Event
    {
        /** @var Schedule $schedule */
        $schedule = app(Schedule::class);

        foreach ($schedule->events() as $event) {
            if (str_contains((string) $event->command, $needle)) {
                return $event;
            }
        }

        return null;
    }
}
