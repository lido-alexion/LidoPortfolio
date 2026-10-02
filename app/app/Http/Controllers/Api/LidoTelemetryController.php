<?php

namespace App\Http\Controllers\Api;

use App\Engines\Support\ApiEnvelope;
use App\Http\Controllers\Controller;
use App\Telemetry\LidoTelemetry;
use App\Telemetry\LidoTelemetryCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Http;
use Throwable;

class LidoTelemetryController extends Controller
{
    public function routeView(Request $request, LidoTelemetry $telemetry): JsonResponse
    {
        $validated = $request->validate([
            'route' => ['required', 'string', 'max:512'],
            'wall_duration_ms' => ['required', 'integer', 'min:0', 'max:86400000'],
            'active_duration_ms' => ['required', 'integer', 'min:0', 'max:86400000'],
        ]);

        $telemetry->recordBusinessEvent(LidoTelemetryCatalog::BUSINESS_ROUTE_VIEW, [
            'route' => $validated['route'],
            'wall_duration_ms' => (int) $validated['wall_duration_ms'],
            'active_duration_ms' => (int) $validated['active_duration_ms'],
        ]);

        return ApiEnvelope::success(['recorded' => $telemetry->enabled()]);
    }

    public function relayTraces(Request $request): HttpResponse
    {
        $maxBytes = max(1024, (int) config('lido_telemetry.browser_relay_max_bytes', 262144));
        if ((int) $request->header('Content-Length', 0) > $maxBytes) {
            return response('', 413);
        }

        $contentType = strtolower((string) $request->header('Content-Type', ''));
        if (! str_starts_with($contentType, 'application/json')) {
            return response('', 415);
        }

        $raw = $request->getContent();
        if ($raw === '' || strlen($raw) > $maxBytes) {
            return response('', 413);
        }

        try {
            $payload = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return response('', 422);
        }

        if (! is_array($payload)
            || ! isset($payload['resourceSpans'])
            || ! is_array($payload['resourceSpans'])
            || $payload['resourceSpans'] === []) {
            return response('', 422);
        }

        // This dedicated telemetry request can wait longer than business exporters.
        $timeout = max(0.1, min(5.0, (float) config('lido_telemetry.browser_relay_timeout_seconds', 2.0)));

        try {
            $upstream = Http::timeout($timeout)
                ->connectTimeout($timeout)
                ->withoutRedirecting()
                ->withHeaders(['Content-Type' => 'application/json'])
                ->withBody($raw, 'application/json')
                ->post((string) config('lido_telemetry.browser_relay_upstream'));
        } catch (Throwable) {
            // Let the browser exporter retry without exposing transport details.
            return response('', 503);
        }

        if ($upstream->successful()) {
            return response('', 202);
        }

        // Retrying the same invalid payload cannot help. Other failures may be
        // temporary infrastructure/configuration issues, not browser input errors.
        if (in_array($upstream->status(), [400, 413, 415, 422], true)) {
            return response('', 400);
        }

        return response('', 503);
    }
}
