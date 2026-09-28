<?php

return [
    'enabled' => (bool) env('STOXLA_ML_LIFECYCLE_ENABLED', false),
    'timezone' => env('STOXLA_ML_LIFECYCLE_TIMEZONE', 'Asia/Kolkata'),
    'horizons' => [
        '1m' => [
            'schedule' => env('STOXLA_ML_SCHEDULE_1M', 'monthly_first_sunday_02:00'),
            'enabled' => (bool) env('STOXLA_ML_SCHEDULE_1M_ENABLED', false),
        ],
        '3m' => [
            'schedule' => env('STOXLA_ML_SCHEDULE_3M', 'monthly_first_sunday_03:00'),
            'enabled' => (bool) env('STOXLA_ML_SCHEDULE_3M_ENABLED', false),
        ],
        '6m' => [
            'schedule' => env('STOXLA_ML_SCHEDULE_6M', 'monthly_first_sunday_04:00'),
            'enabled' => (bool) env('STOXLA_ML_SCHEDULE_6M_ENABLED', false),
        ],
    ],
    'sse' => [
        'poll_interval_seconds' => (int) env('STOXLA_ML_SSE_POLL_SECONDS', 2),
        'max_polls' => (int) env('STOXLA_ML_SSE_MAX_POLLS', 90),
    ],
    'retention' => [
        'enabled' => (bool) env('STOXLA_ML_RETENTION_ENABLED', false),
        'max_retained_per_horizon' => (int) env('STOXLA_ML_RETENTION_MAX_RETAINED', 3),
    ],
    'notifications' => [
        'enabled' => (bool) env('STOXLA_ML_LIFECYCLE_NOTIFICATIONS_ENABLED', true),
    ],
    'retry' => [
        'max_attempts' => max(1, (int) env('STOXLA_ML_RETRY_MAX_ATTEMPTS', 3)),
        'backoff_seconds' => [60, 300, 900],
    ],
    'drift_trigger' => [
        'enabled' => (bool) env('STOXLA_ML_DRIFT_TRIGGER_ENABLED', false),
        'window_months' => (int) env('STOXLA_ML_DRIFT_TRIGGER_WINDOW_MONTHS', 3),
        'max_check_age_hours' => (int) env('STOXLA_ML_DRIFT_TRIGGER_MAX_CHECK_AGE_HOURS', 168),
        'cooldown_hours' => (int) env('STOXLA_ML_DRIFT_TRIGGER_COOLDOWN_HOURS', 168),
        'require_status' => 'warning',
        'warnings' => [
            'live_hit_rate_deterioration',
            'live_benchmark_return_deterioration',
            'score_distribution_outside_expected_band',
        ],
    ],
];
