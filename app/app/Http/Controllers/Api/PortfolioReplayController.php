<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PortfolioReplayRun;
use App\Services\Simulation\PortfolioReplayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PortfolioReplayController extends Controller
{
    public function __construct(private PortfolioReplayService $replays) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => PortfolioReplayRun::query()->where('profile_id', \activePortfolio()->id)->latest()->get()]);
    }

    public function readiness(Request $request): JsonResponse
    {
        $input = $this->validated($request);
        return response()->json(['data' => $this->replays->readiness(\activePortfolio(), $input)]);
    }

    public function store(Request $request): JsonResponse
    {
        $input = $this->validated($request);
        return response()->json(['data' => $this->replays->create(\activePortfolio(), $request->user()->id, $input)], 201);
    }

    public function show(int $replay): JsonResponse
    {
        return response()->json(['data' => $this->owned($replay)]);
    }

    public function cancel(int $replay): JsonResponse
    {
        return response()->json(['data' => $this->replays->cancel($this->owned($replay))]);
    }

    public function destroy(Request $request, int $replay): JsonResponse
    {
        $this->replays->delete($this->owned($replay), $request->user()->id);
        return response()->json(['message' => 'Replay deleted; audit tombstone retained.']);
    }

    private function owned(int $id): PortfolioReplayRun
    {
        return PortfolioReplayRun::query()->where('profile_id', \activePortfolio()->id)->findOrFail($id);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'starting_mode' => ['required', Rule::in(['new_simulated', 'historical_branch'])],
            'period_start' => ['required', 'date', 'before:period_end'],
            'period_end' => ['required', 'date', 'after:period_start', 'before_or_equal:today'],
            'starting_cash' => ['required_if:starting_mode,new_simulated', 'nullable', 'numeric', 'gt:0'],
            'price_method' => ['required', Rule::in(\App\Models\PortfolioProfile::SIMULATION_PRICE_METHODS)],
            'adverse_slippage_percent' => ['sometimes', 'numeric', 'between:0,100'],
        ]);
    }
}
