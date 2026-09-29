<?php

return [
    'enabled' => (bool) env('LIDO_TELEMETRY_ENABLED', false),
    'environment' => env('LIDO_TELEMETRY_ENVIRONMENT', env('APP_ENV', 'local')),
    'service_name' => env('LIDO_TELEMETRY_SERVICE_NAME', 'stox'),
    'service_version' => env('LIDO_TELEMETRY_SERVICE_VERSION', 'v8'),
    'otlp_traces_endpoint' => env('LIDO_TELEMETRY_OTLP_TRACES_ENDPOINT'),
    'otlp_metrics_endpoint' => env('LIDO_TELEMETRY_OTLP_METRICS_ENDPOINT'),
    'browser_relay_upstream' => env('LIDO_TELEMETRY_BROWSER_RELAY_UPSTREAM', 'http://127.0.0.1:4318/v1/traces'),
    'browser_relay_max_bytes' => (int) env('LIDO_TELEMETRY_BROWSER_RELAY_MAX_BYTES', 262144),
    // The official PHP SDK/auto-instrumentation is opt-in and requires the
    // opentelemetry PHP extension plus standard OTEL_* exporter settings.
    'official_sdk_enabled' => (bool) env('LIDO_TELEMETRY_OFFICIAL_SDK_ENABLED', false),
    'otel_sdk_disabled' => (bool) env('OTEL_SDK_DISABLED', false),
    'export_timeout_seconds' => (float) env('LIDO_TELEMETRY_EXPORT_TIMEOUT', 0.15),
    'pseudonymous_user_salt' => env('LIDO_TELEMETRY_USER_SALT', env('APP_KEY', 'stox')),
];
