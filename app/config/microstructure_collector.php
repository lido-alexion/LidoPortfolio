<?php

return [
    'enabled' => filter_var(env('MICROSTRUCTURE_COLLECTOR_ENABLED', false), FILTER_VALIDATE_BOOL),

    // Shared secret for the VPS collector process (Bearer or X-Microstructure-Collector-Token).
    'internal_token' => env('MICROSTRUCTURE_COLLECTOR_INTERNAL_TOKEN'),

    // Portfolio user whose Kite session feeds the collector (typically the VPS operator account).
    'kite_user_id' => env('MICROSTRUCTURE_KITE_USER_ID') !== null
        ? (int) env('MICROSTRUCTURE_KITE_USER_ID')
        : null,

    'schema_version' => env('MICROSTRUCTURE_SCHEMA_VERSION', 'schema_v1'),

    'data_root' => env('MICROSTRUCTURE_DATA_ROOT', storage_path('app/microstructure')),

    'heartbeat_file' => env('MICROSTRUCTURE_HEARTBEAT_FILE', storage_path('app/microstructure/collector-heartbeat.json')),

    'command_file' => env('MICROSTRUCTURE_COMMAND_FILE', storage_path('app/microstructure/collector-command.json')),

    'backup_root' => env('MICROSTRUCTURE_BACKUP_ROOT', storage_path('app/microstructure-backup')),

    'raw_tick_spool_max_mb' => (int) env('MICROSTRUCTURE_RAW_SPOOL_MAX_MB', 512),

    'raw_tick_spool_max_age_minutes' => max(5, (int) env('MICROSTRUCTURE_RAW_SPOOL_MAX_AGE_MINUTES', 60)),

    'disk_free_warning_gb' => (float) env('MICROSTRUCTURE_DISK_FREE_WARNING_GB', 5),

    'market_timezone' => env('MICROSTRUCTURE_MARKET_TIMEZONE', 'Asia/Kolkata'),

    'heartbeat_stale_minutes' => max(2, (int) env('MICROSTRUCTURE_HEARTBEAT_STALE_MINUTES', 5)),

    // FEAT-063 §11 — operator Telegram reminders when Kite session is missing (Asia/Kolkata).
    'kite_auth_reminder_time' => env('MICROSTRUCTURE_KITE_AUTH_REMINDER_TIME', '09:00'),
    'kite_packet_alert_time' => env('MICROSTRUCTURE_KITE_PACKET_ALERT_TIME', '09:20'),
    'market_session_start' => env('MICROSTRUCTURE_MARKET_SESSION_START', '09:15'),
    'market_session_end' => env('MICROSTRUCTURE_MARKET_SESSION_END', '15:30'),

    'coverage_warning_percent' => max(0, min(100, (float) env('MICROSTRUCTURE_COVERAGE_WARNING_PERCENT', 90))),
];
