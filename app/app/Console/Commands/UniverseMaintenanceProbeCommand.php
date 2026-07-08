<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\PortfolioLoggerService;
use App\Services\SettingsService;
use App\Services\UniversePriceSyncService;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;

class UniverseMaintenanceProbeCommand extends Command
{
    public const KEY_SCHEDULE_HEARTBEAT_AT = 'schedule_run_heartbeat_at';

    public const KEY_MAINTENANCE_PROBE_JSON = 'universe_maintenance_probe_json';

    protected $signature = 'portfolio:universe-maintenance-probe
        {--write-heartbeat : Persist schedule:run heartbeat timestamp}
        {--explain : Evaluate maintenance window + mutex and write probe JSON}';

    protected $description = 'Record schedule heartbeats and explain why universe maintenance may or may not run';

    public function handle(
        SettingsService $settings,
        UniversePriceSyncService $sync,
        PortfolioLoggerService $logger,
    ): int {
        $cronTimezone = $settings->get('cron_timezone', 'Asia/Kolkata') ?? 'Asia/Kolkata';
        $now = now()->timezone($cronTimezone);
        $probe = $this->buildProbe($sync, $cronTimezone, $now);

        if ($this->option('write-heartbeat')) {
            Setting::setValue(self::KEY_SCHEDULE_HEARTBEAT_AT, now()->toIso8601String());
            $this->line('Heartbeat written: '.$now->format('Y-m-d H:i:s T'));

            // Minute-level trail while backend_log_level=debug. Shows cron alive + why
            // maintenance may not fire even when schedule:run runs (window/interval/mutex).
            $logger->scheduler('debug', 'schedule:run heartbeat', [
                'event' => 'schedule_heartbeat',
                ...$probe,
            ]);
        }

        if (! $this->option('explain')) {
            return self::SUCCESS;
        }

        Setting::setValue(self::KEY_MAINTENANCE_PROBE_JSON, json_encode($probe));

        $logger->scheduler('info', 'Universe maintenance probe', [
            'event' => 'maintenance_probe',
            ...$probe,
        ]);

        $this->table(
            ['Probe field', 'Value'],
            collect($probe)->map(fn ($value, $key) => [
                $key,
                is_bool($value) ? ($value ? 'yes' : 'no') : (string) ($value ?? '—'),
            ])->values()->all(),
        );

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildProbe(UniversePriceSyncService $sync, string $cronTimezone, $now): array
    {
        $due = $sync->isMaintenanceWindowDue();
        $windowOpen = $this->isInsideMaintenanceHours($sync, $now);
        $intervalOk = ((int) $now->format('i')) % $sync->maintenanceIntervalMinutes() === 0;
        $inProgress = $sync->isSyncInProgress();
        $mutexHeld = $this->universeMaintenanceMutexHeld();
        $throughput = $sync->maintenanceThroughput(0);

        return [
            'recorded_at' => now()->toIso8601String(),
            'cron_timezone' => $cronTimezone,
            'local_time' => $now->format('Y-m-d H:i:s T'),
            'app_timezone' => (string) config('app.timezone'),
            'universe_enabled' => $sync->isEnabled(),
            'batch_size_config' => (int) config('portfolio.universe_price_sync.batch_size', 125),
            'window_open' => $windowOpen,
            'interval_slot' => $intervalOk,
            'is_maintenance_window_due' => $due,
            'is_window_start' => $sync->isMaintenanceWindowStart(),
            'in_progress' => $inProgress,
            'in_progress_at' => Setting::getValue(UniversePriceSyncService::KEY_IN_PROGRESS_AT),
            'cursor_stock_id' => (int) Setting::getValue(UniversePriceSyncService::KEY_CURSOR_STOCK_ID, '0'),
            'mutex_held' => $mutexHeld,
            'mutex_name' => $this->universeMaintenanceMutexName(),
            'would_skip_reason' => $this->skipReason($sync, $due, $inProgress, $mutexHeld),
            'window_label' => $throughput['window_label'] ?? null,
            'interval_minutes' => $sync->maintenanceIntervalMinutes(),
            'start_hour' => $sync->maintenanceStartHour(),
            'end_hour' => $sync->maintenanceEndHour(),
            'end_minute' => $sync->maintenanceEndMinute(),
        ];
    }

    protected function isInsideMaintenanceHours(UniversePriceSyncService $sync, $now): bool
    {
        $hour = (int) $now->format('G');
        $minute = (int) $now->format('i');

        if ($hour < $sync->maintenanceStartHour() || $hour > $sync->maintenanceEndHour()) {
            return false;
        }

        if ($hour === $sync->maintenanceEndHour() && $minute > $sync->maintenanceEndMinute()) {
            return false;
        }

        return true;
    }

    protected function skipReason(
        UniversePriceSyncService $sync,
        bool $due,
        bool $inProgress,
        bool $mutexHeld,
    ): string {
        if (! $sync->isEnabled()) {
            return 'universe_disabled';
        }
        if (! $due) {
            return 'not_due_window_or_interval';
        }
        if ($mutexHeld) {
            return 'schedule_mutex_held';
        }
        if ($inProgress) {
            return 'sync_in_progress_flag';
        }

        return 'none_should_run';
    }

    protected function universeMaintenanceMutexName(): ?string
    {
        try {
            /** @var Schedule $schedule */
            $schedule = app(Schedule::class);
            foreach ($schedule->events() as $event) {
                if (str_contains((string) $event->command, 'portfolio:run-universe-maintenance')) {
                    return $event->mutexName();
                }
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    protected function universeMaintenanceMutexHeld(): bool
    {
        $name = $this->universeMaintenanceMutexName();
        if ($name === null) {
            return false;
        }

        try {
            /** @var Schedule $schedule */
            $schedule = app(Schedule::class);
            foreach ($schedule->events() as $event) {
                if (str_contains((string) $event->command, 'portfolio:run-universe-maintenance')) {
                    return $event->mutex->exists($event);
                }
            }
        } catch (\Throwable) {
            return Cache::has($name);
        }

        return false;
    }
}
