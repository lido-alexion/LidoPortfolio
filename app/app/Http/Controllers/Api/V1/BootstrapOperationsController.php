<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\V7\FundamentalBootstrapRun;
use App\Services\Fundamentals\BootstrapOperationsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BootstrapOperationsController extends Controller
{
    public function __construct(private BootstrapOperationsService $operations) {}

    public function health(\App\Services\Fundamentals\BootstrapOperationsHealthService $health): JsonResponse
    {
        return response()->json(['data' => $health->summary()]);
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->operations->history($request->validate([
            'status' => ['sometimes', 'string', 'max:32'], 'trigger' => ['sometimes', 'string', 'max:32'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ])));
    }

    public function show(FundamentalBootstrapRun $run): JsonResponse
    {
        return response()->json(['data' => $this->operations->detail($run)]);
    }

    public function launch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scope' => ['required', Rule::in(['all', 'stocks'])],
            'stock_ids' => ['required_if:scope,stocks', 'array', 'min:1', 'max:500'],
            'stock_ids.*' => ['integer', 'distinct', 'exists:portfolio_stocks,id'],
            'force' => ['sometimes', 'boolean'], 'reason' => ['required_if:force,true', 'string', 'min:3', 'max:2000'],
        ]);
        $key = $request->header('Idempotency-Key');
        abort_unless(is_string($key) && preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $key), 422, 'A valid Idempotency-Key header is required.');
        $result = $this->operations->launch($data, (int) $request->user()->id, (string) ($request->user()->name ?? 'Admin'), $key);
        return response()->json(['data' => $this->operations->presentRun($result->run)], $result->created ? 201 : 200);
    }

    public function fromGaps(Request $request): JsonResponse
    {
        $data = $request->validate(['gap_ids' => ['required', 'array', 'min:1', 'max:100'], 'gap_ids.*' => ['integer', 'distinct', 'min:1']]);
        $key = $request->header('Idempotency-Key');
        abort_unless(is_string($key), 422, 'A valid Idempotency-Key header is required.');
        $result = $this->operations->launchFromGaps($data['gap_ids'], (int) $request->user()->id, (string) $request->user()->name, $key);
        return response()->json(['data' => $this->operations->presentRun($result->run)], $result->created ? 201 : 200);
    }

    public function retry(Request $request, FundamentalBootstrapRun $run): JsonResponse
    {
        $key = $request->header('Idempotency-Key');
        abort_unless(is_string($key) && preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $key), 422, 'A valid Idempotency-Key header is required.');
        $created = $this->operations->retryFailed($run, (int) $request->user()->id, (string) ($request->user()->name ?? 'Admin'), $key);
        return response()->json(['data' => $this->operations->presentRun($created->run)], $created->created ? 201 : 200);
    }

    public function cancel(Request $request, FundamentalBootstrapRun $run): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']]);
        $result = app(\App\Services\Fundamentals\FundamentalBootstrapService::class)->cancelRun(
            $run, (string) ($request->user()->name ?? 'admin:'.$request->user()->id), $data['reason'],
        );
        if (($result['skipped_reason'] ?? null) === 'another_bootstrap_slice_is_in_progress') {
            return response()->json(['error' => [
                'code' => 'bootstrap_cancellation_busy',
                'message' => 'Another bootstrap slice is active; retry cancellation after it finishes.',
            ]], 409);
        }
        return response()->json(['data' => ['id' => $run->id, 'status' => $result['status'] ?? null, 'cancelled_jobs' => $result['summary_json']['cancellation']['cancelled_jobs'] ?? 0]]);
    }
}
