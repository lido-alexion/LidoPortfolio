<?php

namespace Tests\Feature\V8;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LidoTelemetryPropagationProbeJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void {}
}

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
            'lido_telemetry.otlp_metrics_endpoint' => 'http://collector.test/v1/metrics',
        ]);

        $response = $this->withHeaders([
            'traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
        ])->getJson('/api/build-info');

        $response->assertOk();
        $response->assertHeader('traceparent');
        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => isset($request->data()['resourceMetrics']));
    }

    public function test_official_sdk_mode_does_not_emit_duplicate_custom_server_span(): void
    {
        if (! extension_loaded('opentelemetry')) {
            $this->markTestSkipped('Requires the optional OpenTelemetry PHP extension.');
        }

        Http::fake();
        config([
            'lido_telemetry.enabled' => true,
            'lido_telemetry.official_sdk_enabled' => true,
            'lido_telemetry.otlp_traces_endpoint' => 'http://collector.test/v1/traces',
            'lido_telemetry.otlp_metrics_endpoint' => 'http://collector.test/v1/metrics',
        ]);

        $response = $this->getJson('/api/build-info');

        $response->assertOk();
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => isset($request->data()['resourceMetrics']));
    }

    public function test_effective_context_uses_the_active_official_span_when_requested(): void
    {
        $span = \OpenTelemetry\API\Trace\Span::wrap(
            \OpenTelemetry\API\Trace\SpanContext::create(
                '4bf92f3577b34da6a3ce929d0e0e4736',
                '00f067aa0ba902b7',
                \OpenTelemetry\API\Trace\TraceFlags::SAMPLED,
            ),
        );
        $scope = $span->activate();

        try {
            $context = \App\Telemetry\TraceContext::effective(true);
            $this->assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $context?->traceId);
            $this->assertSame('00f067aa0ba902b7', $context?->spanId);
        } finally {
            $scope->detach();
        }
    }

    public function test_custom_http_fallback_omits_raw_path_and_exception_message(): void
    {
        Http::fake();
        config([
            'lido_telemetry.enabled' => true,
            'lido_telemetry.official_sdk_enabled' => false,
            'lido_telemetry.otlp_traces_endpoint' => 'http://collector.test/v1/traces',
            'lido_telemetry.otlp_metrics_endpoint' => 'http://collector.test/v1/metrics',
        ]);

        $marker = 'synthetic-private-marker';
        $request = \\Illuminate\\Http\\Request::create('/api/'.$marker, 'GET');
        app(\\App\\Telemetry\\LidoTelemetry::class)->recordHttpRequest(
            $request, 500, 1.0, new \\RuntimeException($marker)
        );

        Http::assertSent(function ($outbound) use ($marker): bool {
            if (! isset($outbound->data()['resourceSpans'])) {
                return false;
            }

            $body = json_encode($outbound->data());
            $this->assertStringNotContainsString($marker, (string) $body);
            $this->assertStringNotContainsString('exception.message', (string) $body);
            $this->assertStringContainsString('exception.type', (string) $body);

            return true;
        });
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

    public function test_browser_otlp_relay_forwards_only_bounded_json_to_fixed_collector(): void
    {
        Http::fake();
        config([
            'lido_telemetry.enabled' => true,
            'lido_telemetry.browser_relay_upstream' => 'http://127.0.0.1:4318/v1/traces',
        ]);

        $payload = ['resourceSpans' => [['scopeSpans' => [['spans' => [['name' => 'browser.fetch']]]]]]];
        $response = $this->withHeaders(['Content-Type' => 'application/json'])
            ->postJson('/api/telemetry/otlp/v1/traces', $payload);

        $response->assertStatus(202);
        Http::assertSent(fn ($request): bool =>
            $request->url() === 'http://127.0.0.1:4318/v1/traces'
            && data_get(json_decode($request->body(), true), 'resourceSpans.0.scopeSpans.0.spans.0.name') === 'browser.fetch'
        );
    }

    public function test_browser_otlp_relay_rejects_non_json_and_malformed_payloads(): void
    {
        $this->withHeaders(['Content-Type' => 'text/plain'])
            ->call('POST', '/api/telemetry/otlp/v1/traces', [], [], [], [], 'not-json')
            ->assertStatus(415);

        $this->postJson('/api/telemetry/otlp/v1/traces', ['metrics' => []])
            ->assertStatus(422);
    }

    public function test_sync_queue_payload_and_processing_preserve_causal_trace_context(): void
    {
        Http::fake();
        config([
            'lido_telemetry.enabled' => true,
            'lido_telemetry.otlp_traces_endpoint' => 'http://collector.test/v1/traces',
        ]);

        $incoming = \App\Telemetry\TraceContext::fromRequest(
            '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'
        );
        \App\Telemetry\TraceContext::activate($incoming);

        Queue::connection('sync')->push(new LidoTelemetryPropagationProbeJob);

        $this->assertNull(\App\Telemetry\TraceContext::active());
        Http::assertSent(function ($request): bool {
            $payload = $request->data();
            $spans = data_get($payload, 'resourceSpans.0.scopeSpans.0.spans', []);

            return collect($spans)->contains(fn (array $span): bool =>
                ($span['traceId'] ?? null) === '4bf92f3577b34da6a3ce929d0e0e4736'
                && data_get($span, 'attributes.0.value.stringValue') !== null
            );
        });
    }
}
