<?php

return [
    'enabled' => (bool) env('STOX_FORWARD_DATA_ENABLED', true),
    'timezone' => env('STOX_FORWARD_DATA_TIMEZONE', 'Asia/Kolkata'),
    // Deliberately empty by default: production must seed this from the
    // audited handoff/recovery floor, never infer it from today's coverage.
    'start_date' => env('STOX_FORWARD_DATA_START_DATE'),
    'publication_grace_hours' => (int) env('STOX_FORWARD_DATA_PUBLICATION_GRACE_HOURS', 21),
    'acquisition_eligible_hour' => (int) env('STOX_FORWARD_DATA_ACQUISITION_ELIGIBLE_HOUR', 18),
    'acquisition_eligible_minute' => (int) env('STOX_FORWARD_DATA_ACQUISITION_ELIGIBLE_MINUTE', 0),
    'max_attempts' => (int) env('STOX_FORWARD_DATA_MAX_ATTEMPTS', 8),
    'lease_minutes' => (int) env('STOX_FORWARD_DATA_LEASE_MINUTES', 20),
    'batch' => (int) env('STOX_FORWARD_DATA_BATCH', 20),
    'sector_source' => env('STOX_FORWARD_DATA_SECTOR_SOURCE', 'nse_mii_security_file'),
    // The only remote forward source is the official NSE archive host. The
    // existing downloader supplies descriptors, retries and transport headers;
    // the archive provider still performs extraction/date/parser/mapping gates.
    'official_source_enabled' => (bool) env('STOX_FORWARD_DATA_OFFICIAL_SOURCE_ENABLED', true),
    'official_source_base_url' => env('STOX_FORWARD_DATA_OFFICIAL_SOURCE_BASE_URL', 'https://nsearchives.nseindia.com'),
    'official_source_allowed_hosts' => ['nsearchives.nseindia.com'],
    'official_source_directory' => env('STOX_FORWARD_DATA_OFFICIAL_SOURCE_DIRECTORY', storage_path('app/private/forward-data/nse')),
];
