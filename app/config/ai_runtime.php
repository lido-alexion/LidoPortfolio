<?php

return [
    'global_max_concurrency' => (int) env('STOX_AI_GLOBAL_MAX_CONCURRENCY', 16),
    'base_url' => env('STOX_AI_RUNTIME_URL', 'http://127.0.0.1:8090'),
    'shared_secret' => env('STOX_AI_RUNTIME_SHARED_SECRET'),
    'timeout_seconds' => (float) env('STOX_AI_RUNTIME_TIMEOUT_SECONDS', 20),
    'stream_timeout_seconds' => (float) env('STOX_AI_RUNTIME_STREAM_TIMEOUT_SECONDS', 120),
    'enabled' => (bool) env('STOX_AI_RUNTIME_ENABLED', false),
];
