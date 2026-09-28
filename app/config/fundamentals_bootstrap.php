<?php

return [
    // Official exchange adapters (FEAT-054). Disabled until credentials/endpoints are configured.
    'nse_official_enabled' => (bool) env('FUNDAMENTALS_NSE_OFFICIAL_ENABLED', false),
    'nse_official_feed_url' => env('FUNDAMENTALS_NSE_OFFICIAL_FEED_URL'),
    'nse_official_timeout_seconds' => (float) env('FUNDAMENTALS_NSE_OFFICIAL_TIMEOUT_SECONDS', 30),
    'bse_official_enabled' => (bool) env('FUNDAMENTALS_BSE_OFFICIAL_ENABLED', false),
    'bse_official_feed_url' => env('FUNDAMENTALS_BSE_OFFICIAL_FEED_URL'),
    'bse_official_timeout_seconds' => (float) env('FUNDAMENTALS_BSE_OFFICIAL_TIMEOUT_SECONDS', 30),
];
