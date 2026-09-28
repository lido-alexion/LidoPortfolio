<?php

namespace Tests\Feature\V8;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LidoTelemetryHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_telemetry_disabled_does_not_export_or_set_traceparent(): void
    {
        config(['lido_telemetry.enabled' => false]);

        $response = $this->getJson('/api/build-info');

        $response->assertOk();
        $this->assertFalse($response->headers->has('traceparent'));
    }

    public function test_telemetry_enabled_exports_fail_open_and_sets_traceparent(): void
    {
        Http::fake();
        config([
            'lido_telemetry.enabled' => true,
            'lido_telemetry.otlp_traces_endpoint' => 'http://collector.test/v1/traces',
        ]);

        $response = $this->withHeaders([
            'traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
        ])->getJson('/api/build-info');

        $response->assertOk();
        $response->assertHeader('traceparent');
        Http::assertSentCount(1);
    }

    public function test_business_telemetry_omits_sensitive_attributes(): void
    {
        Http::fake();
        config([
            'lido_telemetry.enabled' => true,
            'lido_telemetry.otlp_traces_endpoint' => 'http://collector.test/v1/traces',
        ]);

        app(\App\Telemetry\LidoTelemetry::class)->recordBusinessEvent('stox.test.event', [
            'password' => 'secret',
            'stock_id' => 42,
        ]);

        Http::assertSent(function ($request) {
            $body = json_encode($request->data());
            $this->assertStringNotContainsString('secret', (string) $body);

            return str_contains((string) $body, 'stock_id');
        });
    }

    public function test_business_telemetry_continues_incoming_trace_context(): void
    {
        Http::fake();
        config([
            'lido_telemetry.enabled' => true,
            'lido_telemetry.otlp_traces_endpoint' => 'http://collector.test/v1/traces',
        ]);

        $request = \Illuminate\Http\Request::create('/api/test', 'GET');
        $request->headers->set('traceparent', '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01');
        app()->instance('request', $request);

        app(\App\Telemetry\LidoTelemetry::class)->recordBusinessEvent('stox.test.trace');

        Http::assertSent(function ($httpRequest): bool {
            $payload = $httpRequest->data();

            return data_get($payload, 'resourceSpans.0.scopeSpans.0.spans.0.traceId') === '4bf92f3577b34da6a3ce929d0e0e4736';
        });
    }
}
