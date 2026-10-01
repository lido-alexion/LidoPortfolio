<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AI\AiInferenceAuditService;
use App\Services\AI\AiPlatformConfigurationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiRuntimeInternalController extends Controller
{
    public function configuration(AiPlatformConfigurationService $configuration): JsonResponse { return response()->json(['success' => true, 'data' => $configuration->projection()]); }
    public function event(Request $request, AiInferenceAuditService $audit): JsonResponse
    {
        $data = $request->validate(['request_id' => ['required','uuid'], 'capability' => ['required','string','max:160'], 'status' => ['required','string','max:40'], 'trace_id' => ['nullable','string','max:160'], 'routing_trace' => ['array'], 'usage' => ['array'], 'budget_scopes' => ['array'], 'selected_path' => ['array'], 'prompt' => ['array'], 'context' => ['array'], 'provenance' => ['array'], 'error' => ['array'], 'latency_ms' => ['nullable','numeric']]);
        $event = $audit->record($data);
        return response()->json(['success' => true, 'data' => ['event_id' => $event->id]]);
    }
}
