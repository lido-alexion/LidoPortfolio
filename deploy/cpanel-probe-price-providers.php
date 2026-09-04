<?php
/**
 * Probe NSE/Yahoo price providers from production. DELETE after use.
 *
 * Upload: public_html/portfolio/cpanel-probe-price-providers.php
 * Visit:  https://www.lidoalexion.com/portfolio/cpanel-probe-price-providers.php?token=Lido
 * Optional: &symbol=RELIANCE&from=2025-07-09&to=2026-04-19
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

$root = is_file(__DIR__.'/laravel/vendor/autoload.php') ? __DIR__.'/laravel' : dirname(__DIR__).'/lidoportfolio';
if (! is_file($root.'/vendor/autoload.php')) {
    http_response_code(500);
    exit("Laravel not found at {$root}\n");
}

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\PriceProviders\NsePriceProvider;
use App\Services\PriceProviders\YahooPriceProvider;
use App\Services\ProviderResolverService;
use App\Services\SettingsService;
use App\Models\Stock;
use Carbon\Carbon;

$symbol = strtoupper((string) ($_GET['symbol'] ?? 'RELIANCE'));
$from = Carbon::parse((string) ($_GET['from'] ?? '2025-07-09'))->startOfDay();
$to = Carbon::parse((string) ($_GET['to'] ?? '2026-04-19'))->startOfDay();

$stock = \App\Models\Stock::query()
    ->where('symbol', preg_replace('/\.(NS|BO)$/', '', $symbol))
    ->orderByRaw("CASE WHEN exchange = 'NSE' THEN 0 ELSE 1 END")
    ->first();

echo "=== Price provider probe ===\n";
echo "Symbol: {$symbol}\n";
if ($stock) {
    echo "DB stock: {$stock->symbol} exchange={$stock->exchange} yahoo={$stock->yahoo_symbol}\n";
}
echo "Range: {$from->toDateString()} -> {$to->toDateString()}\n\n";

$resolver = app(ProviderResolverService::class);
$nse = new NsePriceProvider(app(SettingsService::class));
$yahoo = new YahooPriceProvider();

if ($stock && strtoupper((string) $stock->exchange) === 'BSE') {
    echo "NSE: skipped (BSE-only symbol)\n\n";
} else {
    try {
        $rows = $nse->fetchHistorical($symbol, $from, $to);
        echo 'NSE: OK rows='.count($rows)."\n";
        if ($rows !== []) {
            echo '  first='.$rows[0]['price_date'].' close='.$rows[0]['close_price']."\n";
            $last = $rows[array_key_last($rows)];
            echo '  last='.$last['price_date'].' close='.$last['close_price']."\n";
        }
    } catch (Throwable $e) {
        echo 'NSE: FAIL '.$e->getMessage()."\n";
    }

    echo "\n";
}

try {
    $candidates = $stock
        ? $resolver->yahooSymbolCandidates($stock)
        : [str_ends_with($symbol, '.NS') || str_ends_with($symbol, '.BO') ? $symbol : $symbol.'.NS'];
    echo 'Yahoo candidates: '.implode(', ', $candidates)."\n";
    foreach ($candidates as $yahooSymbol) {
        try {
            $rows = $yahoo->fetchHistorical($symbol, $from, $to, $yahooSymbol);
            echo "Yahoo ({$yahooSymbol}): OK rows=".count($rows)."\n";
            if ($rows !== []) {
                break;
            }
        } catch (Throwable $e) {
            echo "Yahoo ({$yahooSymbol}): FAIL ".$e->getMessage()."\n";
        }
    }
} catch (Throwable $e) {
    echo 'Yahoo: FAIL '.$e->getMessage()."\n";
}

echo "\nDone. DELETE this file after use.\n";
