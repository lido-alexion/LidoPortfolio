<?php
/**
 * Repair dual-listed stocks: purge BSE duplicate OHLCV, deactivate BSE rows, refill NSE history.
 *
 * Upload: public_html/portfolio/cpanel-repair-dual-listed-nse.php
 * Dry run:  ...?token=YOUR_TOKEN
 * Apply:    ...?token=YOUR_TOKEN&apply=1
 * Optional: &no_backfill=1  &max_backfill=25
 * NSE-only backfill (after repair metadata): &backfill_nse_only=1&max_backfill=25
 * Reset backfill cursor: &backfill_nse_only=1&reset_cursor=1
 *
 * Run after stock master sync + migration 2026_07_14_000001 (series column).
 * DELETE this file from the server after success.
 */
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);
set_time_limit(0);
@ini_set('memory_limit', '256M');

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

echo "=== Dual-listed NSE repair ===\n\n";

require $laravelRoot.'/vendor/autoload.php';

/** @var Illuminate\Foundation\Application $app */
$app = require_once $laravelRoot.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

if (! Illuminate\Support\Facades\Schema::hasColumn('portfolio_stocks', 'series')) {
    http_response_code(500);
    echo "Column portfolio_stocks.series is missing.\n";
    echo "Run cpanel-migrate.php first (migration 2026_07_14_000001).\n";
    exit(1);
}

$apply = (($_GET['apply'] ?? '') === '1');
$dryRun = ! $apply;
$backfill = (($_GET['no_backfill'] ?? '') !== '1');
$maxBackfillRaw = trim((string) ($_GET['max_backfill'] ?? ''));
$maxBackfill = is_numeric($maxBackfillRaw) ? max(0, (int) $maxBackfillRaw) : null;
$probe = (($_GET['probe'] ?? '') === '1');
$backfillNseOnly = (($_GET['backfill_nse_only'] ?? '') === '1');
$resetCursor = (($_GET['reset_cursor'] ?? '') === '1');
$sampleSymbol = trim((string) ($_GET['symbol'] ?? 'TOKYOPLAST'));

if ($probe) {
    /** @var App\Services\DualListedNseRepairService $repair */
    $repair = $app->make(App\Services\DualListedNseRepairService::class);
    $probeState = $repair->probeState($sampleSymbol !== '' ? $sampleSymbol : null);

    echo "=== Dual-listed repair probe ===\n\n";
    foreach ($probeState as $key => $value) {
        if ($key === 'sample_symbol_rows') {
            echo $key.":\n";
            if ($value === null || $value === []) {
                echo "  (none)\n";
            } else {
                foreach ($value as $row) {
                    echo '  - '.json_encode($row, JSON_UNESCAPED_UNICODE)."\n";
                }
            }
            continue;
        }
        echo $key.': '.$value."\n";
    }

    $lastMaster = Illuminate\Support\Facades\DB::table('portfolio_sync_logs')
        ->where('job_name', App\Services\SyncLogService::JOB_STOCK_MASTER)
        ->orderByDesc('id')
        ->first(['status', 'summary', 'created_at']);
    echo "\nlast_stock_master_sync: ";
    echo $lastMaster ? json_encode($lastMaster, JSON_UNESCAPED_UNICODE) : '(none)';

    echo "\n\nIf repair_pairs_found_dry_run is 0 and sample has only BSE row, run stock master sync first.\n";
    echo "Probe URL: add &symbol=TOKYOPLAST to inspect another symbol.\n";
    exit(0);
}

if ($backfillNseOnly) {
    if (! is_numeric($maxBackfillRaw)) {
        $maxBackfill = 25;
    } else {
        $maxBackfill = max(1, (int) $maxBackfillRaw);
    }

    echo "=== Dual-listed NSE backfill only ===\n\n";
    echo 'Max backfill symbols: '.$maxBackfill."\n";
    echo 'Reset cursor: '.($resetCursor ? 'yes' : 'no')."\n\n";

    /** @var App\Services\DualListedNseRepairService $repair */
    $repair = $app->make(App\Services\DualListedNseRepairService::class);
    $stats = $repair->backfillPairedNseHistory($maxBackfill, $resetCursor);

    foreach ($stats as $key => $value) {
        if ($key === 'errors') {
            continue;
        }
        echo $key.': '.$value."\n";
    }

    if (($stats['errors'] ?? []) !== []) {
        echo "\nErrors:\n";
        foreach ($stats['errors'] as $error) {
            echo '  - '.$error."\n";
        }
    }

    if (($stats['nse_backfill_remaining'] ?? 0) > 0) {
        echo "\nRe-run the same URL until nse_backfill_remaining is 0.\n";
    } else {
        echo "\nNSE backfill complete for all paired symbols.\n";
    }
    exit(0);
}

echo 'Mode: '.($dryRun ? 'dry run (add &apply=1 to write)' : 'APPLY')."\n";
echo 'NSE backfill: '.($backfill ? 'yes' : 'no')."\n";
if ($maxBackfill !== null) {
    echo 'Max backfill symbols: '.$maxBackfill."\n";
}
echo "\n";

try {
    /** @var App\Services\DualListedNseRepairService $repair */
    $repair = $app->make(App\Services\DualListedNseRepairService::class);
    $stats = $repair->repair($dryRun, $backfill, $maxBackfill);

    foreach ($stats as $key => $value) {
        if ($key === 'errors') {
            continue;
        }
        echo $key.': '.$value."\n";
    }

    if (($stats['errors'] ?? []) !== []) {
        echo "\nErrors:\n";
        foreach ($stats['errors'] as $error) {
            echo '  - '.$error."\n";
        }
    }

    if ($dryRun) {
        echo "\nDry run only. Re-run with &apply=1 to purge BSE data and refill NSE.\n";
    } else {
        echo "\nDone. DELETE cpanel-repair-dual-listed-nse.php from public_html/portfolio/ now.\n";
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo "\nFAILED: ".$e->getMessage()."\n\n";
    echo $e->getTraceAsString()."\n";
    exit(1);
}
