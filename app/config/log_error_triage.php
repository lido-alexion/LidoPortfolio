<?php

return [
    'enabled' => (bool) env('STOX_LOG_ERROR_TRIAGE_ENABLED', false),
    'environments' => array_values(array_filter(array_map('trim', explode(',', (string) env('STOX_LOG_ERROR_TRIAGE_ENVIRONMENTS', 'production'))))),
    'confidence_threshold' => (float) env('STOX_LOG_ERROR_TRIAGE_CONFIDENCE', 0.85),
    'debounce_minutes' => (int) env('STOX_LOG_ERROR_TRIAGE_DEBOUNCE_MINUTES', 5),
    'retention_days' => (int) env('STOX_LOG_ERROR_TRIAGE_RETENTION_DAYS', 90),
];
