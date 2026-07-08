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

    protected function findUniverseMaintenanceEvent(): ?Event
    {
        /** @var Schedule $schedule */
        $schedule = app(Schedule::class);

        foreach ($schedule->events() as $event) {
            if (str_contains((string) $event->command, 'portfolio:run-universe-maintenance')) {
                return $event;
            }
        }

        return null;
    }
}
