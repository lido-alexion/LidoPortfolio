<?php

return [
    'enabled' => (bool) env('STOX_LOG_AI_TRIAGE_ENABLED', env('STOX_LOG_ERROR_TRIAGE_ENABLED', false)),
    'environments' => array_values(array_filter(array_map('trim', explode(',', (string) env('STOX_LOG_ERROR_TRIAGE_ENVIRONMENTS', 'production'))))),
    'confidence_threshold' => env('STOX_LOG_AI_TRIAGE_CODE_BUG_CONFIDENCE', env('STOX_LOG_ERROR_TRIAGE_CONFIDENCE', 0.85)),
    'debounce_seconds' => max(1, (int) env('STOX_LOG_AI_TRIAGE_DEBOUNCE_SECONDS', (int) env('STOX_LOG_ERROR_TRIAGE_DEBOUNCE_MINUTES', 5) * 60)),
    'decision_ttl_seconds' => max(1, (int) env('STOX_LOG_AI_TRIAGE_DECISION_TTL_SECONDS', 21600)),
    'queue_connection' => env('STOX_LOG_AI_TRIAGE_QUEUE_CONNECTION', 'log-triage'),
    'queue' => env('STOX_LOG_AI_TRIAGE_QUEUE', 'log-triage'),
    'build_sha' => env('STOX_DEPLOY_SHA', env('APP_BUILD_SHA', '')),
    'ignored_exceptions' => [\Illuminate\Validation\ValidationException::class, \Illuminate\Auth\AuthenticationException::class],
    'ignored_messages' => [],
    'ignored_routes' => ['up', 'api/health', 'api/ops/api-failures', 'api/telemetry/otlp/v1/traces', 'api/telemetry/route-view', 'api/logs/frontend', 'api/internal/v1/ai-runtime/configuration', 'api/internal/v1/ai-runtime/reservations', 'api/internal/v1/ai-runtime/inference-events', 'api/internal/v1/ai-runtime/settlements'],
    'retention_days' => (int) env('STOX_LOG_ERROR_TRIAGE_RETENTION_DAYS', 90),
];
