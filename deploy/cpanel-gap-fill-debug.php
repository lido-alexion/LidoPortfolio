<?php
/**
 * Gap fill failure + inventory debug (runs on server — no external API / Imunify360).
 *
 * Upload: public_html/portfolio/cpanel-gap-fill-debug.php
 * Visit:  https://www.lidoalexion.com/portfolio/cpanel-gap-fill-debug.php?token=Lido
 * Optional: &clear_lock=1  (clear stale price_history_gap_in_progress)
 * DELETE after use.
 */
declare(strict_types=1);

const SETUP_TOKEN = 'Lido';

if (($_GET['token'] ?? '') !== SETUP_TOKEN) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Forbidden.\n");
}

header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', '1');
error_reporting(E_ALL);

$root = dirname(__DIR__).'/lidoportfolio';
if (! is_file($root.'/vendor/autoload.php')) {
    http_response_code(500);
    exit("Laravel not found at {$root}\n");
}

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Setting;
use App\Models\Stock;
use App\Models\SyncRun;
use App\Services\PriceHistoryGapService;
use App\Services\StockPriceHistoryService;
use Illuminate\Support\Facades\DB;

$clearLock = (($_GET['clear_lock'] ?? '') === '1');

echo "=== Gap fill debug ===\n";
echo 'Generated: '.now()->toIso8601String()."\n\n";

if ($clearLock) {
    Setting::setValue(PriceHistoryGapService::KEY_IN_PROGRESS, '0');
    Setting::setValue(PriceHistoryGapService::KEY_IN_PROGRESS_AT, null);
    Setting::setValue(PriceHistoryGapService::KEY_IN_PROGRESS_MODE, null);
    Setting::setValue(PriceHistoryGapService::KEY_FILL_PROGRESS_JSON, null);
    echo "Cleared price_history_gap_in_progress lock and fill progress.\n\n";
}

$gaps = app(PriceHistoryGapService::class);
$history = app(StockPriceHistoryService::class);
$status = $gaps->status();

echo "--- Locks / progress ---\n";
echo 'in_progress: '.($status['in_progress'] ? 'yes' : 'no')."\n";
echo 'in_progress_mode: '.($status['in_progress_mode'] ?? '—')."\n";
echo 'in_progress_at: '.($status['in_progress_at'] ?? '—')."\n";
$fillProgress = $status['fill_progress'] ?? null;
if (is_array($fillProgress)) {
    echo 'fill_progress: processed '.$fillProgress['processed_total'].' / '.$fillProgress['total_gap_stocks']
        .' filled='.$fillProgress['filled'].' failed='.$fillProgress['failed']."\n";
}
echo "\n";

echo "--- Scan summary ---\n";
echo 'inventory_stock_count: '.($status['inventory_stock_count'] ?? 0)."\n";
echo 'last_scan with_gaps: '.($status['last_scan']['with_gaps'] ?? '?')."\n";
echo 'last_scan scanned: '.($status['last_scan']['scanned'] ?? '?')."\n";
echo 'required_through_session: '.($status['required_through_session'] ?? '?')."\n";
echo 'history_window_days: '.($status['history_window_days'] ?? '?')."\n\n";

$inventory = json_decode((string) Setting::getValue(PriceHistoryGapService::KEY_GAP_INVENTORY_JSON, ''), true);
$stockIds = is_array($inventory['stock_ids'] ?? null) ? $inventory['stock_ids'] : [];
echo 'Inventory stock_ids: '.count($stockIds)."\n";

$exchangeCounts = ['NSE' => 0, 'BSE' => 0, 'OTHER' => 0];
$rangeTypeCounts = ['prefix_only' => 0, 'internal_only' => 0, 'mixed' => 0, 'full_window' => 0];
$wideBseRanges = 0;
['from' => $reqFrom, 'to' => $reqTo] = $gaps->requiredWindow();

foreach (array_slice($stockIds, 0, 500) as $stockId) {
    $stock = Stock::query()->find((int) $stockId);
    if ($stock === null) {
        continue;
    }
    $ex = strtoupper((string) $stock->exchange);
    if (isset($exchangeCounts[$ex])) {
        $exchangeCounts[$ex]++;
    } else {
        $exchangeCounts['OTHER']++;
    }

    $ranges = $history->getMissingHistoryRanges($stock, $reqFrom, $reqTo);
    if ($ranges === []) {
        continue;
    }

    $available = $history->getAvailableHistoryRange($stock);
    $hasPrefix = false;
    $hasInternal = false;
    foreach ($ranges as $range) {
        $span = $range['from']->diffInDays($range['to']);
        if ($ex === 'BSE' && $span > 45) {
            $wideBseRanges++;
        }
        if ($available !== null && $range['from']->lt($available['from'])) {
            $hasPrefix = true;
        } elseif ($available !== null && $range['from']->gte($available['from']) && $range['to']->lte($available['to'])) {
            $hasInternal = true;
        } else {
            $hasPrefix = true;
        }
    }

    if (count($ranges) === 1 && $available === null) {
        $rangeTypeCounts['full_window']++;
    } elseif ($hasPrefix && $hasInternal) {
        $rangeTypeCounts['mixed']++;
    } elseif ($hasInternal) {
        $rangeTypeCounts['internal_only']++;
    } else {
        $rangeTypeCounts['prefix_only']++;
    }
}

echo "Exchange breakdown (inventory): NSE={$exchangeCounts['NSE']} BSE={$exchangeCounts['BSE']} OTHER={$exchangeCounts['OTHER']}\n";
echo "Range types (sampled): prefix_only={$rangeTypeCounts['prefix_only']} internal_only={$rangeTypeCounts['internal_only']} "
    ."mixed={$rangeTypeCounts['mixed']} full_window={$rangeTypeCounts['full_window']}\n";
echo "BSE ranges wider than 45 days (sampled): {$wideBseRanges}\n\n";

echo "--- Last fill chunk ---\n";
$lastFill = json_decode((string) Setting::getValue(PriceHistoryGapService::KEY_LAST_FILL_JSON, ''), true);
if (is_array($lastFill)) {
    foreach (['mode', 'filled', 'failed', 'stored_rows', 'stopped_reason', 'completed', 'processed_total', 'remaining', 'completed_at'] as $key) {
        if (array_key_exists($key, $lastFill)) {
            echo $key.': '.(is_bool($lastFill[$key]) ? ($lastFill[$key] ? 'true' : 'false') : $lastFill[$key])."\n";
        }
    }
    if (! empty($lastFill['errors'])) {
        echo "errors:\n";
        foreach (array_slice((array) $lastFill['errors'], 0, 10) as $err) {
            echo '  - '.$err."\n";
        }
    }
} else {
    echo "(none)\n";
}
echo "\n";

echo "--- Failure report (last 8) ---\n";
$failureReport = json_decode((string) Setting::getValue(PriceHistoryGapService::KEY_FILL_FAILURE_REPORT_JSON, ''), true);
if (is_array($failureReport)) {
    echo 'failure_count: '.($failureReport['failure_count'] ?? 0)."\n";
    $failures = is_array($failureReport['failures'] ?? null) ? $failureReport['failures'] : [];
    foreach (array_slice($failures, -8) as $row) {
        echo ($row['symbol'] ?? '?').' ['.($row['exchange'] ?? '?').'] providers='
            .implode(',', $row['providers_tried'] ?? [])."\n";
        foreach (array_slice((array) ($row['errors'] ?? []), 0, 3) as $err) {
            echo '    '.$err."\n";
        }
        foreach (array_slice((array) ($row['remaining_ranges'] ?? []), 0, 2) as $range) {
            echo '    remaining: '.($range['from'] ?? '?').' → '.($range['to'] ?? '?')."\n";
        }
    }
} else {
    echo "(none)\n";
}
echo "\n";

echo "--- Latest gap-fill sync runs ---\n";
$runs = SyncRun::query()
    ->where('job_name', 'price-history-gap-fill')
    ->orderByDesc('id')
    ->limit(5)
    ->get(['id', 'status', 'started_at', 'finished_at', 'summary']);

foreach ($runs as $run) {
    echo "#{$run->id} {$run->status} started={$run->started_at} finished={$run->finished_at}\n";
    if ($run->summary) {
        echo '  '.$run->summary."\n";
    }
}
echo "\n";

echo "--- portfolio_settings row sizes ---\n";
$keys = [
    PriceHistoryGapService::KEY_LAST_SCAN_JSON,
    PriceHistoryGapService::KEY_FILL_FAILURE_REPORT_JSON,
    PriceHistoryGapService::KEY_LAST_FILL_JSON,
];
foreach ($keys as $key) {
    $len = DB::table('portfolio_settings')->where('setting_key', $key)->value(DB::raw('LENGTH(setting_value)'));
    echo $key.': '.($len ?? 0)." bytes\n";
}

$logsDir = $root.'/storage/logs';
$logFile = $logsDir.'/laravel-'.date('Y-m-d').'.log';
echo "\n--- Laravel log tail (gap / fill / ERROR) ---\n";
if (is_file($logFile)) {
    $lines = file($logFile, FILE_IGNORE_NEW_LINES) ?: [];
    $matched = 0;
    foreach (array_reverse($lines) as $line) {
        if (! preg_match('/gap|fill|ERROR|CRITICAL|fill_all_aborted/i', $line)) {
            continue;
        }
        echo $line."\n";
        if (++$matched >= 25) {
            break;
        }
    }
    if ($matched === 0) {
        echo "(no matching lines today)\n";
    }
} else {
    echo "Log not found: {$logFile}\n";
}

echo "\nDone. DELETE cpanel-gap-fill-debug.php after use.\n";
