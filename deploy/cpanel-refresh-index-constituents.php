<?php
/**
 * Refresh NSE index constituent caches (CSV archives + equity-stockIndices fallback).
 *
 * Prefer the weekly scheduler (`stocks:sync` then `portfolio:refresh-index-constituents`)
 * for production. This script is for one-off / post-deploy refresh.
 *
 * Upload: public_html/portfolio/cpanel-refresh-index-constituents.php
 *
 * Dry-run status: ...?token=YOUR_TOKEN
 * Apply:          ...?token=YOUR_TOKEN&apply=1
 * One index:      ...?token=YOUR_TOKEN&apply=1&symbol=NIFTY50
 *
 * DELETE this file after success.
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

echo "=== Index constituent refresh ===\n\n";

require $laravelRoot.'/vendor/autoload.php';

/** @var Illuminate\Foundation\Application $app */
$app = require_once $laravelRoot.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

/** @var App\Services\IndexConstituentService $service */
$service = $app->make(App\Services\IndexConstituentService::class);
/** @var App\Services\IndexCatalogService $catalog */
$catalog = $app->make(App\Services\IndexCatalogService::class);

$apply = (($_GET['apply'] ?? '') === '1');
$symbol = strtoupper(trim((string) ($_GET['symbol'] ?? '')));

$targets = [];
foreach ($catalog->enabledDefinitions() as $def) {
    if (! $catalog->supportsConstituents($def)) {
        continue;
    }
    if ($symbol !== '' && $def['symbol'] !== $symbol) {
        continue;
    }
    $targets[] = $def['symbol'];
}

echo 'Targets: '.(count($targets) === 0 ? '(none)' : implode(', ', $targets))."\n";
echo 'Mode: '.($apply ? 'APPLY' : 'dry-run')."\n\n";

if (! $apply) {
    echo "Dry-run only. Revisit with &apply=1 to fetch and cache constituents.\n";
    echo "DELETE this file after success.\n";
    exit(0);
}

if ($targets === []) {
    echo "No matching NSE indexes with constituents support.\n";
    exit(0);
}

$ok = 0;
$fail = 0;
foreach ($targets as $target) {
    $rows = $service->constituentsForSymbol($target, forceRefresh: true);
    $count = count($rows);
    if ($count > 0) {
        $ok++;
        echo "OK  {$target}: {$count} symbols\n";
    } else {
        $fail++;
        echo "FAIL {$target}: no symbols returned\n";
    }
}

echo "\nDone. refreshed={$ok} failed={$fail}\n";
echo "DELETE this file after success.\n";
