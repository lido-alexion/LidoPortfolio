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
        $endpoint = (string) ($context['endpoint'] ?? '');
        $endpointPolicies = config('api_failure_reporting.expected_endpoint_statuses', []);
        $endpointExpected = is_array($endpointPolicies) ? ($endpointPolicies[$endpoint] ?? []) : [];
        if (is_string($endpointExpected)) $endpointExpected = array_filter(array_map('intval', explode(',', $endpointExpected)));
        $expected = array_merge((array) $expected, (array) $endpointExpected);
        if ($status !== null && in_array($status, array_map('intval', $expected), true)) return false;
        return true;
    }
}
