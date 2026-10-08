<?php

use App\Jobs\DailyMarketDataJob;
use App\Models\ExportArtifact;
use App\Models\ApiFailureIncident;
use App\Services\AlertExpirationService;
use App\Services\AlertNotificationService;
use App\Services\BenchmarkPriceSyncService;
use App\Services\Broker\KiteReadinessReminderService;
use App\Services\Fundamentals\FundamentalBootstrapService;
use App\Services\Fundamentals\FundamentalUpdateService;
use App\Services\HistoryDepthBackfillService;
use App\Services\ML\MlLifecycleAutomationService;
use App\Services\Notification\NotificationReminderService;
use App\Services\NotificationScheduleService;
use App\Services\NseHolidaySyncService;
use App\Services\PortfolioLoggerService;
use App\Services\SettingsService;
use App\Services\SyncLogService;
use App\Services\UniversePriceSyncService;
use App\Services\VpsHealth\VpsHealthNotificationService;
use App\Services\VpsHealth\VpsHealthSampleService;
use App\Support\TradingCalendar;
use App\Support\TradingOsConfig;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('vps-health:notify', function () {
    $payload = json_decode(stream_get_contents(STDIN), true);
    if (! is_array($payload) || ! is_string($payload['title'] ?? null) || ! is_string($payload['message'] ?? null)) {
        $this->error('Expected JSON on stdin with string title and message fields.');
        return 2;
    }

    $result = app(VpsHealthNotificationService::class)->send(
        mb_substr($payload['title'], 0, 180),
        mb_substr($payload['message'], 0, 8000),
        (bool) ($payload['urgent'] ?? false),
    );
    $this->line(json_encode($result, JSON_THROW_ON_ERROR));
    return $result['success'] ? 0 : 1;
})->purpose('Send health alerts to configured StoX admins through Laravel mail and Telegram');

Artisan::command('vps-health:record', function () {
    $payload = json_decode(stream_get_contents(STDIN), true);
    if (! is_array($payload)) {
        $this->error('Expected a health sample JSON object on stdin.');
        return 2;
    }

    $sample = app(VpsHealthSampleService::class)->record($payload);
    $this->line(json_encode(['recorded' => true, 'sampled_at' => $sample->sampled_at->toIso8601String()], JSON_THROW_ON_ERROR));
    return 0;
})->purpose('Record an aggregate VPS health sample in StoX for the admin dashboard');

Artisan::command('portfolio:daily-sync', function () {
    @set_time_limit(0);
    DailyMarketDataJob::dispatchSync();
    $this->info('Daily portfolio sync completed.');
})->purpose('Run daily market data sync manually');

Artisan::command('portfolio:purge-export-artifacts', function () {
    $removed = 0;
    ExportArtifact::query()->where('expires_at', '<', now())->chunkById(100, function ($artifacts) use (&$removed) {
        foreach ($artifacts as $artifact) {
            if ($artifact->path && \Storage::disk('local')->exists($artifact->path)) \Storage::disk('local')->delete($artifact->path);
            $artifact->delete(); $removed++;
        }
    });
    $this->info("Purged {$removed} expired export artifact(s).");
})->purpose('Delete expired V9 export artifacts');

Artisan::command('portfolio:purge-api-failure-incidents', function () {
    $cutoff = now()->subDays((int) config('api_failure_reporting.retention_days', 90));
    $removed = ApiFailureIncident::query()->where('last_seen_at', '<', $cutoff)->delete();
    $this->info("Purged {$removed} expired API failure incident(s).");
})->purpose('Delete expired API failure observer state');

Artisan::command('portfolio:sync-nse-holidays', function () {
    try {
        $result = app(NseHolidaySyncService::class)->sync();
        $this->info("NSE holidays: {$result['created']} created, {$result['updated']} refreshed, {$result['overridden']} admin overrides preserved.");

        return 0;
    } catch (Throwable $error) {
        $this->error($error->getMessage());

        return 1;
    }
})->purpose('Refresh official NSE capital-market trading holidays');

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

    $result = app(AlertNotificationService::class)->sendScheduledNotificationsAt($at);

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
    $count = app(AlertExpirationService::class)->expireOlderThanHours(100);
    $this->info("Expired {$count} alert(s) older than 100 hours.");

    return 0;
})->purpose('Expire portfolio alerts older than 100 hours');

Artisan::command('portfolio:send-kite-readiness-reminders', function () {
    $result = app(KiteReadinessReminderService::class)->sendDue();
    $this->info("Kite readiness reminders: {$result['sent']} sent; {$result['checked']} due profiles checked.");

    return 0;
})->purpose('Remind Automatic portfolios to reconnect an unusable Kite session');

Artisan::command('portfolio:ml-lifecycle-tick', function () {
    $actions = app(MlLifecycleAutomationService::class)->tick();
    if ($actions === []) {
        $this->info('ML lifecycle tick: no scheduled retrains queued.');

        return 0;
    }
    foreach ($actions as $row) {
        $this->line(sprintf('%s: %s%s', $row['horizon'], $row['action'], isset($row['reason']) ? ' ('.$row['reason'].')' : ''));
    }

    return 0;
})->purpose('FEAT-056: evaluate ML retrain schedules and queue background retrains');

Artisan::command('portfolio:queue-notification-reminders', function () {
    $result = app(NotificationReminderService::class)->queueDue();
    $this->info("Notification reminders: {$result['queued']} queued; {$result['checked']} due notifications checked.");

    return 0;
})->purpose('Queue 48-hour reminders for unresolved notification conditions');

Artisan::command('stox:fundamentals-update
    {--run= : Existing V7 fundamentals run id to process}
    {--stock= : Optional stock id for a new targeted run}
    {--batch=25 : Jobs to process in this slice}', function () {
    @set_time_limit(0);
    $service = app(FundamentalUpdateService::class);
    $runId = $this->option('run');
    $batch = max(1, min((int) $this->option('batch'), 200));

    if (! $runId && ! $this->option('stock')) {
        $result = $service->processScheduledIncremental($batch);
    } else {
        $run = $runId
            ? \App\Models\V7\FundamentalUpdateRun::query()->findOrFail((int) $runId)
            : $service->createRun(
                trigger: 'manual',
                scope: 'stock',
                stockId: (int) $this->option('stock'),
                limit: $batch,
            );
        $result = $service->process($run, $batch);
    }

    $this->info(sprintf(
        'V7 fundamentals: %s; processed=%d succeeded=%d failed=%d skipped=%d.',
        (string) $result['status'],
        (int) $result['processed'],
        (int) $result['succeeded'],
        (int) $result['failed'],
        (int) $result['skipped'],
    ));

    return in_array($result['status'], ['completed', 'completed_with_errors', 'running', 'queued', 'skipped'], true) ? 0 : 1;
})->purpose('Process a bounded V7 StoX fundamental-data update slice');

Artisan::command('stox:fundamentals-bootstrap
    {--dry-run : Preview universe without creating a run}
    {--all : Bootstrap all active equities}
    {--stock= : Single stock symbol (e.g. TCS)}
    {--stocks= : Comma-separated stock symbols}
    {--status= : Rerun stocks from latest run with status failed or complete_partial}
    {--run= : Existing bootstrap run id to process}
    {--batch=10 : Stock jobs to process in this slice}', function () {
    @set_time_limit(0);
    $service = app(FundamentalBootstrapService::class);
    $batch = max(1, min((int) $this->option('batch'), 100));

    if ($this->option('run')) {
        $run = \App\Models\V7\FundamentalBootstrapRun::query()->findOrFail((int) $this->option('run'));
        $result = $service->process($run, $batch);
        $this->info(sprintf(
            'Bootstrap run #%d: %s; queued=%d running=%d completed=%d failed=%d.',
            $run->id,
            (string) ($result['status'] ?? 'unknown'),
            (int) ($result['queued'] ?? 0),
            (int) ($result['running'] ?? 0),
            (int) ($result['completed'] ?? 0),
            (int) ($result['failed'] ?? 0),
        ));

        return 0;
    }

    $scope = 'all';
    $stockIds = null;

    if ($status = $this->option('status')) {
        $scope = match ((string) $status) {
            'failed' => 'rerun_failed',
            'complete_partial' => 'rerun_partial',
            default => throw new \InvalidArgumentException('Unsupported --status value; use failed or complete_partial.'),
        };
    } elseif ($this->option('stock')) {
        $scope = 'stock';
        $stockIds = $service->stockIdsFromSymbols([(string) $this->option('stock')]);
    } elseif ($this->option('stocks')) {
        $scope = 'stocks';
        $stockIds = $service->stockIdsFromSymbols(explode(',', (string) $this->option('stocks')));
    } elseif (! $this->option('all') && ! $this->option('dry-run')) {
        $this->error('Specify --all, --stock, --stocks, --status, --dry-run, or --run.');

        return 1;
    }

    $preview = $service->createRun(
        scope: $scope,
        stockIds: $stockIds,
        dryRun: (bool) $this->option('dry-run'),
    );

    if (is_array($preview)) {
        $this->info(sprintf(
            'Dry run: scope=%s stocks=%d sample=%s',
            (string) $preview['scope'],
            (int) $preview['stock_count'],
            implode(',', (array) $preview['symbols']),
        ));

        return 0;
    }

    $run = $preview;
    $result = $service->process($run, $batch);
    $this->info(sprintf(
        'Bootstrap run #%d created (scope=%s). Status=%s queued=%d completed=%d failed=%d.',
        $run->id,
        $scope,
        (string) ($result['status'] ?? 'unknown'),
        (int) ($result['queued'] ?? 0),
        (int) ($result['completed'] ?? 0),
        (int) ($result['failed'] ?? 0),
    ));

    return 0;
})->purpose('V8 historical fundamentals bootstrap (manual operator action only)');

$cronTime = env('PORTFOLIO_CRON_TIME', '18:30');
$timezone = env('PORTFOLIO_CRON_TIMEZONE', 'Asia/Kolkata');

try {
    if (Schema::hasTable('portfolio_settings')) {
        $settings = app(SettingsService::class);
        $cronTime = $settings->get('cron_time', $cronTime) ?? $cronTime;
        $timezone = $settings->get('cron_timezone', $timezone) ?? $timezone;
    }
} catch (Throwable) {
    // Fall back to env defaults if DB is unavailable during bootstrap.
}

if (! is_string($timezone) || trim($timezone) === '') {
    $timezone = 'Asia/Kolkata';
}

$marketDataSyncDue = function () use ($timezone): bool {
    try {
        return TradingCalendar::isScheduledMarketDataDay(timezone: $timezone);
    } catch (Throwable) {
        return false;
    }
};

Schedule::command('portfolio:daily-sync')
    ->dailyAt($cronTime)
    ->timezone($timezone)
    ->when($marketDataSyncDue)
    ->name('daily-market-data');

Schedule::command('portfolio:sync-benchmark-prices')
    ->dailyAt($cronTime)
    ->timezone($timezone)
    ->when($marketDataSyncDue)
    ->name('benchmark-price-sync');

if (config('portfolio.indexes.enabled', true)) {
    Schedule::command('portfolio:sync-index-prices', ['--mode' => 'daily'])
        ->dailyAt($cronTime)
        ->timezone($timezone)
        ->when($marketDataSyncDue)
        ->name('index-price-sync');
}

$notificationSchedules = [];
try {
    if (Schema::hasTable('portfolio_profile_settings')) {
        $notificationSchedules = app(NotificationScheduleService::class)->distinctSchedulesAcrossProfiles();
    }
} catch (Throwable) {
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

Schedule::command('portfolio:sync-nse-holidays')
    ->weeklyOn(0, '01:30')
    ->timezone($timezone)
    ->name('nse-trading-holiday-sync');

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
        return app(UniversePriceSyncService::class)->isMaintenanceWindowDue();
    } catch (Throwable $e) {
        try {
            app(PortfolioLoggerService::class)->scheduler(
                'error',
                'isMaintenanceWindowDue failed',
                ['error' => $e->getMessage()],
            );
        } catch (Throwable) {
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
        return app(HistoryDepthBackfillService::class)->isDue();
    } catch (Throwable) {
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

Schedule::command('stox:fundamentals-update --batch=20')
    ->hourly()
    ->timezone($timezone)
    ->withoutOverlapping(30)
    ->name('stox-fundamentals-incremental');

Schedule::command('stox:refresh-stock-classifications --batch=50')
    ->dailyAt('08:45')
    ->withoutOverlapping(30)
    ->onOneServer()
    ->name('stox-stock-classifications-refresh');

Schedule::command('stox:check-data-completeness')
    ->hourly()
    ->timezone($timezone)
    ->withoutOverlapping(10)
    ->name('stox-data-completeness');

// Independent of campaigns: the planner persists every completed-session
// obligation and delegates validated writes to the existing NSE owner engine.
Schedule::command('stox:forward-data')
    ->everyFifteenMinutes()
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping(30)
    ->name('stox-nse-universe-session-sync');

Schedule::command('portfolio:expire-alerts')
    ->hourly()
    ->timezone($timezone)
    ->name('alert-max-age-cleanup');

Schedule::command('portfolio:purge-export-artifacts')
    ->hourly()
    ->timezone($timezone)
    ->name('purge-export-artifacts');

Schedule::command('portfolio:purge-access-request-verifications')
    ->daily()
    ->timezone($timezone)
    ->name('purge-access-request-verifications');

Schedule::command('portfolio:send-notification-digests')
    ->everyMinute()
    ->timezone($timezone)
    ->name('notification-digest-dispatch');

Schedule::command('portfolio:send-kite-readiness-reminders')
    ->everyMinute()
    ->timezone($timezone)
    ->name('kite-readiness-reminders');

Schedule::command('portfolio:ml-lifecycle-tick')
    ->everyMinute()
    ->timezone(config('ml_lifecycle.timezone', 'Asia/Kolkata'))
    ->name('ml-lifecycle-scheduled-retrain');

Schedule::command('portfolio:sync-kite-instruments')
    ->dailyAt('08:00')
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping(10)
    ->name('kite-instrument-registry-refresh');

Schedule::command('portfolio:queue-notification-reminders')
    ->hourly()
    ->withoutOverlapping(10)
    ->name('notification-condition-reminders');

Schedule::command('portfolio:refresh-artifact-binding-usability')
    ->hourly()
    ->withoutOverlapping(10)
    ->name('artifact-binding-usability');

Schedule::command('portfolio:send-calendar-reminders')
    ->dailyAt('07:00')
    ->timezone($timezone)
    ->name('calendar-reminders');

if (TradingOsConfig::enabled() && TradingOsConfig::pipelineScheduleEnabled()) {
    $pipelineTime = TradingOsConfig::pipelineScheduleTime();
    Schedule::command('portfolio:decision-pipeline', [
        '--trigger' => 'scheduled',
    ])
        ->dailyAt($pipelineTime)
        ->timezone($timezone)
        ->when($marketDataSyncDue)
        ->withoutOverlapping(45)
        ->name('trading-os-decision-pipeline');
}

Schedule::command('portfolio:check-operational-alerts')
    ->hourly()
    ->timezone($timezone)
    ->name('operational-alerts');

Schedule::command('portfolio:sync-corporate-actions')
    ->dailyAt('20:15')
    ->timezone($timezone)
    ->name('data-quality-corporate-action-sync');

Schedule::command('portfolio:detect-corporate-action-anomalies')
    ->dailyAt('20:45')
    ->timezone($timezone)
    ->name('data-quality-corporate-action-detect');

Schedule::command('portfolio:auto-resolve-data-quality-issues')
    ->dailyAt('21:15')
    ->timezone($timezone)
    ->name('data-quality-auto-resolve');

Schedule::command('portfolio:process-recall-settlements')
    ->dailyAt('21:30')
    ->timezone($timezone)
    ->name('recall-settlements');

Schedule::call(function () {
    if (Schema::hasTable('portfolio_sync_runs')) {
        app(SyncLogService::class)->prune();
    }
})->hourly()->timezone($timezone)->name('sync-log-prune');

Schedule::command('tos:reconcile-broker-orders')
    ->everyFiveMinutes()
    ->timezone($timezone)
    ->withoutOverlapping(5)
    ->name('tos-broker-reconcile');

Schedule::command('portfolio:reconcile')
    ->everyFiveMinutes()
    ->timezone($timezone)
    ->withoutOverlapping(10)
    ->name('portfolio-reconciliation');

// FEAT-020 Paper work is scheduled before analytical simulation capacity and
// processes bounded durable checkpoints without requiring a resident worker.
Schedule::command('portfolio:process-paper-simulations')
    ->everyFiveMinutes()
    ->timezone($timezone)
    ->withoutOverlapping(5)
    ->name('paper-simulation-priority');

Schedule::command('portfolio:process-replays')
    ->everyFiveMinutes()
    ->timezone($timezone)
    ->withoutOverlapping(5)
    ->name('portfolio-replay-slices');

Schedule::command('portfolio:process-backtests --max-runs=1')
    ->everyFiveMinutes()
    ->timezone($timezone)
    ->withoutOverlapping(5)
    ->name('strategy-backtest-slices');

Schedule::command('tos:submit-automatic-orders')
    ->everyFiveMinutes()
    ->timezone($timezone)
    ->withoutOverlapping(5)
    ->name('tos-broker-automatic');

Schedule::command('tos:expire-execution-windows')
    ->everyMinute()
    ->timezone($timezone)
    ->withoutOverlapping(5)
    ->name('tos-recommendation-execution-expiry');

Schedule::command('tos:finalize-internal-transfer-valuations')
    ->everyFiveMinutes()
    ->timezone($timezone)
    ->withoutOverlapping(5)
    ->name('tos-internal-transfer-valuations');

Schedule::command('portfolio:purge-api-failure-incidents')
    ->dailyAt('03:20')
    ->timezone($timezone)
    ->withoutOverlapping(10)
    ->name('api-failure-incident-retention');

// Operational spend reconciliation, never an autonomous agent action.
Artisan::command('ai:reconcile-reservations', function () {
    app(\App\Services\AI\AiBudgetReservationService::class)->reconcile();
})->purpose('Conservatively settle expired AI provider reservations');
Schedule::command('ai:reconcile-reservations')->everyMinute()->withoutOverlapping();

Schedule::command('ops:maintain-log-triages')->everyTenMinutes()->withoutOverlapping()->name('log-triage-maintenance');
