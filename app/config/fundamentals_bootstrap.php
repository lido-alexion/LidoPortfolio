<?php

return [
    // Official adapters stay off until source access and PIT handling are accepted.
    'nse_official_direct_enabled' => (bool) env('FUNDAMENTALS_NSE_OFFICIAL_DIRECT_ENABLED', false),
    // Website automation requires explicit authorization as well as the feature flag.
    'nse_official_direct_access_authorized' => (bool) env('FUNDAMENTALS_NSE_OFFICIAL_DIRECT_ACCESS_AUTHORIZED', false),
    // Optional bridge for an approved normalized feed; blank selects no network route by default.
    'nse_official_feed_url' => env('FUNDAMENTALS_NSE_OFFICIAL_FEED_URL'),
    'nse_official_timeout_seconds' => (float) env('FUNDAMENTALS_NSE_OFFICIAL_TIMEOUT_SECONDS', 30),
    'nse_official_max_documents' => (int) env('FUNDAMENTALS_NSE_OFFICIAL_MAX_DOCUMENTS', 4),
    'exchange_min_gap_ms' => max(5000, (int) env('FUNDAMENTALS_EXCHANGE_MIN_GAP_MS', 5000)),
    'exchange_daily_request_cap' => max(1, min(200, (int) env('FUNDAMENTALS_EXCHANGE_DAILY_REQUEST_CAP', 200))),
    'exchange_block_cooldown_seconds' => max(3600, (int) env('FUNDAMENTALS_EXCHANGE_BLOCK_COOLDOWN_SECONDS', 21600)),
    'bse_official_feed_url' => env('FUNDAMENTALS_BSE_OFFICIAL_FEED_URL'),
    'bse_official_timeout_seconds' => (float) env('FUNDAMENTALS_BSE_OFFICIAL_TIMEOUT_SECONDS', 30),
    // Additional HTTPS hostnames approved for operator-managed normalized feeds.
    'approved_feed_hosts' => array_values(array_filter(array_map(
        'strtolower',
        array_map('trim', explode(',', (string) env('FUNDAMENTALS_APPROVED_FEED_HOSTS', 'nseindia.com,bseindia.com'))),
    ))),
];
