<?php

return [
    'python' => env('STOXLA_ML_PYTHON', '/var/www/stoxla/shared/python/ml/bin/python'),
    'adapter_script' => env('STOXLA_ML_ADAPTER', base_path('scripts/ml_adapter.py')),
    'model_directory' => env('STOXLA_ML_MODEL_DIRECTORY', base_path('../shared/ml/models')),
    'timeout_seconds' => (float) env('STOXLA_ML_TIMEOUT_SECONDS', 180),
    'max_output_bytes' => (int) env('STOXLA_ML_MAX_OUTPUT_BYTES', 8 * 1024 * 1024),
    'artifact_format' => 'joblib',
    'artifact_version' => 'v7-logistic-1',
    'benchmark_mapping' => [
        'default' => env('STOXLA_ML_DEFAULT_BENCHMARK', 'NIFTY50'),
        'sector_overrides' => [],
    ],
    'drift' => [
        'windows_months' => [3, 6, 12],
        'minimum_predictions' => 30,
        'model_age_warning_days' => 365,
        'minimum_matured_predictions' => 30,
    ],
];
