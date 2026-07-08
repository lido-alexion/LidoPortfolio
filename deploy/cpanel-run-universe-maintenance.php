<?php
/**
 * Force one universe maintenance batch from the browser (no SSH). DELETE after use.
 *
 * Upload to: public_html/portfolio/cpanel-run-universe-maintenance.php
 * Dry-run:   ?token=YOUR_TOKEN
 * Apply:     ?token=YOUR_TOKEN&apply=1
 * Options:   &skip_gap_fill=1  &reset_cursor=1  &batch=125  &clear_guards=1
 */
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);
@set_time_limit(0);

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

echo "=== Lido Portfolio — force universe maintenance ===\n\n";
echo "Laravel root: {$laravelRoot}\n";
echo 'PHP: '.PHP_VERSION."\n\n";

require $laravelRoot.'/vendor/autoload.php';

/** @var Illuminate\Foundation\Application $app */
$app = require_once $laravelRoot.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\SettingsService;
use App\Services\UniversePriceSyncService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

$apply = (($_GET['apply'] ?? '') === '1');
$skipGap = (($_GET['skip_gap_fill'] ?? '') === '1');
$resetCursor = (($_GET['reset_cursor'] ?? '') === '1');
$clearGuards = (($_GET['clear_guards'] ?? '') === '1');
$batch = is_numeric($_GET['batch'] ?? null) ? max(1, (int) $_GET['batch']) : null;

$settings = app(SettingsService::class);
$sync = app(UniversePriceSyncService::class);
$cronTimezone = $settings->get('cron_timezone', 'Asia/Kolkata') ?? 'Asia/Kolkata';
$now = now()->timezone($cronTimezone);

echo "Mode: ".($apply ? 'APPLY (will run maintenance)' : 'DRY-RUN (no sync)')."\n";
echo "Local time ({$cronTimezone}): ".$now->format('Y-m-d H:i:s T')."\n";
echo 'Universe enabled: '.($sync->isEnabled() ? 'yes' : 'no')."\n";
echo 'Window due now: '.($sync->isMaintenanceWindowDue() ? 'yes' : 'no')."\n";
echo 'In progress: '.($sync->isSyncInProgress() ? 'yes' : 'no')."\n";

/** @var Schedule $schedule */
$schedule = app(Schedule::class);
$mutexHeld = false;
foreach ($schedule->events() as $event) {
    if (! str_contains((string) $event->command, 'portfolio:run-universe-maintenance')) {
        continue;
    }
    try {
        $mutexHeld = $event->mutex->exists($event);
    } catch (Throwable) {
        $mutexHeld = Cache::has($event->mutexName());
    }
    echo 'Schedule mutex held: '.($mutexHeld ? 'yes' : 'no')."\n";
    break;
}

if (! $apply) {
    echo "\nAdd &apply=1 to run portfolio:run-universe-maintenance now (bypasses schedule window).\n";
    echo "Optional: &clear_guards=1 clears in-progress + mutex before run.\n";
    echo "DELETE this file after use.\n";
    exit(0);
}

if ($clearGuards) {
    echo "\nClearing guards...\n";
    $sync->clearInProgress();
    foreach ($schedule->events() as $event) {
        if (! str_contains((string) $event->command, 'portfolio:run-universe-maintenance')) {
            continue;
        }
        try {
            $event->mutex->forget($event);
            Cache::forget($event->mutexName());
            echo "  mutex forgotten\n";
        } catch (Throwable $e) {
            echo '  mutex forget failed: '.$e->getMessage()."\n";
        }
        break;
    }
}

$params = [];
if ($skipGap) {
    $params['--skip-gap-fill'] = true;
}
if ($resetCursor) {
    $params['--reset-cursor'] = true;
}
if ($batch !== null) {
    $params['--batch'] = $batch;
}

echo "\nRunning portfolio:run-universe-maintenance...\n\n";
$exit = Artisan::call('portfolio:run-universe-maintenance', $params);
echo Artisan::output();
echo "\nExit code: {$exit}\n";
echo "DELETE this file after use.\n";
exit($exit);
