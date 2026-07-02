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
            '12m' => 400,
        ],
        'max_internal_gap_days' => 7,
    ],

    'stock_master' => [
        'nse_equity_csv_url' => env(
            'NSE_EQUITY_CSV_URL',
            'https://archives.nseindia.com/content/equities/EQUITY_L.csv',
        ),
        'bse_enabled' => filter_var(env('BSE_STOCK_MASTER_ENABLED', true), FILTER_VALIDATE_BOOL),
        'bse_equity_csv_url' => env('BSE_EQUITY_CSV_URL', ''),
        'bse_list_api_url' => env(
            'BSE_EQUITY_LIST_API_URL',
            'https://api.bseindia.com/BseIndiaAPI/api/ListofScripData/w?Group=&Scripcode=&industry=&segment=Equity&status=Active',
        ),
        'revalidation_days' => (int) env('STOCK_REVALIDATION_DAYS', 7),
        // UI stock-master sync skips price backfill (too slow for HTTP); CLI `stocks:sync` backfills by default.
        'backfill_new_symbols_on_cli_sync' => filter_var(env('STOCK_MASTER_BACKFILL_ON_SYNC', true), FILTER_VALIDATE_BOOL),
        'max_backfill_per_sync' => max(0, (int) env('STOCK_MASTER_MAX_BACKFILL_PER_SYNC', 25)),
    ],

    /*
    | Universe-wide OHLCV sync for NSE equities (independent of holdings).
    | Run `stocks:sync` first so portfolio_stocks is populated.
    | Initial backfill: portfolio:sync-universe-prices --mode=backfill --all
    | Daily updates: scheduled batches after market close (cursor-based).
    */
    'universe_price_sync' => [
        'enabled' => filter_var(env('UNIVERSE_PRICE_SYNC_ENABLED', true), FILTER_VALIDATE_BOOL),
        // all_equities = active NSE + BSE-only (ISIN deduped); nifty500 = NSE index constituents only
        'scope' => env('UNIVERSE_PRICE_SYNC_SCOPE', 'all_equities'),
        'history_days' => max(30, (int) env('UNIVERSE_PRICE_SYNC_HISTORY_DAYS', 365)),
        'daily_lookback_days' => max(3, (int) env('UNIVERSE_PRICE_SYNC_DAILY_LOOKBACK_DAYS', 10)),
        'delay_ms_between_stocks' => max(0, (int) env('UNIVERSE_PRICE_SYNC_DELAY_MS', 400)),
        'batch_size' => max(1, (int) env('UNIVERSE_PRICE_SYNC_BATCH_SIZE', 75)),
        'maintenance_gap_fill_retries' => max(0, (int) env('UNIVERSE_MAINTENANCE_GAP_FILL_RETRIES', 2)),
        'gap_fill_wait_seconds' => max(0, (int) env('UNIVERSE_GAP_FILL_WAIT_SECONDS', 20)),
        'nifty500_index_name' => env('UNIVERSE_NIFTY500_INDEX_NAME', 'NIFTY 500'),
        'nifty500_cache_days' => max(1, (int) env('UNIVERSE_NIFTY500_CACHE_DAYS', 7)),
    ],

    /*
    | Admin operational alerts (rate limits, scheduler downtime, job failures).
    | Telegram goes to all admin users with Telegram configured on any portfolio.
    */
    'operational_alerts' => [
        'telegram_cooldown_hours' => max(1, (int) env('ADMIN_OPS_ALERT_TELEGRAM_COOLDOWN_HOURS', 6)),
        'daily_sync_stale_hours' => max(12, (int) env('ADMIN_OPS_DAILY_SYNC_STALE_HOURS', 36)),
        'universe_sync_stale_hours' => max(6, (int) env('ADMIN_OPS_UNIVERSE_SYNC_STALE_HOURS', 26)),
        'universe_sync_stale_minutes_maintenance' => max(15, (int) env('ADMIN_OPS_UNIVERSE_SYNC_STALE_MINUTES', 45)),
        'stock_master_stale_days' => max(2, (int) env('ADMIN_OPS_STOCK_MASTER_STALE_DAYS', 8)),
        'scheduler_dead_hours' => max(12, (int) env('ADMIN_OPS_SCHEDULER_DEAD_HOURS', 48)),
    ],
];
