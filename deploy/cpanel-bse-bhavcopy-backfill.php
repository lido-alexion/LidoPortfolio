<?php
/**
 * Backfill BSE-only stock OHLCV from BSE UDiFF bhavcopy (no SSH).
 *
 * Upload to: public_html/portfolio/cpanel-bse-bhavcopy-backfill.php
 * Dry run:   https://www.lidoalexion.com/portfolio/cpanel-bse-bhavcopy-backfill.php?token=YOUR_TOKEN
 * Apply:     ...?token=YOUR_TOKEN&apply=1
 * Optional: &from=2025-01-01&to=2026-07-09&days=5&sync_scrip=1
 * Default days=5 when omitted (cPanel 128MB limit; increase &days= per run).
 *
 * Requires migration 2026_07_13_000001 (bse_scrip_code column).
 * DELETE this file from the server after success.
 */
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);
set_time_limit(0);
@ini_set('memory_limit', '256M');

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

echo "=== BSE bhavcopy backfill (BSE-only stocks) ===\n\n";
echo 'Laravel root: '.$laravelRoot."\n";
echo 'PHP: '.PHP_VERSION."\n";

$apply = (($_GET['apply'] ?? '') === '1');
$dryRun = ! $apply;
echo 'Mode: '.($dryRun ? 'dry run (add &apply=1 to write)' : 'APPLY')."\n\n";

require $laravelRoot.'/vendor/autoload.php';

/** @var Illuminate\Foundation\Application $app */
$app = require_once $laravelRoot.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

if (! Illuminate\Support\Facades\Schema::hasColumn('portfolio_stocks', 'bse_scrip_code')) {
    http_response_code(500);
    echo "Column portfolio_stocks.bse_scrip_code is missing.\n";
    echo "Run cpanel-migrate.php first (migration 2026_07_13_000001).\n";
    exit(1);
}

$fromRaw = trim((string) ($_GET['from'] ?? ''));
$toRaw = trim((string) ($_GET['to'] ?? ''));
$daysRaw = trim((string) ($_GET['days'] ?? ''));
$syncScrip = (($_GET['sync_scrip'] ?? '') === '1');

$from = $fromRaw !== '' ? Carbon\Carbon::parse($fromRaw)->startOfDay() : null;
$to = $toRaw !== '' ? Carbon\Carbon::parse($toRaw)->startOfDay() : null;
$maxDays = $daysRaw !== '' && is_numeric($daysRaw) ? max(1, (int) $daysRaw) : 5;

echo 'Trading days per run: '.$maxDays." (use &days=N to change)\n\n";

try {
    /** @var App\Services\BseBhavcopyBackfillService $backfill */
    $backfill = $app->make(App\Services\BseBhavcopyBackfillService::class);

    if ($syncScrip) {
        echo "Syncing BSE scrip codes from master…\n";
        $scripStats = $backfill->syncScripCodesFromMaster();
        echo '  updated: '.$scripStats['updated']."\n";
        echo '  master missing symbol: '.$scripStats['missing_symbol']."\n";
        echo '  master missing scrip code: '.$scripStats['missing_code']."\n\n";
    }

    $stats = $backfill->backfill($from, $to, $maxDays, $dryRun);

    echo "BSE-only stocks: {$stats['stocks']}\n";
    echo "Range: {$stats['from_date']} → {$stats['to_date']}\n";
    echo "Trading days processed: {$stats['days_processed']}\n";
    echo "Trading days skipped: {$stats['days_skipped']}\n";
    echo "Rows matched: {$stats['rows_matched']}\n";
    echo "Rows stored: {$stats['rows_stored']}\n";

    if ($stats['errors'] !== []) {
        echo "\nSample errors:\n";
        foreach (array_slice($stats['errors'], 0, 10) as $error) {
            echo '  - '.$error."\n";
        }
    }

    if ($dryRun) {
        echo "\nDry run only. Re-run with &apply=1 to write OHLCV.\n";
    } else {
        echo "\nDone. DELETE cpanel-bse-bhavcopy-backfill.php from public_html/portfolio/ now.\n";
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo "\nFAILED: ".$e->getMessage()."\n\n";
    echo $e->getTraceAsString()."\n";
    exit(1);
}
