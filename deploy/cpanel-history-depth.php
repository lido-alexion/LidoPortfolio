<?php
/**
 * History depth backfill — status, manual batch, or campaign reset (no SSH).
 *
 * The scheduler runs this campaign automatically all day via
 * `portfolio:backfill-history-depth` (every 5 minutes until complete).
 * Use this script only to check progress or to kick/reset it manually.
 *
 * Upload to: public_html/portfolio/cpanel-history-depth.php
 * Status:    https://lidoalexion.com/portfolio/cpanel-history-depth.php?token=YOUR_TOKEN
 * Run batch: ...?token=YOUR_TOKEN&run=1           (one batch; repeat as needed)
 * Small run: ...?token=YOUR_TOKEN&run=1&batch=5   (smaller batch for HTTP timeouts)
 * Reset:     ...?token=YOUR_TOKEN&reset=1         (restart the campaign from stock #1)
 *
 * This script can stay on the server while the campaign runs; DELETE it after
 * the campaign shows "completed".
 */
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);
set_time_limit(0);

register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error === null) {
        return;
    }
    if (! in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    if (! headers_sent()) {
        header('Content-Type: text/plain; charset=utf-8');
        http_response_code(500);
    }
    echo "\n\nPHP FATAL: {$error['message']}\n{$error['file']}:{$error['line']}\n";
});

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

echo "=== History depth backfill ===\n\n";
echo 'Laravel root: '.$laravelRoot."\n";
echo 'PHP: '.PHP_VERSION."\n\n";

require $laravelRoot.'/vendor/autoload.php';

/** @var Illuminate\Foundation\Application $app */
$app = require_once $laravelRoot.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

/** @var App\Services\HistoryDepthBackfillService $service */
$service = $app->make(App\Services\HistoryDepthBackfillService::class);

$printStatus = static function (App\Services\HistoryDepthBackfillService $service): void {
    $status = $service->status();
    $progress = $status['progress'] ?? [];

    echo "--- Campaign status ---\n";
    echo 'Enabled:               '.($status['enabled'] ? 'yes' : 'no')."\n";
    echo 'Target history days:   '.$status['target_history_days']."\n";
    echo 'Completed at:          '.($status['completed_at'] ?? '(not yet)')."\n";
    echo 'Completed target days: '.($status['completed_target_days'] ?: '-')."\n";
    echo 'Indexes done at:       '.($status['indexes_done_at'] ?? '(not yet)')."\n";
    echo 'Cursor stock id:       '.$status['cursor_stock_id']."\n";
    echo 'Universe progress:     '.$status['processed_through'].' / '.$status['universe_total']."\n";
    echo 'Total processed:       '.($progress['processed'] ?? 0)."\n";
    echo 'Total deepened:        '.($progress['deepened'] ?? 0)."\n";
    echo 'Total already deep:    '.($progress['already_deep'] ?? 0)."\n";
    echo 'Total failed:          '.($progress['failed'] ?? 0)."\n";
    echo 'Total rows stored:     '.($progress['stored_rows'] ?? 0)."\n";
    echo 'Run in progress:       '.($status['in_progress'] ? 'yes' : 'no')."\n";
    echo 'Due (scheduler gate):  '.($status['due'] ? 'yes' : 'no')."\n\n";
};

try {
    if (($_GET['reset'] ?? '') === '1') {
        $service->resetCampaign();
        echo "Campaign reset — the scheduler will start deepening again from the first stock.\n\n";
        $printStatus($service);
        exit;
    }

    if (($_GET['run'] ?? '') === '1') {
        $batchRaw = $_GET['batch'] ?? null;
        $batchSize = is_numeric($batchRaw) ? max(1, (int) $batchRaw) : null;

        echo '--- Running one batch (batch='.($batchSize ?? 'config default').") ---\n\n";
        $stats = $service->runBatch($batchSize);

        if (($stats['skipped'] ?? false) === true) {
            echo 'Skipped: '.($stats['reason'] ?? 'unknown')."\n\n";
        } else {
            echo 'Window:            '.$stats['required_from'].' -> '.$stats['required_to']."\n";
            echo 'Indexes processed: '.$stats['indexes_processed']."\n";
            echo 'Processed:         '.$stats['processed']."\n";
            echo 'Deepened:          '.$stats['deepened']."\n";
            echo 'Already deep:      '.$stats['already_deep']."\n";
            echo 'Failed:            '.$stats['failed']."\n";
            echo 'Rows stored:       '.$stats['stored_rows']."\n";
            echo 'Campaign complete: '.($stats['cycle_completed'] ? 'YES' : 'no')."\n";
            if (! empty($stats['errors'])) {
                echo "Sample errors:\n";
                foreach (array_slice($stats['errors'], 0, 10) as $error) {
                    echo '  - '.$error."\n";
                }
            }
            echo "\n";
        }

        $printStatus($service);
        exit;
    }

    $printStatus($service);
    echo "Add &run=1 to run one batch now, or &reset=1 to restart the campaign.\n";
    echo "The scheduler also runs batches automatically every 5 minutes all day.\n";
    echo "DELETE this file once the campaign shows completed.\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "\nFAILED: ".$e->getMessage()."\n\n";
    echo $e->getTraceAsString()."\n";
    exit(1);
}
