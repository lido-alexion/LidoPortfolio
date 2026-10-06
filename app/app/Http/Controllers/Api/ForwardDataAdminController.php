<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ForwardCollectionWork;
use App\Services\ForwardDataHealthService;
use App\Services\ForwardDataPlanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ForwardDataAdminController extends Controller
{
    public function health(ForwardDataHealthService $health, ForwardDataPlanner $planner): JsonResponse
    {
        return response()->json(['data' => ['paused' => $planner->isPaused(), 'health' => $health->report()]]);
    }

    public function work(Request $request): JsonResponse
    {
        $query = ForwardCollectionWork::query()->orderByDesc('session_date')->orderBy('id');
        if ($request->filled('dataset')) $query->where('dataset_key', (string) $request->string('dataset'));
        if ($request->filled('state')) $query->where('state', (string) $request->string('state'));
        $perPage = min(100, max(1, (int) $request->input('per_page', 25)));
        $page = $query->paginate($perPage);
        $page->through(static function (ForwardCollectionWork $work): array {
            $data = $work->toArray();
            $data['session_date'] = $work->session_date?->toDateString();

            return $data;
        });

        return response()->json(['data' => $page]);
    }

    public function dispatch(ForwardDataPlanner $planner): JsonResponse
    {
        return response()->json(['data' => $planner->plan()]);
    }

    public function retry(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:100'], 'ids.*' => ['integer', 'distinct']]);
        $updated = ForwardCollectionWork::query()
            ->whereIn('id', $data['ids'])
            ->whereIn('state', ['retry_wait', 'waiting_publication', 'exhausted', 'blocked_quality'])
            ->update(['state' => 'pending', 'next_attempt_at' => now(), 'last_error' => null, 'last_error_code' => null]);

        return response()->json(['data' => [
            'requested' => count($data['ids']),
            'updated' => $updated,
            'idempotent' => $updated === 0,
        ]]);
    }

    public function pause(Request $request, ForwardDataPlanner $planner): JsonResponse
    {
        return response()->json(['data' => $planner->setPaused(true, $request->user()?->id)]);
    }

    public function resume(Request $request, ForwardDataPlanner $planner): JsonResponse
    {
        return response()->json(['data' => $planner->setPaused(false, $request->user()?->id)]);
    }
}