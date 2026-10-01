<?php

return [
    'enabled' => (bool) env('STOX_GITHUB_ISSUES_ENABLED', false),
    'repository' => env('STOX_GITHUB_REPOSITORY', 'lido-alexion/LidoPortfolio'),
    'token' => env('STOX_GITHUB_TOKEN'),
    'environments' => array_values(array_filter(array_map('trim', explode(',', (string) env('STOX_GITHUB_AUTO_ISSUE_ENVIRONMENTS', 'production'))))),
    'max_new_per_hour' => max(1, (int) env('STOX_GITHUB_AUTO_ISSUE_MAX_NEW_PER_HOUR', 10)),
    'comment_cooldown_hours' => max(1, (int) env('STOX_GITHUB_AUTO_ISSUE_COMMENT_COOLDOWN_HOURS', 6)),
    'recurrence_cooldown_hours' => max(1, (int) env('STOX_GITHUB_AUTO_ISSUE_RECURRENCE_COOLDOWN_HOURS', 24)),
    'retention_days' => max(1, (int) env('STOX_GITHUB_INCIDENT_RETENTION_DAYS', 90)),
    'expected_statuses' => (array) env('STOX_API_FAILURE_EXPECTED_STATUSES', []),
];
