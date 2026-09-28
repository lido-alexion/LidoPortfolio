<?php

namespace App\Http\Middleware;

use App\Telemetry\LidoTelemetry;
use App\Telemetry\TraceContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class LidoTelemetryHttpMiddleware
{
    public function __construct(
        protected LidoTelemetry $telemetry,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->telemetry->enabled()) {
            return $next($request);
        }

        $incoming = TraceContext::fromRequest($request->headers->get('traceparent'));
        $request->attributes->set('lido_trace_context', $incoming);
        $started = microtime(true);
        $exception = null;
        $response = null;

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            $exception = $e;
            throw $e;
        } finally {
            $durationMs = (microtime(true) - $started) * 1000;
            $status = $response instanceof Response ? $response->getStatusCode() : 500;
            $this->telemetry->recordHttpRequest($request, $status, $durationMs, $exception);
        }

        if ($response instanceof Response) {
            $outgoing = $incoming->childSpan();
            $response->headers->set('traceparent', $outgoing->traceparent());
        }

        return $response;
    }
}
