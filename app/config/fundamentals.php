<?php

return [
    'yahoo' => [
        'python' => env('STOXLA_FUNDAMENTALS_PYTHON', '/var/www/stoxla/shared/python/fundamentals/bin/python'),
        'adapter_script' => env('STOXLA_FUNDAMENTALS_ADAPTER', base_path('scripts/yahoo_fundamentals.py')),
        'timeout_seconds' => (float) env('STOXLA_FUNDAMENTALS_TIMEOUT_SECONDS', 45),
        'max_output_bytes' => (int) env('STOXLA_FUNDAMENTALS_MAX_OUTPUT_BYTES', 4 * 1024 * 1024),
        'yfinance_version' => '1.7.0',
    ],
];
