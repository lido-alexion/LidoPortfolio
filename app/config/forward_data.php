<?php

return [
    'enabled' => (bool) env('STOX_FORWARD_DATA_ENABLED', true),
    'timezone' => env('STOX_FORWARD_DATA_TIMEZONE', 'Asia/Kolkata'),
    // Deliberately empty by default: production must seed this from the
    // audited handoff/recovery floor, never infer it from today's coverage.
    'start_date' => env('STOX_FORWARD_DATA_START_DATE'),
    'publication_grace_hours' => (int) env('STOX_FORWARD_DATA_PUBLICATION_GRACE_HOURS', 21),
    'max_attempts' => (int) env('STOX_FORWARD_DATA_MAX_ATTEMPTS', 8),
    'lease_minutes' => (int) env('STOX_FORWARD_DATA_LEASE_MINUTES', 20),
    'batch' => (int) env('STOX_FORWARD_DATA_BATCH', 20),
    'sector_source' => env('STOX_FORWARD_DATA_SECTOR_SOURCE', 'nse_mii_security_file'),
];
