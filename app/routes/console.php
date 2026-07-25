<?php

use App\Jobs\DailyMarketDataJob;
use App\Services\BenchmarkPriceSyncService;
use App\Services\NotificationScheduleService;
use App\Services\SettingsService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('portfolio:daily-sync', function () {
    @set_time_limit(0);
    DailyMarketDataJob::dispatchSync();
    $this->info('Daily portfolio sync completed.');
})->purpose('Run daily market data sync manually');

Artisan::command('portfolio:sync-benchmark-prices', function () {
    @set_time_limit(0);
    $result = app(BenchmarkPriceSyncService::class)->syncIfNeeded(force: true);
    if ($result['skipped'] ?? false) {
        $this->info('NIFTY50 benchmark prices already synced today.');

        return 0;
    }
    if ($result['success'] ?? false) {
        $this->info(sprintf(
            'NIFTY50 benchmark sync OK (%s rows stored, %s history).',
            $result['stored_rows'] ?? 0,
            ($result['full_history'] ?? false) ? 'full' : 'incremental',
        ));

        return 0;
    }
    $this->error('NIFTY50 benchmark sync failed: '.implode('; ', $result['errors'] ?? ['unknown']));

    return 1;
})->purpose('Sync NIFTY50 index prices for relative strength / Explorer');

Artisan::command('portfolio:send-notifications {--at= : HH:mm schedule slot in cron timezone}', function () {
    $at = $this->option('at');
    if (! is_string($at) || $at === '') {
        $at = now()
            ->timezone(app(NotificationScheduleService::class)->timezone())
            ->format('H:i');
    }

    $result = app(\App\Services\AlertNotificationService::class)->sendScheduledNotificationsAt($at);

    if (($result['skipped'] ?? false) && ($result['alert_count'] ?? 0) === 0) {
        $this->info('No alerts to send.');

        return 0;
    }

    if ($result['sent'] ?? false) {
        $profiles = $result['profiles_notified'] ?? 0;
        $this->info('Sent '.$result['alert_count'].' alert(s) to Telegram for '.$profiles.' profile(s).');

        return 0;
    }

    $this->warn('Alerts were found but Telegram delivery failed or is disabled.');

    return 1;
})->purpose('Send portfolio alerts to Telegram (silent when none)');

Artisan::command('portfolio:expire-alerts', function () {
    $count = app(\App\Services\AlertExpirationService::class)->expireOlderThanHours(100);
    $this->info("Expired {$count} alert(s) older than 100 hours.");

    return 0;
})->purpose('Expire portfolio alerts older than 100 hours');

$cronTime = env('PORTFOLIO_CRON_TIME', '18:30');
$timezone = env('PORTFOLIO_CRON_TIMEZONE', 'Asia/Kolkata');

try {
    if (\Illuminate\Support\Facades\Schema::hasTable('portfolio_settings')) {
        $settings = app(SettingsService::class);
        $cronTime = $settings->get('cron_time', $cronTime) ?? $cronTime;
        $timezone = $settings->get('cron_timezone', $timezone) ?? $timezone;
    }
} catch (\Throwable) {
    // Fall back to env defaults if DB is unavailable during bootstrap.
}

if (! is_string($timezone) || trim($timezone) === '') {
    $timezone = 'Asia/Kolkata';
}

Schedule::command('portfolio:daily-sync')
    ->dailyAt($cronTime)
    ->timezone($timezone)
    ->name('daily-market-data');

Schedule::command('portfolio:sync-benchmark-prices')
    ->dailyAt($cronTime)
    ->timezone($timezone)
    ->name('benchmark-price-sync');

if (config('portfolio.indexes.enabled', true)) {
    Schedule::command('portfolio:sync-index-prices', ['--mode' => 'daily'])
        ->dailyAt($cronTime)
        ->timezone($timezone)
        ->name('index-price-sync');
}

$notificationSchedules = [];
try {
    if (\Illuminate\Support\Facades\Schema::hasTable('portfolio_profile_settings')) {
        $notificationSchedules = app(NotificationScheduleService::class)->distinctSchedulesAcrossProfiles();
    }
} catch (\Throwable) {
    // Fall back to no notification schedules if DB is unavailable during bootstrap.
}

foreach ($notificationSchedules as $notificationTime) {
    Schedule::command('portfolio:send-notifications', ['--at' => $notificationTime])
        ->dailyAt($notificationTime)
        ->timezone($timezone)
        ->name('alert-notifications-'.str_replace(':', '', $notificationTime));
}

Schedule::command('stocks:sync')
    ->weeklyOn(0, '02:00')
    ->timezone($timezone)
    ->name('stock-master-sync');

// Backup weekly pass if stock-master sync is skipped/fails before constituent refresh.
Schedule::command('portfolio:refresh-index-constituents')
    ->weeklyOn(0, '02:30')
    ->timezone($timezone)
    ->name('index-constituents-refresh');

// Heartbeat every minute so we can tell whether cPanel cron is invoking schedule:run.
Schedule::command('portfolio:universe-maintenance-probe --write-heartbeat')
    ->timezone($timezone)
    ->everyMinute()
    ->name('universe-schedule-heartbeat');

$universeMaintenanceDue = function (): bool {
    try {
        return app(\App\Services\UniversePriceSyncService::class)->isMaintenanceWindowDue();
    } catch (\Throwable $e) {
        try {
            app(\App\Services\PortfolioLoggerService::class)->scheduler(
                'error',
                'isMaintenanceWindowDue failed',
                ['error' => $e->getMessage()],
            );
        } catch (\Throwable) {
            // ignore nested logger failures
        }

        return false;
    }
};

if (config('portfolio.universe_price_sync.enabled')) {
    // Explain/probe once at the top of each maintenance slot (same due helper as the real job).
    Schedule::command('portfolio:universe-maintenance-probe --explain')
        ->timezone($timezone)
        ->everyMinute()
        ->when($universeMaintenanceDue)
        ->name('universe-maintenance-probe');

    Schedule::command('portfolio:run-universe-maintenance')
        ->timezone($timezone)
        ->everyMinute()
        ->when($universeMaintenanceDue)
        ->withoutOverlapping(25)
        ->name('universe-maintenance');
}

// History depth deepening campaign: runs ALL DAY (not just the maintenance
// window) every 5 minutes until one full universe pass completes, then the
// isDue() gate keeps it idle. Re-arms automatically if the target is raised.
$historyDepthDue = function (): bool {
    try {
        return app(\App\Services\HistoryDepthBackfillService::class)->isDue();
    } catch (\Throwable) {
        return false;
    }
};

Schedule::command('portfolio:backfill-history-depth')
    ->timezone($timezone)
    ->everyFiveMinutes()
    ->when($historyDepthDue)
    ->withoutOverlapping(30)
    ->name('history-depth-backfill');

Schedule::command('portfolio:run-due-screeners')
    ->everyMinute()
    ->timezone($timezone)
    ->name('run-due-screeners');

Schedule::command('portfolio:expire-alerts')
    ->hourly()
    ->timezone($timezone)
    ->name('alert-max-age-cleanup');

Schedule::command('portfolio:send-calendar-reminders')
    ->dailyAt('07:00')
    ->timezone($timezone)
    ->name('calendar-reminders');

if (config('trading_os.enabled', true) && config('trading_os.pipeline.schedule_enabled', false)) {
    $pipelineTime = config('trading_os.pipeline.schedule_time', '19:00');
    Schedule::command('portfolio:decision-pipeline')
        ->dailyAt(is_string($pipelineTime) ? $pipelineTime : '19:00')
        ->timezone($timezone)
        ->name('trading-os-decision-pipeline');
}

Schedule::command('portfolio:check-operational-alerts')
    ->hourly()
    ->timezone($timezone)
    ->name('operational-alerts');

Schedule::call(function () {
    if (\Illuminate\Support\Facades\Schema::hasTable('portfolio_sync_runs')) {
        app(\App\Services\SyncLogService::class)->prune();
    }
})->hourly()->timezone($timezone)->name('sync-log-prune');
