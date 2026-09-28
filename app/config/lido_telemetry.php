<?php

return [
    'enabled' => (bool) env('LIDO_TELEMETRY_ENABLED', false),
    'environment' => env('LIDO_TELEMETRY_ENVIRONMENT', env('APP_ENV', 'local')),
    'service_name' => env('LIDO_TELEMETRY_SERVICE_NAME', 'stox'),
    'service_version' => env('LIDO_TELEMETRY_SERVICE_VERSION', 'v8'),
    'otlp_traces_endpoint' => env('LIDO_TELEMETRY_OTLP_TRACES_ENDPOINT'),
    'otlp_metrics_endpoint' => env('LIDO_TELEMETRY_OTLP_METRICS_ENDPOINT'),
    'export_timeout_seconds' => (float) env('LIDO_TELEMETRY_EXPORT_TIMEOUT', 0.15),
    'pseudonymous_user_salt' => env('LIDO_TELEMETRY_USER_SALT', env('APP_KEY', 'stox')),
];
