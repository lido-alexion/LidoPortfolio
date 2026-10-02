<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'data_quality' => [
        // Official NSE public schema: symbol, subject, exDate, recDate, isin.
        // The adapter validates and normalizes this response before queuing
        // any reviewable data-quality issue; it never repairs accounting data.
        'corporate_actions_feed_url' => env('CORPORATE_ACTIONS_FEED_URL', 'https://www.nseindia.com/api/corporates-corporateActions?index=equities'),
        'corporate_actions_feed_supports_window' => (bool) env('CORPORATE_ACTIONS_FEED_SUPPORTS_WINDOW', false),
        'corporate_actions_overlap_days' => (int) env('CORPORATE_ACTIONS_OVERLAP_DAYS', 7),
        'auto_accept_days' => (int) env('DATA_QUALITY_AUTO_ACCEPT_DAYS', 15),
    ],

];
