<?php

return [
    'enabled' => (bool) env('STOXLA_INTRADAY_PLATFORM_ENABLED', false),
    'corpus_root' => env('STOXLA_INTRADAY_CORPUS_ROOT', base_path('../shared/intraday/corpus')),
    'checkpoint_store' => env('STOXLA_INTRADAY_CHECKPOINT_STORE', 'database'),
    'universe' => 'nifty500',
    'target_years' => 8,
    'bar_interval' => '1m',
    'storage_format' => 'parquet',
    'internal_token' => env('STOXLA_INTRADAY_BACKFILL_INTERNAL_TOKEN'),
];
