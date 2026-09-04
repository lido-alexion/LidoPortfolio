<?php
/**
 * Deactivate active BSE-only stocks with zero OHLCV in the universe history window.
 *
 * Upload: public_html/portfolio/cpanel-deactivate-bse-unpriceable.php
 * Dry run:  ...?token=Lido
 * Apply:    ...?token=Lido&apply=1
 * Optional: &require_gap=1 (only stocks still gapped in latest inventory)
 *
 * DELETE this file from the server after success.
 */
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);
set_time_limit(0);

const SETUP_TOKEN = 'Lido';

if (($_GET['token'] ?? '') !== SETUP_TOKEN) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Forbidden.\n");
}

header('Content-Type: text/plain; charset=utf-8');

$root = is_file(__DIR__.'/laravel/vendor/autoload.php') ? __DIR__.'/laravel' : dirname(__DIR__).'/lidoportfolio';
if (! is_file($root.'/vendor/autoload.php')) {
    http_response_code(500);
    exit("Laravel not found.\n");
}

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Stock;
use App\Models\StockPrice;
use App\Services\PriceHistoryGapService;

$apply = (($_GET['apply'] ?? '') === '1');
$requireGap = (($_GET['require_gap'] ?? '1') === '1');

/** @var PriceHistoryGapService $gaps */
$gaps = $app->make(PriceHistoryGapService::class);
['from' => $reqFrom, 'to' => $reqTo] = $gaps->requiredWindow();

$inventoryIds = [];
$inventoryRaw = \App\Models\Setting::getValue(PriceHistoryGapService::KEY_GAP_INVENTORY_JSON);
if ($inventoryRaw) {
    $inventory = json_decode($inventoryRaw, true);
    $inventoryIds = array_flip(array_map('intval', is_array($inventory['stock_ids'] ?? null) ? $inventory['stock_ids'] : []));
}

echo "=== Deactivate BSE-only unpriceable stocks ===\n";
echo 'Mode: '.($apply ? 'APPLY' : 'dry run (add &apply=1)')."\n";
echo "Window: {$reqFrom->toDateString()} → {$reqTo->toDateString()}\n";
echo 'Require still gapped in inventory: '.($requireGap ? 'yes' : 'no')."\n\n";

$candidates = Stock::query()
    ->where('exchange', 'BSE')
    ->where('is_benchmark', false)
    ->where('is_active', true)
    ->orderBy('symbol')
    ->get();

$toDeactivate = [];

foreach ($candidates as $stock) {
    $hasActiveNse = Stock::query()
        ->where('symbol', $stock->symbol)
        ->where('exchange', 'NSE')
        ->where('is_benchmark', false)
        ->where('is_active', true)
        ->exists();
    if ($hasActiveNse) {
        continue;
    }

    if ($requireGap && ! isset($inventoryIds[(int) $stock->id])) {
        continue;
    }

    if ($requireGap && ! $gaps->gapsForStock($stock)['has_gaps']) {
        continue;
    }

    $priceRows = StockPrice::query()
        ->where('stock_id', $stock->id)
        ->whereBetween('price_date', [$reqFrom->toDateString(), $reqTo->toDateString()])
        ->count();
    if ($priceRows > 0) {
        continue;
    }

    $toDeactivate[] = $stock;
}

echo 'Candidates: '.count($toDeactivate)."\n\n";
foreach ($toDeactivate as $stock) {
    echo $stock->id."\t".$stock->symbol."\t".($stock->isin ?: '-')."\t".($stock->bse_scrip_code ?: '-')."\n";
}

if ($apply && $toDeactivate !== []) {
    $ids = array_map(static fn (Stock $stock) => $stock->id, $toDeactivate);
    $updated = Stock::query()->whereIn('id', $ids)->update(['is_active' => false]);
    echo "\nDeactivated: {$updated}\n";
    echo "Re-run Scan all gaps after deactivation to refresh inventory.\n";
} elseif (! $apply) {
    echo "\nDry run only. Re-run with &apply=1 to deactivate.\n";
}

echo "\nDELETE cpanel-deactivate-bse-unpriceable.php after success.\n";
