<?php

$defaultCa = null;
$candidates = [
    env('CURL_CAFILE'),
    'C:\\certs\\cacert.pem',
    '/etc/ssl/certs/ca-certificates.crt',
    '/etc/pki/tls/certs/ca-bundle.crt',
];

foreach ($candidates as $path) {
    if ($path && is_readable($path)) {
        $defaultCa = $path;
        break;
    }
}

return [
    'ca_bundle' => $defaultCa,
    'ssl_verify' => filter_var(env('HTTP_SSL_VERIFY', true), FILTER_VALIDATE_BOOL),

    'history' => [
        'portfolio_lookback_months' => 3,
        'analytics_buffer_days' => [
            '1m' => 60,
            '3m' => 150,
            '6m' => 210,
        ],
        'max_internal_gap_days' => 7,
    ],

    'stock_master' => [
        'nse_equity_csv_url' => env(
            'NSE_EQUITY_CSV_URL',
            'https://archives.nseindia.com/content/equities/EQUITY_L.csv',
        ),
        'bse_enabled' => filter_var(env('BSE_STOCK_MASTER_ENABLED', false), FILTER_VALIDATE_BOOL),
        'bse_equity_csv_url' => env('BSE_EQUITY_CSV_URL', ''),
        'revalidation_days' => (int) env('STOCK_REVALIDATION_DAYS', 7),
    ],

    /*
    | Universe-wide OHLCV sync for NSE equities (independent of holdings).
    | Run `stocks:sync` first so portfolio_stocks is populated.
    | Initial backfill: portfolio:sync-universe-prices --mode=backfill --all
    | Daily updates: scheduled batches after market close (cursor-based).
    */
    'universe_price_sync' => [
        'enabled' => filter_var(env('UNIVERSE_PRICE_SYNC_ENABLED', true), FILTER_VALIDATE_BOOL),
        // all_nse = every active NSE EQ row from stock master; nifty500 = NSE index constituents only
        'scope' => env('UNIVERSE_PRICE_SYNC_SCOPE', 'all_nse'),
        'history_days' => max(30, (int) env('UNIVERSE_PRICE_SYNC_HISTORY_DAYS', 365)),
        'daily_lookback_days' => max(3, (int) env('UNIVERSE_PRICE_SYNC_DAILY_LOOKBACK_DAYS', 10)),
        'delay_ms_between_stocks' => max(0, (int) env('UNIVERSE_PRICE_SYNC_DELAY_MS', 400)),
        'batch_size' => max(1, (int) env('UNIVERSE_PRICE_SYNC_BATCH_SIZE', 75)),
        'nifty500_index_name' => env('UNIVERSE_NIFTY500_INDEX_NAME', 'NIFTY 500'),
        'nifty500_cache_days' => max(1, (int) env('UNIVERSE_NIFTY500_CACHE_DAYS', 7)),
    ],
];
