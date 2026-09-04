<?php
/**
 * Run stock master sync from the browser (no SSH).
 *
 * Upload: public_html/portfolio/cpanel-stock-master-sync.php
 * Run:    ...?token=YOUR_TOKEN
 * Optional: &backfill=1  (price backfill for newly added symbols — slow)
 *
 * Requires migration 2026_07_14_000001 (series column) for BE/BZ NSE imports.
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
    __DIR__.'/laravel',
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

echo "=== Stock master sync ===\n\n";

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

$backfillNewSymbols = (($_GET['backfill'] ?? '') === '1');
echo 'Backfill new symbols: '.($backfillNewSymbols ? 'yes' : 'no (add &backfill=1 for price backfill)')."\n\n";

try {
    /** @var App\Services\StockMasterSyncService $master */
    $master = $app->make(App\Services\StockMasterSyncService::class);
    $stats = $master->syncStockMaster($backfillNewSymbols);

    foreach ($stats as $key => $value) {
        if (is_array($value)) {
            continue;
        }
        echo $key.': '.$value."\n";
    }

    echo "\nDone. Run cpanel-repair-dual-listed-nse.php?token=...&probe=1 to verify pairs.\n";
    echo "DELETE cpanel-stock-master-sync.php from public_html/portfolio/ when finished.\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "\nFAILED: ".$e->getMessage()."\n\n";
    echo $e->getTraceAsString()."\n";
    exit(1);
}
