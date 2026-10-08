<?php

namespace Tests\Feature\V8;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LidoTelemetryRelayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'lido_telemetry.enabled' => true,
            'lido_telemetry.browser_relay_upstream' => 'http://collector.test/v1/traces',
        ]);
        Http::preventStrayRequests();
    }

    public function test_disabled_telemetry_returns_empty_no_content_without_forwarding(): void
    {
        config(['lido_telemetry.enabled' => false]);
        Http::fake();

        $this->postJson('/api/telemetry/otlp/v1/traces', $this->payload())
            ->assertNoContent();
        Http::assertNothingSent();
    }

    public static function upstreamStatuses(): array
    {
        return [
            [200, 202], [202, 202], [204, 202], [299, 202],
            [400, 400], [413, 400], [415, 400], [422, 400],
            [301, 503], [307, 503], [401, 503], [403, 503], [404, 503],
            [408, 503], [429, 503], [500, 503], [502, 503], [503, 503], [504, 503],
        ];
    }

    #[DataProvider('upstreamStatuses')]
    public function test_acknowledgement_reflects_upstream_without_exposing_details(int $upstream, int $expected): void
    {
        Log::spy();
        $payload = $this->payload();
        Http::fake(fn () => Http::response('private upstream response', $upstream, [
            'Location' => 'http://untrusted.test/traces',
            'X-Private' => 'private header',
        ]));

        $response = $this->postJson('/api/telemetry/otlp/v1/traces?upstream=http://untrusted.test', $payload);

        $response->assertStatus($expected)->assertContent('');
        $response->assertHeaderMissing('Location')->assertHeaderMissing('X-Private');
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'http://collector.test/v1/traces'
            && json_decode($request->body(), true) === $payload);
        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('warning');
    }

    public static function transportErrors(): array
    {
        return [[new ConnectionException('private timeout detail')], [new \RuntimeException('private transport detail')]];
    }

    #[DataProvider('transportErrors')]
    public function test_transport_errors_are_empty_retryable_responses(\Throwable $error): void
    {
        Log::spy();
        Http::fake(function () use ($error) {
            throw $error;
        });

        $this->postJson('/api/telemetry/otlp/v1/traces', $this->payload())
            ->assertStatus(503)->assertContent('');
        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('warning');
    }

    public static function timeouts(): array
    {
        return [[2.0, 2.0], [0.0, 0.1], [-1.0, 0.1], [100.0, 5.0]];
    }

    #[DataProvider('timeouts')]
    public function test_relay_has_bounded_timeout_and_disables_redirects(float $configured, float $expected): void
    {
        config(['lido_telemetry.browser_relay_timeout_seconds' => $configured]);
        Http::fake(function ($request, $options) use ($expected) {
            $this->assertSame($expected, $options['timeout']);
            $this->assertSame($expected, $options['connect_timeout']);
            $this->assertFalse($options['allow_redirects']);

            return Http::response('', 200);
        });

        $this->postJson('/api/telemetry/otlp/v1/traces', $this->payload())->assertStatus(202);
        $this->assertSame(0.15, config('lido_telemetry.export_timeout_seconds'));
    }

    public function test_local_validation_does_not_forward_rejected_content(): void
    {
        Http::fake();
        config(['lido_telemetry.browser_relay_max_bytes' => 1024]);
        $this->call('POST', '/api/telemetry/otlp/v1/traces', [], [], [], [
            'CONTENT_TYPE' => 'text/plain',
        ], '{}')->assertStatus(415)->assertContent('');
        $this->call('POST', '/api/telemetry/otlp/v1/traces', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], '{')->assertStatus(422)->assertContent('');
        $this->postJson('/api/telemetry/otlp/v1/traces', ['resourceSpans' => []])->assertStatus(422);
        $this->postJson('/api/telemetry/otlp/v1/traces', ['resourceSpans' => [str_repeat('x', 1025)]])->assertStatus(413);
        Http::assertNothingSent();
    }

    public function test_business_and_http_operations_remain_fail_open_on_transport_failure(): void
    {
        config([
            'lido_telemetry.enabled' => true,
            'lido_telemetry.official_sdk_enabled' => false,
            'lido_telemetry.otlp_traces_endpoint' => 'http://collector.test/v1/traces',
            'lido_telemetry.otlp_metrics_endpoint' => 'http://collector.test/v1/metrics',
        ]);
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('private transport detail');
        });

        app(\App\Telemetry\LidoTelemetry::class)->recordBusinessEvent('stox.test.event');
        $this->getJson('/api/build-info')->assertOk();
        $this->assertGreaterThanOrEqual(2, $attempts);
    }

    private function payload(): array
    {
        return ['resourceSpans' => [['scopeSpans' => [['spans' => array_map(fn ($id) => [
            'traceId' => str_pad((string) $id, 32, '0', STR_PAD_LEFT),
            'spanId' => str_pad((string) $id, 16, '0', STR_PAD_LEFT),
            'name' => 'HTTP GET',
            'kind' => 3,
            'startTimeUnixNano' => '1790948940000000000',
            'endTimeUnixNano' => '1790948940100000000',
        ], [1, 2, 3])]]]]];
    }
}
