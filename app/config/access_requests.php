<?php

return [
    'verification_expiry_hours' => (int) env('ACCESS_REQUEST_VERIFICATION_EXPIRY_HOURS', 24),
    'ignore_cooldown_hours' => (int) env('ACCESS_REQUEST_IGNORE_COOLDOWN_HOURS', 72),
    'verification_retention_days' => (int) env('ACCESS_REQUEST_VERIFICATION_RETENTION_DAYS', 14),
    'captcha' => [
        'driver' => env('ACCESS_REQUEST_CAPTCHA_DRIVER', env('APP_ENV') === 'testing' ? 'testing' : 'turnstile'),
        'turnstile' => [
            'site_key' => env('TURNSTILE_SITE_KEY'),
            'secret_key' => env('TURNSTILE_SECRET_KEY'),
        ],
    ],
];
