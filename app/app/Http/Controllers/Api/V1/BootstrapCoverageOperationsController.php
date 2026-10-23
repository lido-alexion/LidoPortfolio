<?php

namespace App\Http\Controllers\Api\V1;

use App\Engines\Support\ApiEnvelope;
use App\Http\Controllers\Controller;
use App\Models\V7\FundamentalBootstrapRun;
use App\Services\Fundamentals\BootstrapCoverageOperationsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Bind only inside the existing admin middleware group. */
class BootstrapCoverageOperationsController extends Controller
{
    public function summary(BootstrapCoverageOperationsService $service): JsonResponse
    {
        return ApiEnvelope::success($service->summary());
    }

    public function gaps(Request $request, BootstrapCoverageOperationsService $service): JsonResponse
    {
        $filters = $request->validate(['state' => ['sometimes', 'in:actionable,reopened,accepted,resolved'],
            'reason' => ['sometimes', 'string', 'max:48'], 'metric' => ['sometimes', 'string', 'max:64'],
            'cadence' => ['sometimes', 'in:quarterly,annual'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        return ApiEnvelope::success($service->gaps($filters));
    }

    public function warnings(Request $request, BootstrapCoverageOperationsService $service): JsonResponse
    {
        $filters = $request->validate(['state' => ['sometimes', 'in:unresolved,acknowledged'],
            'category' => ['sometimes', 'string', 'max:48'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        return ApiEnvelope::success($service->warnings($filters));
    }

    public function reconcile(Request $request, BootstrapCoverageOperationsService $service): JsonResponse
    {
        $data = $request->validate(['run_id' => ['required', 'integer', 'min:1', 'exists:stox_fundamental_bootstrap_runs,id']]);
        return ApiEnvelope::success($service->reconcile(FundamentalBootstrapRun::query()->findOrFail($data['run_id'])));
    }

    public function acknowledgeWarnings(Request $request, BootstrapCoverageOperationsService $service): JsonResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:100'], 'ids.*' => ['integer', 'distinct'], 'note' => ['nullable', 'string', 'max:1000']]);
        return ApiEnvelope::success(['acknowledged' => $service->acknowledgeWarnings($data['ids'], (int) $request->user()->id, isset($data['note']) ? trim($data['note']) : null)]);
    }

    public function acknowledgeGaps(Request $request, BootstrapCoverageOperationsService $service): JsonResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:100'], 'ids.*' => ['integer', 'distinct'], 'note' => ['nullable', 'string', 'max:1000']]);
        return ApiEnvelope::success(['acknowledged' => $service->acknowledgeGaps($data['ids'], (int) $request->user()->id, isset($data['note']) ? trim($data['note']) : null)]);
    }
}
