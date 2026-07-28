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
            'https://archives.nseindia.com/content/equities/EQUITY_L.csv'
        ),
        'bse_enabled' => filter_var(env('BSE_STOCK_MASTER_ENABLED', true), FILTER_VALIDATE_BOOL),
        'bse_equity_csv_url' => env('BSE_EQUITY_CSV_URL', ''),
        'bse_list_api_url' => env(
            'BSE_EQUITY_LIST_API_URL',
            'https://api.bseindia.com/BseIndiaAPI/api/ListofScripData/w?Group=&Scripcode=&industry=&segment=Equity&status=Active'
        ),
        'revalidation_days' => (int) env('STOCK_REVALIDATION_DAYS', 7),
        // UI stock-master sync skips price backfill (too slow for HTTP); CLI `stocks:sync` backfills by default.
        'backfill_new_symbols_on_cli_sync' => filter_var(env('STOCK_MASTER_BACKFILL_ON_SYNC', true), FILTER_VALIDATE_BOOL),
        'max_backfill_per_sync' => max(0, (int) env('STOCK_MASTER_MAX_BACKFILL_PER_SYNC', 25)),
        'dual_listed_repair_backfill' => filter_var(env('STOCK_MASTER_DUAL_LISTED_REPAIR_BACKFILL', true), FILTER_VALIDATE_BOOL),
        'dual_listed_repair_max_backfill' => max(0, (int) env('STOCK_MASTER_DUAL_LISTED_REPAIR_MAX_BACKFILL', 50)),
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
        'batch_size' => max(1, (int) env('UNIVERSE_PRICE_SYNC_BATCH_SIZE', 125)),
        'maintenance_interval_minutes' => max(1, min(60, (int) env('UNIVERSE_MAINTENANCE_INTERVAL_MINUTES', 5))),
        'maintenance_start_hour' => max(0, min(23, (int) env('UNIVERSE_MAINTENANCE_START_HOUR', 19))),
        'maintenance_end_hour' => max(0, min(23, (int) env('UNIVERSE_MAINTENANCE_END_HOUR', 23))),
        'maintenance_end_minute' => max(0, min(59, (int) env('UNIVERSE_MAINTENANCE_END_MINUTE', 45))),
        'maintenance_gap_fill_retries' => max(0, (int) env('UNIVERSE_MAINTENANCE_GAP_FILL_RETRIES', 2)),
        'maintenance_gap_fill_enabled' => filter_var(env('UNIVERSE_MAINTENANCE_GAP_FILL_ENABLED', true), FILTER_VALIDATE_BOOL),
        'maintenance_gap_fill_chain_max_batches' => max(1, (int) env('UNIVERSE_MAINTENANCE_GAP_FILL_CHAIN_MAX_BATCHES', 500)),
        'gap_fill_wait_seconds' => max(0, (int) env('UNIVERSE_GAP_FILL_WAIT_SECONDS', 20)),
        // UI "Fill all gaps" chunk size — keep low for cPanel HTTP timeouts (~60–120s/request).
        'gap_fill_all_batch_size' => max(1, min(100, (int) env('UNIVERSE_GAP_FILL_ALL_BATCH_SIZE', 5))),
        // Per-stock gap fill skips bse_bhavcopy above this span (use bulk bhavcopy backfill instead).
        'bse_bhavcopy_max_gap_calendar_days' => max(7, (int) env('UNIVERSE_BSE_BHAVCOPY_MAX_GAP_CALENDAR_DAYS', 45)),
        // Per-stock gap fill should use bulk bhavcopy backfill for BSE; disable by default on cPanel.
        'bse_bhavcopy_gap_fill_enabled' => filter_var(env('UNIVERSE_BSE_BHAVCOPY_GAP_FILL_ENABLED', false), FILTER_VALIDATE_BOOL),
        'nifty500_index_name' => env('UNIVERSE_NIFTY500_INDEX_NAME', 'NIFTY 500'),
        'nifty500_cache_days' => max(1, (int) env('UNIVERSE_NIFTY500_CACHE_DAYS', 7)),
    ],

    /*
    | History depth backfill: one re-armable campaign that deepens stored OHLCV
    | beyond the rolling universe window (default ~18 months) so long-lookback
    | indicators (SMA200, 52-week high, etc.) have bars at the START of a 1-year
    | backtest instead of skipping stocks. Runs all day via the scheduler until
    | a full universe pass completes, then goes idle. Raising the target
    | re-arms the campaign automatically.
    */
    'history_depth_backfill' => [
        'enabled' => filter_var(env('HISTORY_DEPTH_BACKFILL_ENABLED', true), FILTER_VALIDATE_BOOL),
        'target_history_days' => max(365, (int) env('HISTORY_DEPTH_TARGET_DAYS', 550)),
        'batch_size' => max(1, min(200, (int) env('HISTORY_DEPTH_BATCH_SIZE', 25))),
        'delay_ms_between_stocks' => max(0, (int) env('HISTORY_DEPTH_DELAY_MS', 400)),
    ],

    /*
    | Market index OHLCV (is_benchmark rows). Tier A+B Yahoo/NSE charting sources.
    | Primary remains NIFTY50 for relative strength / Explorer until a later task.
    */
    'indexes' => [
        'enabled' => filter_var(env('INDEX_PRICE_SYNC_ENABLED', true), FILTER_VALIDATE_BOOL),
        'primary_symbol' => env('INDEX_PRIMARY_SYMBOL', 'NIFTY50'),
        'batch_size' => max(1, min(20, (int) env('INDEX_PRICE_SYNC_BATCH_SIZE', 3))),
        'history_days' => max(30, (int) env('INDEX_PRICE_SYNC_HISTORY_DAYS', 365)),
        'delay_ms_between_indexes' => max(0, (int) env('INDEX_PRICE_SYNC_DELAY_MS', 400)),
        'definitions' => [
            ['symbol' => 'NIFTY50', 'name' => 'Nifty 50', 'exchange' => 'NSE', 'tier' => 'broad', 'description' => 'NSE’s flagship large-cap index of the 50 most liquid stocks by free-float market cap. Widely used as the benchmark for Indian equity performance.', 'nse_charting_name' => 'NIFTY 50', 'yahoo_symbol' => '^NSEI', 'alpha_vantage_symbol' => 'NSEI', 'enabled' => true],
            ['symbol' => 'NIFTYBANK', 'name' => 'Nifty Bank', 'exchange' => 'NSE', 'tier' => 'sector', 'description' => 'Tracks the most liquid and large banking stocks on NSE. A common gauge of banking-sector sentiment.', 'nse_charting_name' => 'NIFTY BANK', 'yahoo_symbol' => '^NSEBANK', 'alpha_vantage_symbol' => null, 'enabled' => true],
            ['symbol' => 'NIFTYIT', 'name' => 'Nifty IT', 'exchange' => 'NSE', 'tier' => 'sector', 'description' => 'Tracks leading information technology companies on NSE. Reflects IT sector earnings and global tech demand.', 'nse_charting_name' => 'NIFTY IT', 'yahoo_symbol' => '^CNXIT', 'alpha_vantage_symbol' => null, 'enabled' => true],
            ['symbol' => 'NIFTYNEXT50', 'name' => 'Nifty Next 50', 'exchange' => 'NSE', 'tier' => 'broad', 'description' => 'The next 50 companies after Nifty 50 by free-float market cap (ranks 51–100). Often seen as a pipeline into the large-cap universe.', 'nse_charting_name' => 'NIFTY NEXT 50', 'yahoo_symbol' => '^NSMIDCP', 'alpha_vantage_symbol' => null, 'enabled' => true],
            ['symbol' => 'NIFTY100', 'name' => 'Nifty 100', 'exchange' => 'NSE', 'tier' => 'broad', 'description' => 'Combines Nifty 50 and Nifty Next 50 — the top 100 NSE companies by free-float market cap.', 'nse_charting_name' => 'NIFTY 100', 'yahoo_symbol' => '^CNX100', 'alpha_vantage_symbol' => null, 'enabled' => true],
            ['symbol' => 'NIFTY200', 'name' => 'Nifty 200', 'exchange' => 'NSE', 'tier' => 'broad', 'description' => 'Top 200 NSE companies by free-float market cap, spanning large- and mid-cap names.', 'nse_charting_name' => 'NIFTY 200', 'yahoo_symbol' => '^CNX200', 'alpha_vantage_symbol' => null, 'enabled' => true],
            ['symbol' => 'NIFTY500', 'name' => 'Nifty 500', 'exchange' => 'NSE', 'tier' => 'broad', 'description' => 'Broad market index of the top 500 NSE companies, covering roughly 96% of NSE free-float market cap.', 'nse_charting_name' => 'NIFTY 500', 'yahoo_symbol' => '^CRSLDX', 'alpha_vantage_symbol' => null, 'enabled' => true],
            ['symbol' => 'NIFTYMIDCAP50', 'name' => 'Nifty Midcap 50', 'exchange' => 'NSE', 'tier' => 'broad', 'description' => 'A liquid basket of 50 mid-cap NSE stocks selected by free-float market cap.', 'nse_charting_name' => 'NIFTY MIDCAP 50', 'yahoo_symbol' => '^NSEMDCP50', 'alpha_vantage_symbol' => null, 'enabled' => true],
            ['symbol' => 'NIFTYMIDCAP100', 'name' => 'Nifty Midcap 100', 'exchange' => 'NSE', 'tier' => 'broad', 'description' => '100 mid-cap NSE stocks by free-float market cap. A common mid-cap market gauge.', 'nse_charting_name' => 'NIFTY MIDCAP 100', 'yahoo_symbol' => 'NIFTY_MIDCAP_100.NS', 'alpha_vantage_symbol' => null, 'enabled' => true],
            ['symbol' => 'NIFTYMIDCAP150', 'name' => 'Nifty Midcap 150', 'exchange' => 'NSE', 'tier' => 'broad', 'description' => 'Broader mid-cap coverage with 150 NSE stocks ranked by free-float market cap.', 'nse_charting_name' => 'NIFTY MIDCAP 150', 'yahoo_symbol' => 'NIFTYMIDCAP150.NS', 'alpha_vantage_symbol' => null, 'enabled' => true],
            ['symbol' => 'NIFTYSMLCAP250', 'name' => 'Nifty Smallcap 250', 'exchange' => 'NSE', 'tier' => 'broad', 'description' => '250 small-cap NSE stocks by free-float market cap. Tracks the smaller end of the listed universe.', 'nse_charting_name' => 'NIFTY SMLCAP 250', 'yahoo_symbol' => 'NIFTYSMLCAP250.NS', 'alpha_vantage_symbol' => null, 'enabled' => true],
            ['symbol' => 'NIFTYFINSERVICE', 'name' => 'Nifty Financial Services', 'exchange' => 'NSE', 'tier' => 'sector', 'description' => 'Tracks financial services companies on NSE — banks, NBFCs, insurers, and related names.', 'nse_charting_name' => 'NIFTY FIN SERVICE', 'yahoo_symbol' => 'NIFTY_FIN_SERVICE.NS', 'alpha_vantage_symbol' => null, 'enabled' => true],
            ['symbol' => 'NIFTYPHARMA', 'name' => 'Nifty Pharma', 'exchange' => 'NSE', 'tier' => 'sector', 'description' => 'Tracks pharmaceutical and healthcare companies on NSE.', 'nse_charting_name' => 'NIFTY PHARMA', 'yahoo_symbol' => '^CNXPHARMA', 'alpha_vantage_symbol' => null, 'enabled' => true],
            ['symbol' => 'NIFTYAUTO', 'name' => 'Nifty Auto', 'exchange' => 'NSE', 'tier' => 'sector', 'description' => 'Tracks automobile makers and related auto-ancillary companies on NSE.', 'nse_charting_name' => 'NIFTY AUTO', 'yahoo_symbol' => '^CNXAUTO', 'alpha_vantage_symbol' => null, 'enabled' => true],
            ['symbol' => 'NIFTYFMCG', 'name' => 'Nifty FMCG', 'exchange' => 'NSE', 'tier' => 'sector', 'description' => 'Tracks fast-moving consumer goods companies on NSE — staples and everyday brands.', 'nse_charting_name' => 'NIFTY FMCG', 'yahoo_symbol' => '^CNXFMCG', 'alpha_vantage_symbol' => null, 'enabled' => true],
            ['symbol' => 'NIFTYMETAL', 'name' => 'Nifty Metal', 'exchange' => 'NSE', 'tier' => 'sector', 'description' => 'Tracks metal and mining companies on NSE, sensitive to commodity cycles.', 'nse_charting_name' => 'NIFTY METAL', 'yahoo_symbol' => '^CNXMETAL', 'alpha_vantage_symbol' => null, 'enabled' => true],
            ['symbol' => 'NIFTYREALTY', 'name' => 'Nifty Realty', 'exchange' => 'NSE', 'tier' => 'sector', 'description' => 'Tracks real estate and property developers listed on NSE.', 'nse_charting_name' => 'NIFTY REALTY', 'yahoo_symbol' => '^CNXREALTY', 'alpha_vantage_symbol' => null, 'enabled' => true],
            ['symbol' => 'NIFTYENERGY', 'name' => 'Nifty Energy', 'exchange' => 'NSE', 'tier' => 'sector', 'description' => 'Tracks energy companies on NSE, including oil, gas, and power-related names.', 'nse_charting_name' => 'NIFTY ENERGY', 'yahoo_symbol' => '^CNXENERGY', 'alpha_vantage_symbol' => null, 'enabled' => true],
            ['symbol' => 'NIFTYINFRA', 'name' => 'Nifty Infra', 'exchange' => 'NSE', 'tier' => 'sector', 'description' => 'Tracks infrastructure-related companies on NSE such as construction, utilities, and capital goods.', 'nse_charting_name' => 'NIFTY INFRA', 'yahoo_symbol' => '^CNXINFRA', 'alpha_vantage_symbol' => null, 'enabled' => true],
            ['symbol' => 'NIFTYPSUBANK', 'name' => 'Nifty PSU Bank', 'exchange' => 'NSE', 'tier' => 'sector', 'description' => 'Tracks public-sector (government-owned) banks listed on NSE.', 'nse_charting_name' => 'NIFTY PSU BANK', 'yahoo_symbol' => '^CNXPSUBANK', 'alpha_vantage_symbol' => null, 'enabled' => true],
            ['symbol' => 'NIFTYPVTBANK', 'name' => 'Nifty Private Bank', 'exchange' => 'NSE', 'tier' => 'sector', 'description' => 'Tracks private-sector banks listed on NSE.', 'nse_charting_name' => 'NIFTY PVT BANK', 'yahoo_symbol' => 'NIFTY_PVT_BANK.NS', 'alpha_vantage_symbol' => null, 'enabled' => true],
            ['symbol' => 'NIFTYMEDIA', 'name' => 'Nifty Media', 'exchange' => 'NSE', 'tier' => 'sector', 'description' => 'Tracks media and entertainment companies on NSE.', 'nse_charting_name' => 'NIFTY MEDIA', 'yahoo_symbol' => '^CNXMEDIA', 'alpha_vantage_symbol' => null, 'enabled' => true],
            ['symbol' => 'INDIAVIX', 'name' => 'India VIX', 'exchange' => 'NSE', 'tier' => 'volatility', 'description' => 'India’s volatility index. It reflects expected near-term Nifty volatility from option prices — not a price index of stocks.', 'nse_charting_name' => 'INDIA VIX', 'yahoo_symbol' => '^INDIAVIX', 'alpha_vantage_symbol' => null, 'enabled' => true],
            ['symbol' => 'SENSEX', 'name' => 'Sensex', 'exchange' => 'BSE', 'tier' => 'broad', 'description' => 'BSE’s flagship index of 30 of the largest and most actively traded stocks. The long-standing BSE market benchmark.', 'nse_charting_name' => null, 'yahoo_symbol' => '^BSESN', 'alpha_vantage_symbol' => null, 'enabled' => true],
            ['symbol' => 'BSE100', 'name' => 'BSE 100', 'exchange' => 'BSE', 'tier' => 'broad', 'description' => 'Top 100 BSE companies by market capitalisation — a broader large-cap BSE gauge than Sensex.', 'nse_charting_name' => null, 'yahoo_symbol' => 'BSE-100.BO', 'alpha_vantage_symbol' => null, 'enabled' => true],
            ['symbol' => 'BSE200', 'name' => 'BSE 200', 'exchange' => 'BSE', 'tier' => 'broad', 'description' => 'Top 200 BSE companies by market capitalisation, spanning large- and mid-cap names.', 'nse_charting_name' => null, 'yahoo_symbol' => 'BSE-200.BO', 'alpha_vantage_symbol' => null, 'enabled' => true],
            ['symbol' => 'BSE500', 'name' => 'BSE 500', 'exchange' => 'BSE', 'tier' => 'broad', 'description' => 'Broad BSE market index of the top 500 companies by market capitalisation.', 'nse_charting_name' => null, 'yahoo_symbol' => 'BSE-500.BO', 'alpha_vantage_symbol' => null, 'enabled' => true],
        ],
    ],

    /*
    | Market depth / breadth heatmap (Dashboard card + /market-depth page).
    | indexes: null or ['*'] = all NSE indexes with constituents; else explicit list.
    | RS 55 = 55-session return vs primary benchmark > 0.
    | Snapshots retained for history_retention_days (max 7) per exchange scope.
    */
    'market_depth' => [
        'indexes' => null,
        'rs_sessions' => max(1, (int) env('MARKET_DEPTH_RS_SESSIONS', 55)),
        'history_calendar_days' => max(250, (int) env('MARKET_DEPTH_HISTORY_DAYS', 400)),
        'cache_ttl_seconds' => max(300, (int) env('MARKET_DEPTH_CACHE_TTL', 21600)),
        'history_retention_days' => max(1, min(7, (int) env('MARKET_DEPTH_HISTORY_RETENTION', 7))),
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

    /*
    | Temporary agent/debug hooks (pre-launch only). See debugging.md.
    | API: header X-Lido-Debug-Token or ?debug_token= on /api/* routes.
    */
    'debug_agent' => [
        'enabled' => filter_var(env('LIDO_AGENT_DEBUG_ENABLED', true), FILTER_VALIDATE_BOOL),
        'token' => env('LIDO_AGENT_DEBUG_TOKEN', 'Lido'),
    ],
];
