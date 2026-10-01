<?php

namespace App\Services\Operations;

class ApiFailurePolicy
{
    public function reportable(?int $status, ?string $failureClass = null, array $context = []): bool
    {
        if (($context['skip_reporting'] ?? false) === true) return false;
        if ($status !== null && $status >= 200 && $status < 300) return false;
        $expected = config('api_failure_reporting.expected_statuses', []);
        if (is_string($expected)) $expected = array_filter(array_map('intval', explode(',', $expected)));
        if ($status !== null && in_array($status, array_map('intval', $expected), true)) return false;
        return true;
    }
}
