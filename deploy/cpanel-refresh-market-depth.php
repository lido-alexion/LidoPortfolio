<?php
/**
 * Build / refresh the Dashboard "Stocks Above" market-depth matrix (no SSH).
 *
 * Upload: public_html/portfolio/cpanel-refresh-market-depth.php
 * Visit:  https://YOUR-DOMAIN/portfolio/cpanel-refresh-market-depth.php?token=YOUR_TOKEN
 *
 * Heavy: loads OHLCV for configured index constituents (incl. Nifty 500).
 * Raise PHP max_execution_time / memory in cPanel if it fatals.
 * DELETE this file after success (daily sync also refreshes the cache).
 */
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);
@set_time_limit(0);
@ini_set('memory_limit', '512M');

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

echo "=== Refresh market depth matrix ===\n\n";
echo 'Laravel root: '.$laravelRoot."\n";
echo 'PHP: '.PHP_VERSION."\n";
echo 'Memory limit: '.ini_get('memory_limit')."\n\n";

require $laravelRoot.'/vendor/autoload.php';

/** @var Illuminate\Foundation\Application $app */
$app = require_once $laravelRoot.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

try {
    $started = microtime(true);
    $result = app(\App\Services\Analytics\MarketDepthBackfillService::class)->backfill(7);
    $elapsed = round(microtime(true) - $started, 2);

    echo "OK in {$elapsed}s\n";
    echo 'saved: '.$result['saved']."\n";
    echo 'dates: '.implode(', ', $result['dates'])."\n";
    if ($result['failed'] !== []) {
        echo 'failed: '.implode(', ', $result['failed'])."\n";
    }

    echo "\nMarket Depth page will show snapshots on next refresh.\n";
    echo "DELETE cpanel-refresh-market-depth.php after success.\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo 'ERROR: '.$e->getMessage()."\n";
    echo $e->getFile().':'.$e->getLine()."\n";
    exit(1);
}
