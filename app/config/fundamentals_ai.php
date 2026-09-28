<?php

return [
    'enabled' => (bool) env('FUNDAMENTALS_AI_INSIGHTS_ENABLED', false),
    'prompt_version' => env('FUNDAMENTALS_AI_PROMPT_VERSION', 'fundamental-signals-v1'),
    'limits' => [
        'daily_global_max' => (int) env('FUNDAMENTALS_AI_DAILY_GLOBAL_MAX', 500),
        'daily_per_user_max' => (int) env('FUNDAMENTALS_AI_DAILY_PER_USER_MAX', 50),
    ],
    'primary_provider' => env('FUNDAMENTALS_AI_PRIMARY_PROVIDER', 'gemini'),
    'secondary_provider' => env('FUNDAMENTALS_AI_SECONDARY_PROVIDER', 'codex'),
    'timeout_seconds' => (float) env('FUNDAMENTALS_AI_TIMEOUT_SECONDS', 30),
    'gemini' => [
        'api_key' => env('FUNDAMENTALS_AI_GEMINI_API_KEY'),
        'model' => env('FUNDAMENTALS_AI_GEMINI_MODEL', 'gemini-2.0-flash'),
    ],
    'codex' => [
        'api_key' => env('FUNDAMENTALS_AI_CODEX_API_KEY'),
        'base_url' => env('FUNDAMENTALS_AI_CODEX_BASE_URL', 'https://api.openai.com/v1'),
        'model' => env('FUNDAMENTALS_AI_CODEX_MODEL', 'gpt-4o-mini'),
    ],
    /*
     * USD per 1M tokens for spend estimates (support/cost analysis; not billing truth).
     * Keys: exact model id, else provider slug (gemini|codex).
     */
    'estimated_cost_per_million_tokens' => [
        'gemini-2.0-flash' => ['input' => 0.10, 'output' => 0.40],
        'gpt-4o-mini' => ['input' => 0.15, 'output' => 0.60],
        'gemini' => ['input' => 0.10, 'output' => 0.40],
        'codex' => ['input' => 0.15, 'output' => 0.60],
    ],
];
