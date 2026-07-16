<?php
/**
 * Backfill / sync market index OHLCV (Tier A+B catalog in config/portfolio.php indexes).
 *
 * Upload: public_html/portfolio/cpanel-backfill-index-prices.php
 *
 * Status:     ...?token=YOUR_TOKEN
 * Daily batch:...?token=YOUR_TOKEN&apply=1&mode=daily
 * Backfill:   ...?token=YOUR_TOKEN&apply=1&mode=backfill
 * One symbol: ...?token=YOUR_TOKEN&apply=1&mode=backfill&symbol=NIFTYBANK
 * All once:   ...?token=YOUR_TOKEN&apply=1&mode=backfill&all=1
 * Fill gaps:  ...?token=YOUR_TOKEN&apply=1&fill_gaps=1
 * Reset:      ...?token=YOUR_TOKEN&apply=1&reset_cursor=1&mode=backfill
 *
 * Default is dry-run (status only). DELETE this file after success.
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

echo "=== Index price sync / backfill ===\n\n";

require $laravelRoot.'/vendor/autoload.php';

/** @var Illuminate\Foundation\Application $app */
$app = require_once $laravelRoot.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

/** @var App\Services\IndexPriceSyncService $sync */
$sync = $app->make(App\Services\IndexPriceSyncService::class);
/** @var App\Services\IndexCatalogService $catalog */
$catalog = $app->make(App\Services\IndexCatalogService::class);

$apply = (($_GET['apply'] ?? '') === '1');
$mode = strtolower(trim((string) ($_GET['mode'] ?? 'backfill')));
if (! in_array($mode, ['daily', 'backfill'], true)) {
    $mode = 'backfill';
}
$symbol = strtoupper(trim((string) ($_GET['symbol'] ?? '')));
$processAll = (($_GET['all'] ?? '') === '1');
$fillGaps = (($_GET['fill_gaps'] ?? '') === '1');
$resetCursor = (($_GET['reset_cursor'] ?? '') === '1');
$batchRaw = trim((string) ($_GET['batch'] ?? ''));
$batchSize = is_numeric($batchRaw) ? max(1, (int) $batchRaw) : null;

echo 'Enabled: '.($sync->isEnabled() ? 'yes' : 'no')."\n";
echo 'Primary: '.$catalog->primarySymbol()."\n";
echo 'Configured indexes: '.count($catalog->enabledDefinitions())."\n";
echo 'Mode: '.$mode."\n";
echo 'Apply writes: '.($apply ? 'YES' : 'no (dry-run / status only)')."\n\n";

$status = $sync->status();
echo "--- Status ---\n";
echo 'In progress: '.(($status['in_progress'] ?? false) ? 'yes' : 'no')."\n";
echo 'Cursor: '.($status['cursor_symbol'] ?? '—')."\n";
echo 'Progress: '.($status['processed_through'] ?? 0).'/'.($status['index_count'] ?? 0)
    .' ('.($status['progress_percent'] ?? 0)."%)\n";
echo 'Last cycle: '.($status['last_cycle_completed_at'] ?? '—')."\n\n";

$withGaps = 0;
foreach ($status['indexes'] ?? [] as $row) {
    $gapFlag = ! empty($row['has_gaps']) ? ' GAPS='.$row['gap_count'] : '';
    echo sprintf(
        "  %-18s %-4s rows=%-6d %s..%s%s\n",
        $row['symbol'] ?? '',
        $row['exchange'] ?? '',
        (int) ($row['row_count'] ?? 0),
        $row['price_from'] ?? '—',
        $row['price_to'] ?? '—',
        $gapFlag,
    );
    if (! empty($row['has_gaps'])) {
        $withGaps++;
    }
}
echo "\nIndexes with gaps: {$withGaps}\n";

if (! $apply) {
    echo "\nDry run only. Revisit with &apply=1 to sync.\n";
    echo "DELETE this file after success.\n";
    exit(0);
}

if (! $sync->isEnabled()) {
    echo "\nIndex sync disabled in config.\n";
    exit(1);
}

echo "\n--- Running ---\n";

if ($fillGaps) {
    $result = $sync->fillGapsBatch(batchSize: $batchSize, resetCursor: $resetCursor);
} elseif ($symbol !== '') {
    $result = $sync->syncOneSymbol($symbol, $mode);
} else {
    $result = $sync->syncBatch(
        mode: $mode,
        batchSize: $batchSize,
        resetCursor: $resetCursor,
        processAll: $processAll,
    );
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n\n";
echo "Done. DELETE this file after success.\n";
exit((($result['success'] ?? true) === false || (($result['failed'] ?? 0) > 0 && ($result['succeeded'] ?? 0) === 0)) ? 1 : 0);
