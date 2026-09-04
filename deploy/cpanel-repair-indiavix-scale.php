<?php
/**
 * Repair India VIX OHLC rows stored at ×100 scale (e.g. 1264.5 instead of 12.645).
 * Dry-run by default. Pass &apply=1 to write. DELETE after success.
 *
 * Upload: public_html/portfolio/cpanel-repair-indiavix-scale.php
 * Visit:  https://www.lidoalexion.com/portfolio/cpanel-repair-indiavix-scale.php?token=Lido
 * Apply:  ...?token=Lido&apply=1
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
    exit("Laravel root not found.\n");
}

require $laravelRoot.'/vendor/autoload.php';
$app = require $laravelRoot.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\PortfolioProfile;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Services\ProfileSettingsService;
use App\Support\IndiaVixScale;

$apply = isset($_GET['apply']) && (string) $_GET['apply'] === '1';

echo "=== Repair India VIX ×100 scale ===\n";
echo 'Mode: '.($apply ? 'APPLY' : 'DRY-RUN (add &apply=1 to write)')."\n\n";

$stock = Stock::query()
    ->where('symbol', IndiaVixScale::SYMBOL)
    ->where('is_benchmark', true)
    ->orderBy('id')
    ->first();

if ($stock === null) {
    echo "INDIAVIX benchmark stock not found.\n";
    exit(0);
}

$bad = StockPrice::query()
    ->where('stock_id', $stock->id)
    ->where('close_price', '>=', IndiaVixScale::MAX_SANE_CLOSE)
    ->where('close_price', '<', IndiaVixScale::MAX_RESCALE_CLOSE)
    ->orderBy('price_date')
    ->get();

echo "Stock id={$stock->id} yahoo={$stock->yahoo_symbol}\n";
echo 'Scaled rows found: '.$bad->count()."\n\n";

foreach ($bad as $row) {
    $before = (float) $row->close_price;
    $normalized = IndiaVixScale::normalizeRow([
        'open_price' => $row->open_price,
        'high_price' => $row->high_price,
        'low_price' => $row->low_price,
        'close_price' => $row->close_price,
        'adjusted_close_price' => $row->adjusted_close_price,
    ]);
    $after = (float) $normalized['close_price'];
    echo sprintf(
        "%s  close %s → %s  (O %s→%s H %s→%s L %s→%s)\n",
        $row->price_date?->toDateString() ?? (string) $row->getRawOriginal('price_date'),
        number_format($before, 4, '.', ''),
        number_format($after, 4, '.', ''),
        number_format((float) $row->open_price, 4, '.', ''),
        number_format((float) ($normalized['open_price'] ?? 0), 4, '.', ''),
        number_format((float) $row->high_price, 4, '.', ''),
        number_format((float) ($normalized['high_price'] ?? 0), 4, '.', ''),
        number_format((float) $row->low_price, 4, '.', ''),
        number_format((float) ($normalized['low_price'] ?? 0), 4, '.', ''),
    );

    if ($apply) {
        $row->fill([
            'open_price' => $normalized['open_price'] ?? $row->open_price,
            'high_price' => $normalized['high_price'] ?? $row->high_price,
            'low_price' => $normalized['low_price'] ?? $row->low_price,
            'close_price' => $normalized['close_price'],
            'adjusted_close_price' => $normalized['adjusted_close_price']
                ?? $normalized['close_price'],
        ]);
        $row->save();
    }
}

if ($apply && $bad->isNotEmpty()) {
    $profiles = PortfolioProfile::query()->orderBy('id')->get();
    $settings = app(ProfileSettingsService::class);
    foreach ($profiles as $profile) {
        $settings->setIndiaVixAlertArmed($profile, true);
        echo "Re-armed India VIX alert for profile #{$profile->id} ({$profile->name})\n";
    }
}

echo "\nDone. DELETE this file after success.\n";
