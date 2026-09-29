<?php

namespace App\Telemetry;

use App\Models\User;
use Illuminate\Http\Request;
use Throwable;

class LidoTelemetry
{
    public function __construct(
        protected OtlpHttpTraceExporter $exporter,
        protected OtlpHttpMetricsExporter $metricsExporter,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('lido_telemetry.enabled', false);
    }

    public function officialSdkInstrumentationActive(): bool
    {
        return TraceContext::officialSdkActive();
    }

    public function pseudonymousUserId(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }

        $salt = (string) config('lido_telemetry.pseudonymous_user_salt', 'stox');

        return hash('sha256', $salt.'|'.$user->id);
    }

    public function recordHttpRequest(Request $request, int $status, float $durationMs, ?Throwable $exception = null): void
    {
        if (! $this->enabled()) {
            return;
        }

        $context = TraceContext::effective($this->officialSdkInstrumentationActive())
            ?? TraceContext::fromRequest($request->headers->get('traceparent'));
        $span = $context->childSpan();
        $now = (int) (microtime(true) * 1_000_000_000);
        $start = $now - (int) max(0, $durationMs * 1_000_000);

        $attributes = [
            ['key' => 'http.method', 'value' => ['stringValue' => $request->method()]],
            ['key' => 'http.route', 'value' => ['stringValue' => (string) $request->path()]],
            ['key' => 'http.status_code', 'value' => ['intValue' => (string) $status]],
        ];

        $user = $request->user();
        $pseudo = $this->pseudonymousUserId($user instanceof User ? $user : null);
        if ($pseudo !== null) {
            $attributes[] = ['key' => 'stox.user.pseudo_id', 'value' => ['stringValue' => $pseudo]];
        }

        $spanPayload = [
            'traceId' => $span->traceId,
            'spanId' => $span->spanId,
            'name' => LidoTelemetryCatalog::HTTP_SERVER,
            'kind' => 2,
            'startTimeUnixNano' => (string) $start,
            'endTimeUnixNano' => (string) $now,
            'attributes' => $attributes,
            'status' => [
                'code' => $exception !== null || $status >= 500 ? 2 : 1,
            ],
        ];

        if ($exception !== null) {
            $spanPayload['events'] = [[
                'name' => 'exception',
                'timeUnixNano' => (string) $now,
                'attributes' => [
                    ['key' => 'exception.type', 'value' => ['stringValue' => $exception::class]],
                    ['key' => 'exception.message', 'value' => ['stringValue' => substr($exception->getMessage(), 0, 500)]],
                ],
            ]];
        }

        // The official Laravel instrumentation owns the technical server
        // span when explicitly enabled. Keep the custom metric and business
        // telemetry paths, but do not emit a competing duplicate span.
        if (! $this->officialSdkInstrumentationActive()) {
            $this->exporter->export($spanPayload);
        }
        $this->metricsExporter->export('stox.http.server.duration', $durationMs, [
            'http.method' => $request->method(),
            'http.status_class' => (string) intdiv($status, 100).'xx',
        ], 'ms');
    }

    /**
     * @param  array<string, string|int|bool|null>  $attributes
     */
    public function recordBusinessEvent(string $name, array $attributes = []): void
    {
        if (! $this->enabled()) {
            return;
        }

        $context = TraceContext::effective($this->officialSdkInstrumentationActive())
            ?? (app()->bound('request')
                ? TraceContext::fromRequest(request()->headers->get('traceparent'))
                : TraceContext::freshRoot());
        $span = $context->childSpan();
        $now = (int) (microtime(true) * 1_000_000_000);
        $otlpAttributes = [];
        foreach ($attributes as $key => $value) {
            if ($value === null || $this->isSensitiveAttribute($key)) {
                continue;
            }
            $otlpAttributes[] = match (true) {
                is_int($value) => ['key' => $key, 'value' => ['intValue' => (string) $value]],
                is_bool($value) => ['key' => $key, 'value' => ['boolValue' => $value]],
                default => ['key' => $key, 'value' => ['stringValue' => substr((string) $value, 0, 256)]],
            };
        }

        $this->exporter->export([
            'traceId' => $context->traceId,
            'spanId' => $span->spanId,
            'parentSpanId' => $context->spanId,
            'name' => $name,
            'kind' => 1,
            'startTimeUnixNano' => (string) $now,
            'endTimeUnixNano' => (string) ($now + 1_000_000),
            'attributes' => $otlpAttributes,
            'status' => ['code' => 1],
        ]);
    }

    protected function isSensitiveAttribute(string $key): bool
    {
        $lower = strtolower($key);

        return str_contains($lower, 'password')
            || str_contains($lower, 'token')
            || str_contains($lower, 'secret')
            || str_contains($lower, 'authorization');
    }
}
