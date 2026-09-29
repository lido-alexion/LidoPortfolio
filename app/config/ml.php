<?php

return [
    'historical_universe' => [
        'archive_path' => env('STOXLA_ML_HISTORICAL_UNIVERSE_ARCHIVE', ''),
        'mii_path' => env('STOXLA_ML_NSE_MII_PATH', ''),
        'bhavcopy_path' => env('STOXLA_ML_NSE_BHAVCOPY_PATH', ''),
        'source' => env('STOXLA_ML_HISTORICAL_UNIVERSE_SOURCE', 'configured_authoritative_archive'),
    ],
    'python' => env('STOXLA_ML_PYTHON', '/var/www/stoxla/shared/python/ml/bin/python'),
    'adapter_script' => env('STOXLA_ML_ADAPTER', base_path('scripts/ml_adapter.py')),
    'model_directory' => env('STOXLA_ML_MODEL_DIRECTORY', base_path('../shared/ml/models')),
    'timeout_seconds' => (float) env('STOXLA_ML_TIMEOUT_SECONDS', 180),
    'max_output_bytes' => (int) env('STOXLA_ML_MAX_OUTPUT_BYTES', 8 * 1024 * 1024),
    // Retraining includes streamed dataset construction, baseline evaluation and Python fitting.
    'retrain_lock_seconds' => (int) env('STOXLA_ML_RETRAIN_LOCK_SECONDS', 14400),
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
    'challenger_promotion' => [
        'enabled' => (bool) env('STOXLA_ML_CHALLENGER_CANDIDATES_ENABLED', true),
        'min_roc_auc_delta_vs_logistic' => (float) env('STOXLA_ML_CHALLENGER_MIN_ROC_AUC_DELTA', 0.01),
    ],
];
