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
    ],
];
