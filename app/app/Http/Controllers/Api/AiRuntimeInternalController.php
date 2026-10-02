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
    public function reserve(Request $request, \App\Services\AI\AiBudgetReservationService $budgets): JsonResponse
    {
        $data = $request->validate(['id' => ['required', 'uuid'], 'request_id' => ['required', 'uuid'], 'capability_id' => ['required', 'string', 'max:160'], 'path_id' => ['required', 'string', 'max:160'], 'user_id' => ['nullable', 'integer', 'min:1'], 'provider' => ['required', 'string', 'max:120'], 'model' => ['required', 'string', 'max:180'], 'input_token_bound' => ['required', 'integer', 'min:1', 'max:2000000']]);
        return response()->json(['success' => true, 'data' => $budgets->reserve($data)]);
    }

    public function settle(Request $request, \App\Services\AI\AiBudgetReservationService $budgets): JsonResponse
    {
        $data = $request->validate(['id' => ['required', 'uuid'], 'usage' => ['nullable', 'array:input_tokens,output_tokens,estimated_cost'], 'usage.input_tokens' => ['required_with:usage', 'integer', 'min:0'], 'usage.output_tokens' => ['required_with:usage', 'integer', 'min:0'], 'usage.estimated_cost' => ['nullable', 'numeric', 'min:0']]);
        return response()->json(['success' => true, 'data' => $budgets->settle($data['id'], $data['usage'] ?? null)]);
    }

    public function event(Request $request, AiInferenceAuditService $audit): JsonResponse
    {
        $data = $request->validate(['request_id' => ['required','uuid'], 'capability' => ['required','string','max:160'], 'status' => ['required','string','max:40'], 'trace_id' => ['nullable','string','max:160'], 'routing_trace' => ['array'], 'usage' => ['array'], 'budget_scopes' => ['array'], 'selected_path' => ['array'], 'prompt' => ['array'], 'context' => ['array'], 'provenance' => ['array'], 'error' => ['array'], 'latency_ms' => ['nullable','numeric']]);
        $event = $audit->record($data);
        return response()->json(['success' => true, 'data' => ['event_id' => $event->id]]);
    }
}
