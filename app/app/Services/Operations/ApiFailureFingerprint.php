<?php

namespace App\Services\Operations;

use Illuminate\Support\Str;

class ApiFailureFingerprint
{
    public function make(array $observation): string
    {
        $canonical = [
            'environment' => $observation['environment'] ?? app()->environment(),
            'direction' => $observation['direction'] ?? 'unknown',
            'component' => $observation['component'] ?? 'unknown',
            'method' => strtoupper((string) ($observation['method'] ?? '')),
            'endpoint' => $this->normalizeEndpoint((string) ($observation['endpoint'] ?? '')),
            'status_or_failure' => $observation['http_status'] ?? ($observation['failure_class'] ?? 'unknown'),
            'category' => $observation['error_category'] ?? 'unknown',
        ];
        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function normalizeEndpoint(string $endpoint): string
    {
        $endpoint = preg_replace('/\?.*$/', '', trim($endpoint)) ?? trim($endpoint);
        $endpoint = preg_replace('/\b[0-9a-f]{8}-[0-9a-f-]{27,}\b/i', '{id}', $endpoint) ?? $endpoint;
        $endpoint = preg_replace('/\b\d+\b/', '{id}', $endpoint) ?? $endpoint;
        $endpoint = preg_replace('/\b[A-Z]{2,}[A-Z0-9._-]*\.(?:NS|BO)\b/i', '{symbol}', $endpoint) ?? $endpoint;
        return Str::limit($endpoint ?: '/', 255, '…');
    }
}
