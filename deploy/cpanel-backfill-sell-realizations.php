<?php
/**
 * Backfill FIFO realized P/L and squared-off fees on sell transactions (no SSH).
 *
 * Upload to: public_html/portfolio/cpanel-backfill-sell-realizations.php
 * Visit:     https://lidoalexion.com/portfolio/cpanel-backfill-sell-realizations.php?token=YOUR_TOKEN
 * Optional: ?token=YOUR_TOKEN&profile=123  (limit to one portfolio profile id)
 *
 * Requires migration 2026_07_09_000001 (realized_pl, squared_off_fees columns).
 * DELETE this file from the server immediately after success.
 */
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

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

echo "=== Backfill sell realizations ===\n\n";
echo 'Laravel root: '.$laravelRoot."\n";
echo 'PHP: '.PHP_VERSION."\n\n";

require $laravelRoot.'/vendor/autoload.php';

/** @var Illuminate\Foundation\Application $app */
$app = require_once $laravelRoot.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$table = 'portfolio_transactions';

if (! Illuminate\Support\Facades\Schema::hasTable($table)) {
    http_response_code(500);
    echo "Table {$table} not found.\n";
    exit(1);
}

foreach (['realized_pl', 'squared_off_fees'] as $column) {
    if (! Illuminate\Support\Facades\Schema::hasColumn($table, $column)) {
        http_response_code(500);
        echo "Column {$table}.{$column} is missing.\n";
        echo "Run cpanel-migrate.php first (migration 2026_07_09_000001).\n";
        exit(1);
    }
}

echo "Columns realized_pl + squared_off_fees: OK\n\n";

$profileRaw = $_GET['profile'] ?? null;
$profileId = ($profileRaw !== null && $profileRaw !== '') ? (int) $profileRaw : null;

if ($profileId !== null) {
    echo "Profile filter: {$profileId}\n\n";
} else {
    echo "Profile filter: all profiles\n\n";
}

try {
    /** @var App\Services\TransactionRealizationService $realizations */
    $realizations = $app->make(App\Services\TransactionRealizationService::class);
    $processed = $realizations->backfillAll($profileId);

    $sellQuery = Illuminate\Support\Facades\DB::table($table)->where('type', 'sell');
    if ($profileId !== null) {
        $sellQuery->where('profile_id', $profileId);
    }

    $sellCount = (clone $sellQuery)->count();
    $filledCount = (clone $sellQuery)
        ->whereNotNull('realized_pl')
        ->count();

    echo "Backfilled sell realizations for {$processed} profile/stock ledger(s).\n";
    echo "Sell rows: {$sellCount}; with realized_pl set: {$filledCount}\n";
    echo "\nDone. DELETE cpanel-backfill-sell-realizations.php from public_html/portfolio/ now.\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "\nFAILED: ".$e->getMessage()."\n\n";
    echo $e->getTraceAsString()."\n";
    exit(1);
}
