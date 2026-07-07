<?php
/**
 * Read-only Laravel scheduler diagnostic (no SSH). DELETE after use.
 *
 * Upload to: public_html/portfolio/cpanel-schedule-diagnostic.php
 * Visit:     https://lidoalexion.com/portfolio/cpanel-schedule-diagnostic.php?token=YOUR_TOKEN
 */
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

const SETUP_TOKEN = 'Lido';

if (($_GET['token'] ?? '') !== SETUP_TOKEN) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Forbidden. Set SETUP_TOKEN in this file, then visit ?token=YOUR_TOKEN\n");
}

header('Content-Type: text/plain; charset=utf-8');

$laravelRootCandidates = [
    dirname(__DIR__).'/lidoportfolio',
    dirname(__DIR__, 2).'/public_html/lidoportfolio',
];

$laravelRoot = null;
foreach ($laravelRootCandidates as $candidate) {
    if (is_file($candidate.'/vendor/autoload.php')) {
        $laravelRoot = $candidate;
        break;
    }
}

if ($laravelRoot === null) {
    http_response_code(500);
    echo "Could not find Laravel (vendor/autoload.php).\n";
    exit(1);
}

echo "=== Lido Portfolio — scheduler diagnostic ===\n\n";
echo "Laravel root: {$laravelRoot}\n";
echo 'PHP: '.PHP_VERSION."\n";
echo 'Script time (server default tz): '.date('Y-m-d H:i:s T')."\n\n";

require $laravelRoot.'/vendor/autoload.php';

/** @var Illuminate\Foundation\Application $app */
$app = require_once $laravelRoot.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\SyncRun;
use App\Services\SettingsService;
use App\Services\SyncLogService;
use App\Services\UniversePriceSyncService;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

function section(string $title): void
{
    echo "\n--- {$title} ---\n";
}

function line(string $label, $value): void
{
    $text = is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value;
    echo "  {$label}: {$text}\n";
}

function formatCarbon(?Carbon $at, string $timezone): string
{
    if ($at === null) {
        return '—';
    }

    return $at->copy()->timezone($timezone)->format('Y-m-d H:i:s T');
}

try {
    $settings = app(SettingsService::class);
    $cronTime = $settings->get('cron_time', env('PORTFOLIO_CRON_TIME', '18:30')) ?? '18:30';
    $cronTimezone = $settings->get('cron_timezone', env('PORTFOLIO_CRON_TIMEZONE', 'Asia/Kolkata')) ?? 'Asia/Kolkata';
    $nowCron = now()->timezone($cronTimezone);

    section('Clocks');
    line('app.timezone', (string) config('app.timezone'));
    line('php.ini date.timezone', ini_get('date.timezone') ?: '(not set)');
    line('cron_timezone (portfolio_settings)', $cronTimezone);
    line('now in cron_timezone', $nowCron->format('Y-m-d H:i:s T'));
    line('now UTC', now()->utc()->format('Y-m-d H:i:s T'));

    section('Admin cron settings (portfolio_settings)');
    line('cron_time', $cronTime.' (daily holdings + NIFTY50 benchmark)');
    line('cron_timezone', $cronTimezone.' (all scheduled jobs)');

    $universeEnabled = (bool) config('portfolio.universe_price_sync.enabled');
    section('Universe price sync');
    line('UNIVERSE_PRICE_SYNC_ENABLED', $universeEnabled);
    line('scope', (string) config('portfolio.universe_price_sync.scope'));
    line('batch_size', (int) config('portfolio.universe_price_sync.batch_size'));
    line('scheduled window', 'every 15 min, 19:00–23:45 in cron_timezone (hardcoded in routes/console.php)');
    line('NOT tied to cron_time', true);

    $inMaintenanceWindow = $nowCron->format('H:i') >= '19:00' && $nowCron->format('H:i') <= '23:45';
    line('in universe maintenance window now', $inMaintenanceWindow);

    if ($cronTimezone !== 'Asia/Kolkata') {
        $windowStartIst = Carbon::today($cronTimezone)->setTime(19, 0)->timezone('Asia/Kolkata');
        $windowEndIst = Carbon::today($cronTimezone)->setTime(23, 45)->timezone('Asia/Kolkata');
        line('19:00–23:45 in cron_tz as IST', $windowStartIst->format('H:i').' – '.$windowEndIst->format('H:i').' IST (same calendar day start)');
    }

    if ($universeEnabled) {
        try {
            $status = app(UniversePriceSyncService::class)->status();
            $lastRun = $status['last_run'] ?? null;
            if (is_array($lastRun)) {
                line('last_run.completed_at (settings JSON)', $lastRun['completed_at'] ?? '—');
                line('last_run.processed', (int) ($lastRun['processed'] ?? 0));
                line('last_run.failed', (int) ($lastRun['failed'] ?? 0));
            }
        } catch (Throwable $e) {
            line('universe status', 'FAILED: '.$e->getMessage());
        }
    }

    section('Registered schedule (schedule:list)');
    Artisan::call('schedule:list');
    echo Artisan::output();

    section('Due events if schedule:run ran right now');
    /** @var Schedule $schedule */
    $schedule = app(Schedule::class);
    $dueEvents = [];
    foreach ($schedule->events() as $event) {
        if ($event->isDue($app)) {
            $dueEvents[] = $event;
        }
    }
    if ($dueEvents === []) {
        echo "  (none due at {$nowCron->format('Y-m-d H:i:s T')})\n";
    } else {
        foreach ($dueEvents as $event) {
            echo '  DUE: '.trim($event->getSummaryForDisplay())."\n";
        }
    }

    section('Next run — key jobs');
    /** @var Event $event */
    foreach ($schedule->events() as $event) {
        $summary = $event->getSummaryForDisplay();
        $interesting = false;
        foreach (['universe-maintenance', 'daily-market-data', 'benchmark-price-sync', 'operational-alerts', 'stock-master-sync'] as $needle) {
            if (stripos($summary, $needle) !== false || stripos((string) $event->command, $needle) !== false) {
                $interesting = true;
                break;
            }
        }
        if (! $interesting) {
            foreach (['portfolio:run-universe-maintenance', 'portfolio:daily-sync', 'portfolio:check-operational-alerts', 'stocks:sync'] as $cmd) {
                if (stripos((string) $event->command, $cmd) !== false) {
                    $interesting = true;
                    break;
                }
            }
        }
        if (! $interesting) {
            continue;
        }

        try {
            $next = $event->nextRunDate($cronTimezone);
            echo '  '.trim($summary)."\n";
            echo '    next run: '.$next->timezone($cronTimezone)->format('Y-m-d H:i:s T')."\n";
        } catch (Throwable $e) {
            echo '  '.trim($summary)."\n";
            echo '    next run: (could not compute — '.$e->getMessage().")\n";
        }
    }

    if (Schema::hasTable('portfolio_sync_runs')) {
        section('Recent sync runs (portfolio_sync_runs)');

        $printRuns = static function (string $label, $query) use ($cronTimezone): void {
            echo "  {$label}:\n";
            $runs = $query->limit(8)->get();
            if ($runs->isEmpty()) {
                echo "    (none)\n";

                return;
            }
            foreach ($runs as $run) {
                /** @var SyncRun $run */
                $started = formatCarbon($run->started_at, $cronTimezone);
                $finished = formatCarbon($run->finished_at, $cronTimezone);
                echo "    {$started}  status={$run->status}  job={$run->job_name}";
                if ($run->finished_at) {
                    echo "  finished={$finished}";
                }
                if ($run->summary) {
                    echo '  summary='.substr((string) $run->summary, 0, 80);
                }
                echo "\n";
            }
        };

        $printRuns('Latest any job', SyncRun::query()->orderByDesc('started_at'));
        $printRuns('Latest universe-price-sync', SyncRun::query()
            ->where('job_name', SyncLogService::JOB_UNIVERSE_PRICE_SYNC)
            ->orderByDesc('started_at'));
        $printRuns('Latest daily-market-data', SyncRun::query()
            ->where('job_name', SyncLogService::JOB_DAILY_MARKET_DATA)
            ->orderByDesc('started_at'));

        section('Universe runs — last 7 days by hour (cron_timezone)');
        $cutoff = now()->subDays(7);
        $universeRuns = SyncRun::query()
            ->where('job_name', SyncLogService::JOB_UNIVERSE_PRICE_SYNC)
            ->where('started_at', '>=', $cutoff)
            ->orderBy('started_at')
            ->get();

        if ($universeRuns->isEmpty()) {
            echo "  (no universe-price-sync runs in last 7 days)\n";
        } else {
            $buckets = [];
            foreach ($universeRuns as $run) {
                $hour = $run->started_at?->timezone($cronTimezone)->format('Y-m-d H:00');
                if ($hour === null) {
                    continue;
                }
                $buckets[$hour] = ($buckets[$hour] ?? 0) + 1;
            }
            ksort($buckets);
            foreach ($buckets as $hour => $count) {
                echo "  {$hour}: {$count} run(s)\n";
            }
            echo "\n  Tip: evening maintenance should show many runs between 19:00–23:45.\n";
            echo "  A single cluster around 05:00 often means cPanel cron runs once daily\n";
            echo "  and/or cron_timezone is UTC (19:00–23:45 UTC ≈ 00:30–05:15 IST).\n";
        }

        if ($universeEnabled && $inMaintenanceWindow) {
            section('Tonight maintenance window check');
            $windowStart = $nowCron->copy()->setTime(19, 0, 0);
            $runsTonight = SyncRun::query()
                ->where('job_name', SyncLogService::JOB_UNIVERSE_PRICE_SYNC)
                ->where('started_at', '>=', $windowStart->copy()->utc())
                ->orderByDesc('started_at')
                ->limit(5)
                ->get();
            line('window opened at', $windowStart->format('Y-m-d H:i:s T'));
            if ($runsTonight->isEmpty()) {
                echo "  *** No universe runs since 19:00 tonight — ops alert expected ***\n";
            } else {
                foreach ($runsTonight as $run) {
                    echo '  run: '.formatCarbon($run->started_at, $cronTimezone)." status={$run->status}\n";
                }
            }
        }
    } else {
        section('Sync runs');
        echo "  portfolio_sync_runs table missing — run cpanel-migrate.php\n";
    }

    section('Scheduler health hint');
    $latestActivity = app(SyncLogService::class)->latestActivityAt();
    line('latest sync activity (any job)', formatCarbon($latestActivity, $cronTimezone));
    $deadHours = (int) config('portfolio.operational_alerts.scheduler_dead_hours', 48);
    if ($latestActivity === null) {
        echo "  No sync runs logged — scheduler may never have run, or sync log retention is 0.\n";
    } elseif ($latestActivity->lt(now()->subHours($deadHours))) {
        echo "  *** No sync activity for >{$deadHours}h — scheduler_inactive alert likely ***\n";
    }

    section('Recommended cPanel cron');
    echo "  Laravel needs schedule:run EVERY MINUTE (not once daily):\n\n";
    echo "  * * * * * cd {$laravelRoot} && php artisan schedule:run >> /dev/null 2>&1\n\n";
    echo "  Replace path if your lidoportfolio folder differs.\n";
    echo "  If cron runs only once at ~05:00, universe maintenance gets at most one batch\n";
    echo "  (and only if that moment falls inside 19:00–23:45 in cron_timezone).\n";

    echo "\n=== Done (read-only) ===\n";
    echo "DELETE cpanel-schedule-diagnostic.php from public_html/portfolio/ after reviewing.\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "\nFAILED: ".$e->getMessage()."\n\n";
    echo $e->getTraceAsString()."\n";
}
