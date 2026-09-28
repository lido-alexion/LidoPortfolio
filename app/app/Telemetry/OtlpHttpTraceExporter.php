<?php

namespace App\Telemetry;

use Illuminate\Support\Facades\Http;
use Throwable;

final class OtlpHttpTraceExporter
{
    /**
     * @param  array<string, mixed>  $span
     */
    public function export(array $span): void
    {
        $endpoint = config('lido_telemetry.otlp_traces_endpoint');
        if (! is_string($endpoint) || trim($endpoint) === '') {
            return;
        }

        $payload = [
            'resourceSpans' => [[
                'resource' => [
                    'attributes' => [
                        ['key' => 'service.name', 'value' => ['stringValue' => (string) config('lido_telemetry.service_name')]],
                        ['key' => 'deployment.environment', 'value' => ['stringValue' => (string) config('lido_telemetry.environment')]],
                        ['key' => 'service.version', 'value' => ['stringValue' => (string) config('lido_telemetry.service_version')]],
                    ],
                ],
                'scopeSpans' => [[
                    'scope' => ['name' => 'stox-laravel'],
                    'spans' => [$span],
                ]],
            ]],
        ];

        try {
            Http::timeout((float) config('lido_telemetry.export_timeout_seconds', 0.15))
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($endpoint, $payload);
        } catch (Throwable) {
            // Fail-open (052-07).
        }
    }
}
