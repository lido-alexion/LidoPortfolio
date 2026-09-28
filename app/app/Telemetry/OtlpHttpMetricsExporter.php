<?php

namespace App\Telemetry;

use Illuminate\Support\Facades\Http;
use Throwable;

/** Fail-open OTLP/HTTP metrics exporter for the bounded StoX metric set. */
final class OtlpHttpMetricsExporter
{
    /**
     * @param  array<string, string|int|bool|null>  $attributes
     */
    public function export(string $name, float $value, array $attributes = [], string $unit = '1'): void
    {
        $endpoint = config('lido_telemetry.otlp_metrics_endpoint');
        if (! is_string($endpoint) || trim($endpoint) === '') {
            return;
        }

        $metricAttributes = [];
        foreach ($attributes as $key => $attribute) {
            if ($attribute === null || str_contains(strtolower((string) $key), 'id') && str_contains(strtolower((string) $key), 'user')) {
                continue;
            }
            $metricAttributes[] = match (true) {
                is_int($attribute) => ['key' => (string) $key, 'value' => ['intValue' => (string) $attribute]],
                is_bool($attribute) => ['key' => (string) $key, 'value' => ['boolValue' => $attribute]],
                default => ['key' => (string) $key, 'value' => ['stringValue' => substr((string) $attribute, 0, 128)]],
            };
        }

        $now = (int) (microtime(true) * 1_000_000_000);
        $payload = [
            'resourceMetrics' => [[
                'resource' => [
                    'attributes' => [
                        ['key' => 'service.name', 'value' => ['stringValue' => (string) config('lido_telemetry.service_name')]],
                        ['key' => 'deployment.environment', 'value' => ['stringValue' => (string) config('lido_telemetry.environment')]],
                        ['key' => 'service.version', 'value' => ['stringValue' => (string) config('lido_telemetry.service_version')]],
                    ],
                ],
                'scopeMetrics' => [[
                    'scope' => ['name' => 'stox-laravel'],
                    'metrics' => [[
                        'name' => $name,
                        'unit' => $unit,
                        'sum' => [
                            'dataPoints' => [[
                                'asDouble' => $value,
                                'timeUnixNano' => (string) $now,
                                'attributes' => $metricAttributes,
                            ]],
                            'aggregationTemporality' => 2,
                            'isMonotonic' => false,
                        ],
                    ]],
                ]],
            ]],
        ];

        try {
            Http::timeout((float) config('lido_telemetry.export_timeout_seconds', 0.15))
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($endpoint, $payload);
        } catch (Throwable) {
            // Telemetry must never block or break StoX business operations.
        }
    }
}
