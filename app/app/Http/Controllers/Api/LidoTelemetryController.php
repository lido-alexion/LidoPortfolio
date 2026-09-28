<?php

namespace App\Http\Controllers\Api;

use App\Engines\Support\ApiEnvelope;
use App\Http\Controllers\Controller;
use App\Telemetry\LidoTelemetry;
use App\Telemetry\LidoTelemetryCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
}
