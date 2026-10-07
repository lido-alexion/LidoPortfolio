<?php

return [
    'state_dir' => env('VPS_HEALTH_STATE_DIR', '/var/lib/vps-health'),
    'recipient_cache_ttl_seconds' => 7 * 24 * 60 * 60,
];
