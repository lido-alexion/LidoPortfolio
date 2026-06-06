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
];
