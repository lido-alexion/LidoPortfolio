<?php

return [
    /*
    | Per-user Zerodha/Kite Connect (V4-FEAT-001). App key/secret live in env;
    | each Lido user authorizes their own Kite account. Access tokens are
    | encrypted at rest and expire around 06:00 IST (Kite session).
    */
    'kite' => [
        'api_key' => env('KITE_API_KEY'),
        'api_secret' => env('KITE_API_SECRET'),
        'redirect_url' => env('KITE_REDIRECT_URL'),
        'login_url' => 'https://kite.trade/connect/login',
        'api_base' => 'https://api.kite.trade',
        'cancel_retry_cooldown_seconds' => max(1, (int) env('KITE_CANCEL_RETRY_COOLDOWN_SECONDS', 60)),
    ],
    'kite_funds' => [
        // Both remain off until real Console evidence validates payout behavior and operators approve rollout.
        'auto_adjustments_enabled' => filter_var(env('KITE_FUNDS_AUTO_ADJUSTMENTS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'payout_semantics_verified' => filter_var(env('KITE_FUNDS_PAYOUT_SEMANTICS_VERIFIED', false), FILTER_VALIDATE_BOOLEAN),
    ],
];
