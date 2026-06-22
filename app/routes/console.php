<?php

use App\Jobs\DailyMarketDataJob;
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

Artisan::command('portfolio:send-notifications', function () {
    $result = app(\App\Services\AlertNotificationService::class)->sendScheduledNotifications();

    if (($result['skipped'] ?? false) && ($result['alert_count'] ?? 0) === 0) {
        $this->info('No alerts to send.');

        return 0;
    }

    if ($result['sent'] ?? false) {
        $this->info('Sent '.$result['alert_count'].' alert(s) to Telegram.');

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

Schedule::command('portfolio:daily-sync')
    ->dailyAt($cronTime)
    ->timezone($timezone)
    ->name('daily-market-data');

$notificationSchedules = [];
try {
    if (\Illuminate\Support\Facades\Schema::hasTable('portfolio_settings')) {
        $notificationSchedules = app(NotificationScheduleService::class)->schedules();
    }
} catch (\Throwable) {
    // Fall back to no notification schedules if DB is unavailable during bootstrap.
}

foreach ($notificationSchedules as $notificationTime) {
    Schedule::command('portfolio:send-notifications')
        ->dailyAt($notificationTime)
        ->timezone($timezone)
        ->name('alert-notifications-'.str_replace(':', '', $notificationTime));
}

Schedule::command('stocks:sync')
    ->weeklyOn(0, '02:00')
    ->timezone($timezone)
    ->name('stock-master-sync');

Schedule::command('portfolio:expire-alerts')
    ->hourly()
    ->timezone($timezone)
    ->name('alert-max-age-cleanup');

Schedule::call(function () {
    if (\Illuminate\Support\Facades\Schema::hasTable('portfolio_sync_runs')) {
        app(\App\Services\SyncLogService::class)->prune();
    }
})->hourly()->timezone($timezone)->name('sync-log-prune');
