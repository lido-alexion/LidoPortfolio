<?php
/**
 * Read-only gap inventory analysis (same data as GET /api/universe-price-sync/gaps/status).
 * DELETE after use.
 *
 * Upload: public_html/portfolio/cpanel-gap-analysis.php
 * Visit:  https://www.lidoalexion.com/portfolio/cpanel-gap-analysis.php?token=Lido
 * Optional: &sample=5 (provider probe on first N suffix/internal gaps)
 *          &deactivate_candidates=1 (list BSE-only stocks with zero OHLCV in history window)
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
@set_time_limit(120);

$root = is_file(__DIR__.'/laravel/vendor/autoload.php') ? __DIR__.'/laravel' : dirname(__DIR__).'/lidoportfolio';
if (! is_file($root.'/vendor/autoload.php')) {
    http_response_code(500);
    exit("Laravel not found at {$root}\n");
}

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Setting;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Services\PriceHistoryGapService;
use App\Services\StockPriceHistoryService;
use App\Support\TradingCalendar;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

$sampleProbes = max(0, min(10, (int) ($_GET['sample'] ?? 3)));
$listDeactivateCandidates = (($_GET['deactivate_candidates'] ?? '') === '1');

echo "=== Gap inventory analysis ===\n";
echo 'Generated: '.now()->toIso8601String()."\n\n";

$gaps = app(PriceHistoryGapService::class);
$history = app(StockPriceHistoryService::class);
$status = $gaps->status();

$lastFill = $status['last_fill'] ?? [];
$failureReport = $status['last_fill_failure_report'] ?? [];
$scanWithGaps = (int) ($status['last_scan']['with_gaps'] ?? 0);
$stillWithGaps = (int) ($lastFill['still_with_gaps'] ?? -1);
$reportResolved = (int) ($failureReport['resolved'] ?? -1);
$reportUnresolved = (int) ($failureReport['unresolved'] ?? -1);
$failureRows = is_array($failureReport['failures'] ?? null) ? $failureReport['failures'] : [];
$failureStockIds = array_flip(array_values(array_unique(array_map(
    static fn (array $row) => (int) ($row['stock_id'] ?? 0),
    $failureRows,
))));

echo "--- Status (API-equivalent) ---\n";
echo 'Universe count: '.($status['universe_count'] ?? 0)."\n";
echo 'Inventory count: '.($status['inventory_stock_count'] ?? 0)."\n";
echo 'Required through: '.($status['required_through_session'] ?? '?')."\n";
echo 'History window days: '.($status['history_window_days'] ?? '?')."\n";
echo 'Last scan completed: '.($status['last_scan']['completed_at'] ?? 'never')."\n";
echo 'Last scan with_gaps: '.$scanWithGaps."\n";
echo 'Last fill still_with_gaps: '.($stillWithGaps >= 0 ? $stillWithGaps : '?')."\n";
echo 'Failure report resolved: '.($reportResolved >= 0 ? $reportResolved : '?')."\n";
echo 'Failure report unresolved: '.($reportUnresolved >= 0 ? $reportUnresolved : '?')."\n";
echo 'Failure detail rows stored: '.count($failureRows)."\n\n";

echo "--- Number reconciliation ---\n";
if ($scanWithGaps > 0 && $stillWithGaps >= 0 && $reportResolved >= 0) {
    echo "scan with_gaps ({$scanWithGaps}) = resolved ({$reportResolved}) + still gapped ({$stillWithGaps})\n";
    echo 'implied fixed: '.($scanWithGaps - $stillWithGaps)." (should equal resolved {$reportResolved})\n";
}
if ($reportUnresolved >= 0 && $stillWithGaps >= 0 && $reportUnresolved !== $stillWithGaps) {
    echo "WARNING: report.unresolved ({$reportUnresolved}) != still_with_gaps ({$stillWithGaps})\n";
} elseif ($reportUnresolved >= 0) {
    echo "report.unresolved matches still_with_gaps ({$stillWithGaps})\n";
}
if ($reportUnresolved >= 0 && count($failureRows) < $reportUnresolved) {
    $hidden = $reportUnresolved - count($failureRows);
    echo "UI used to show failure_count (".count($failureRows).") as unresolved; true unresolved is {$reportUnresolved} ({$hidden} not in detail list when capped).\n";
}
echo "Last fill 'filled' is chunk successes; 'failed' is chunk provider failures — not the same as resolved/unresolved.\n";
echo "symbols_with_gaps in last_scan is frozen at scan time; still_with_gaps is a live re-check after fill.\n\n";

$inventory = Setting::getValue(PriceHistoryGapService::KEY_GAP_INVENTORY_JSON);
$inventoryData = $inventory ? json_decode($inventory, true) : null;
$inventoryStockIds = is_array($inventoryData['stock_ids'] ?? null) ? $inventoryData['stock_ids'] : [];
['from' => $reqFrom, 'to' => $reqTo] = $gaps->requiredWindow();

$liveStillGapped = [];
foreach ($inventoryStockIds as $id) {
    $stock = Stock::query()->find((int) $id);
    if ($stock === null) {
        continue;
    }
    if ($gaps->gapsForStock($stock)['has_gaps']) {
        $liveStillGapped[] = (int) $stock->id;
    }
}
echo 'Live still gapped (inventory re-check now): '.count($liveStillGapped)."\n";
$notInFailureReport = array_values(array_filter(
    $liveStillGapped,
    static fn (int $id) => ! isset($failureStockIds[$id]),
));
echo 'Still gapped but not in failure detail list: '.count($notInFailureReport)."\n\n";

if ($listDeactivateCandidates) {
    echo "--- BSE-only deactivate candidates (0 OHLCV in window, still gapped) ---\n";
    $candidates = [];
    foreach ($liveStillGapped as $stockId) {
        $stock = Stock::query()->find($stockId);
        if ($stock === null || $stock->exchange !== 'BSE') {
            continue;
        }
        $hasActiveNse = Stock::query()
            ->where('symbol', $stock->symbol)
            ->where('exchange', 'NSE')
            ->where('is_benchmark', false)
            ->where('is_active', true)
            ->exists();
        if ($hasActiveNse) {
            continue;
        }
        $priceRows = StockPrice::query()
            ->where('stock_id', $stock->id)
            ->whereBetween('price_date', [$reqFrom->toDateString(), $reqTo->toDateString()])
            ->count();
        if ($priceRows > 0) {
            continue;
        }
        $failure = null;
        foreach ($failureRows as $row) {
            if ((int) ($row['stock_id'] ?? 0) === $stock->id) {
                $failure = $row;
                break;
            }
        }
        $candidates[] = [
            'id' => $stock->id,
            'symbol' => $stock->symbol,
            'isin' => $stock->isin,
            'bse_scrip_code' => $stock->bse_scrip_code,
            'is_active' => $stock->is_active,
            'in_failure_report' => $failure !== null,
            'providers_tried' => $failure['providers_tried'] ?? [],
            'errors' => array_slice($failure['errors'] ?? [], 0, 2),
        ];
    }
    echo 'Count: '.count($candidates)."\n";
    foreach ($candidates as $row) {
        echo json_encode($row, JSON_UNESCAPED_UNICODE)."\n";
    }
    echo "\nApply deactivation: cpanel-deactivate-bse-unpriceable.php?token=Lido (dry run) then &apply=1\n\n";
}

echo "--- Status (continued) ---\n";
echo 'Fill failure report: '
    .(count($failureRows).' detail rows, ')
    .'resolved='.($failureReport['resolved'] ?? '?')
    .' unresolved='.($failureReport['unresolved'] ?? '?')."\n\n";

$lastScan = $status['last_scan'] ?? null;
$symbolsWithGaps = is_array($lastScan['symbols_with_gaps'] ?? null) ? $lastScan['symbols_with_gaps'] : [];

if ($symbolsWithGaps === []) {
    echo "No symbols_with_gaps in last_scan JSON. Run Scan all gaps first.\n";
    exit;
}

echo 'Analyzing '.count($symbolsWithGaps)." gapped symbols from last_scan...\n\n";

$categories = [
    'pre_listing_prefix' => 0,
    'suffix_edge' => 0,
    'internal' => 0,
    'mixed' => 0,
];
$exchangeCounts = ['NSE' => 0, 'BSE' => 0, 'OTHER' => 0];
$bucketExamples = [
    'pre_listing_prefix' => [],
    'suffix_edge' => [],
    'internal' => [],
    'mixed' => [],
];
$probeCandidates = [];

['from' => $reqFrom, 'to' => $reqTo] = $gaps->requiredWindow();

foreach ($symbolsWithGaps as $row) {
    $stockId = (int) ($row['stock_id'] ?? 0);
    $symbol = (string) ($row['symbol'] ?? '?');
    $ranges = is_array($row['ranges'] ?? null) ? $row['ranges'] : [];

    $stock = $stockId > 0 ? Stock::query()->find($stockId) : null;
    $exchange = strtoupper((string) ($stock->exchange ?? 'OTHER'));
    if (isset($exchangeCounts[$exchange])) {
        $exchangeCounts[$exchange]++;
    } else {
        $exchangeCounts['OTHER']++;
    }

    $priceBounds = DB::table('portfolio_stock_prices')
        ->where('stock_id', $stockId)
        ->selectRaw('MIN(price_date) as min_date, MAX(price_date) as max_date, COUNT(*) as row_count')
        ->first();

    $minDate = $priceBounds?->min_date ? Carbon::parse($priceBounds->min_date)->startOfDay() : null;
    $maxDate = $priceBounds?->max_date ? Carbon::parse($priceBounds->max_date)->startOfDay() : null;

    $rangeTypes = [];
    foreach ($ranges as $range) {
        $from = Carbon::parse($range['from'] ?? $reqFrom->toDateString())->startOfDay();
        $to = Carbon::parse($range['to'] ?? $reqTo->toDateString())->startOfDay();

        $type = 'internal';
        if ($minDate && $to->copy()->addDay()->equalTo($minDate)) {
            $type = 'pre_listing_prefix';
        } elseif ($maxDate && $from->copy()->subDay()->equalTo($maxDate)) {
            $type = 'suffix_edge';
        } elseif ($minDate && $maxDate && $from->gte($minDate) && $to->lte($maxDate)) {
            $type = 'internal';
        } elseif ($minDate && $to->lt($minDate)) {
            $type = 'pre_listing_prefix';
        } elseif ($maxDate && $from->gt($maxDate)) {
            $type = 'suffix_edge';
        }

        $rangeTypes[] = $type;
    }

    $uniqueTypes = array_values(array_unique($rangeTypes));
    $bucket = count($uniqueTypes) === 1 ? $uniqueTypes[0] : 'mixed';
    $categories[$bucket] = ($categories[$bucket] ?? 0) + 1;

    if (count($bucketExamples[$bucket] ?? []) < 8) {
        $bucketExamples[$bucket][] = [
            'symbol' => $symbol,
            'exchange' => $exchange,
            'ranges' => $ranges,
            'first_price' => $minDate?->toDateString(),
            'last_price' => $maxDate?->toDateString(),
            'price_rows' => (int) ($priceBounds->row_count ?? 0),
            'created_at' => $stock?->created_at?->toDateString(),
            'range_types' => $rangeTypes,
        ];
    }

    if ($bucket !== 'pre_listing_prefix' && count($probeCandidates) < $sampleProbes) {
        $probeCandidates[] = [
            'symbol' => $symbol,
            'exchange' => $exchange,
            'range' => $ranges[0] ?? null,
        ];
    }
}

echo "--- Gap category breakdown ---\n";
foreach ($categories as $name => $count) {
    echo str_pad($name, 22).": {$count}\n";
}
echo "\n--- Exchange breakdown ---\n";
foreach ($exchangeCounts as $ex => $count) {
    if ($count > 0) {
        echo "{$ex}: {$count}\n";
    }
}

foreach ($bucketExamples as $bucket => $examples) {
    if ($examples === []) {
        continue;
    }
    echo "\n--- Examples: {$bucket} ---\n";
    foreach ($examples as $ex) {
        $rangeStr = implode('; ', array_map(
            static fn (array $r) => ($r['from'] ?? '?').'→'.($r['to'] ?? '?'),
            $ex['ranges'],
        ));
        echo $ex['symbol'].' ('.$ex['exchange'].')'
            .' prices='.$ex['first_price'].'..'.$ex['last_price'].' ('.$ex['price_rows'].' rows)'
            .' created='.$ex['created_at']
            ."\n  gaps: {$rangeStr}\n";
    }
}

// Re-verify with live gap logic (detects pre-listing skip if deployed)
echo "\n--- Live getMissingHistoryRanges check (first 20 inventory stocks) ---\n";
$inventory = Setting::getValue(PriceHistoryGapService::KEY_GAP_INVENTORY_JSON);
$inventoryData = $inventory ? json_decode($inventory, true) : null;
$stockIds = is_array($inventoryData['stock_ids'] ?? null) ? array_slice($inventoryData['stock_ids'], 0, 20) : [];
$stillGapped = 0;
$clearedByLogic = 0;
foreach ($stockIds as $id) {
    $stock = Stock::query()->find((int) $id);
    if (! $stock) {
        continue;
    }
    $live = $history->getMissingHistoryRanges($stock, $reqFrom, $reqTo);
    if ($live === []) {
        $clearedByLogic++;
        echo "{$stock->symbol}: inventory stale — live logic shows NO gaps (rescan needed)\n";
    } else {
        $stillGapped++;
        $r = $live[0];
        echo "{$stock->symbol}: still gapped ".$r['from']->toDateString().'→'.$r['to']->toDateString()."\n";
    }
}
echo "Sample: {$stillGapped} still gapped, {$clearedByLogic} cleared by current logic (stale inventory)\n";

$failures = $status['last_fill_failure_report']['failures'] ?? [];
if (is_array($failures) && $failures !== []) {
    echo "\n--- Fill failure error patterns (top) ---\n";
    $patterns = [];
    foreach ($failures as $f) {
        $err = implode(' ', $f['errors'] ?? []);
        $key = preg_replace('/\([^)]+\)/', '(SYM)', $err) ?? $err;
        $patterns[$key] = ($patterns[$key] ?? 0) + 1;
    }
    arsort($patterns);
    foreach (array_slice($patterns, 0, 8, true) as $pattern => $count) {
        echo "{$count}x: ".substr($pattern, 0, 120)."\n";
    }
}

if ($probeCandidates !== []) {
    echo "\n--- Provider probe sample ---\n";
    $nse = new App\Services\PriceProviders\NsePriceProvider(app(App\Services\SettingsService::class));
    $yahoo = new App\Services\PriceProviders\YahooPriceProvider();
    $resolver = app(App\Services\ProviderResolverService::class);

    foreach ($probeCandidates as $c) {
        $stock = Stock::query()->where('symbol', $c['symbol'])->first();
        if (! $stock || ! is_array($c['range'])) {
            continue;
        }
        $from = Carbon::parse($c['range']['from'])->startOfDay();
        $to = Carbon::parse($c['range']['to'])->startOfDay();
        echo "\n{$c['symbol']} ({$c['exchange']}) gap {$from->toDateString()}→{$to->toDateString()}\n";

        if (strtoupper($c['exchange']) === 'BSE') {
            echo "  NSE: skipped\n";
        } else {
            try {
                $rows = $nse->fetchHistorical($c['symbol'], $from, $to);
                echo '  NSE: '.count($rows)." rows\n";
            } catch (Throwable $e) {
                echo '  NSE: FAIL '.$e->getMessage()."\n";
            }
        }

        foreach ($resolver->yahooSymbolCandidates($stock) as $ys) {
            try {
                $rows = $yahoo->fetchHistorical($c['symbol'], $from, $to, $ys);
                echo "  Yahoo ({$ys}): ".count($rows)." rows\n";
                if ($rows !== []) {
                    break;
                }
            } catch (Throwable $e) {
                echo "  Yahoo ({$ys}): FAIL ".$e->getMessage()."\n";
            }
        }
    }
}

echo "\nDone. DELETE this file after use.\n";
