<?php

return [
    'tour_version' => env('GUIDED_TOUR_VERSION', 'investor-core-v1'),
    'max_auto_prompts' => (int) env('GUIDED_TOUR_MAX_AUTO_PROMPTS', 3),
    'target_wait_ms' => (int) env('GUIDED_TOUR_TARGET_WAIT_MS', 4000),
];
