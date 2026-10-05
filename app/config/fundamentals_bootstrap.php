<?php

return [
    // Official adapters stay off until source access and PIT handling are accepted.
    'nse_official_direct_enabled' => (bool) env('FUNDAMENTALS_NSE_OFFICIAL_DIRECT_ENABLED', false),
    // Website automation requires explicit authorization as well as the feature flag.
    'nse_official_direct_access_authorized' => (bool) env('FUNDAMENTALS_NSE_OFFICIAL_DIRECT_ACCESS_AUTHORIZED', false),
    // Optional bridge for an approved normalized feed; blank selects no network route by default.
    'nse_official_feed_url' => env('FUNDAMENTALS_NSE_OFFICIAL_FEED_URL'),
    'nse_official_timeout_seconds' => (float) env('FUNDAMENTALS_NSE_OFFICIAL_TIMEOUT_SECONDS', 30),
    'nse_official_max_documents' => (int) env('FUNDAMENTALS_NSE_OFFICIAL_MAX_DOCUMENTS', 40),
    'bse_official_feed_url' => env('FUNDAMENTALS_BSE_OFFICIAL_FEED_URL'),
    'bse_official_timeout_seconds' => (float) env('FUNDAMENTALS_BSE_OFFICIAL_TIMEOUT_SECONDS', 30),
];
