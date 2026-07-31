<?php

namespace App\Http\Controllers\Api\V1;

use App\Engines\Support\ApiEnvelope;
use App\Http\Controllers\Controller;
use App\Models\BacktestRun;
use App\Services\Backtest\BacktestSimulationEngine;
use App\Services\Screener\ScreenerCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BacktestController extends Controller
{
    public function __construct(
        protected BacktestSimulationEngine $engine,
    ) {}

    public function meta(): JsonResponse
    {
        return ApiEnvelope::success([
            'ranges' => ScreenerCatalog::BACKTEST_RANGES,
            'time_budget_seconds' => 20,
            'stages' => [
                BacktestRun::STAGE_PREPARING,
                BacktestRun::STAGE_SIMULATING_DAYS,
                BacktestRun::STAGE_GENERATING_STATISTICS,
                BacktestRun::STAGE_GENERATING_REPORT,
                BacktestRun::STAGE_COMPLETED,
                BacktestRun::STAGE_FAILED,
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $profile = \activePortfolio();
        $runs = BacktestRun::query()
            ->where('profile_id', $profile->id)
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (BacktestRun $run) => $this->engine->format($run))
            ->all();

        return ApiEnvelope::success(['runs' => $runs]);
    }

    public function store(Request $request): JsonResponse
    {
        $profile = \activePortfolio();
        $validated = $request->validate([
            'name' => 'nullable|string|max:191',
            'range_key' => 'nullable|string|max:16',
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date',
            'initial_capital' => 'nullable|numeric|min:1000',
            'notes' => 'nullable|string|max:10000',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:64',
            'session_token' => 'required|string|max:64',
            'strategy_version_id' => 'nullable|integer',
        ]);

        $result = $this->engine->start($profile, $validated);

        return ApiEnvelope::success($result);
    }

    public function show(int $id): JsonResponse
    {
        $run = $this->findOwned($id);

        return ApiEnvelope::success($this->engine->detail($run));
    }

    public function continue(int $id): JsonResponse
    {
        $run = $this->findOwned($id);
        $result = $this->engine->resume($run);

        return ApiEnvelope::success($result);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $run = $this->findOwned($id);
        $validated = $request->validate([
            'name' => 'nullable|string|max:191',
            'notes' => 'nullable|string|max:10000',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:64',
        ]);
        $run = $this->engine->updateMeta($run, $validated);

        return ApiEnvelope::success($this->engine->format($run));
    }

    public function destroy(int $id): JsonResponse
    {
        $run = $this->findOwned($id);
        $this->engine->delete($run);

        return ApiEnvelope::success(['deleted' => true, 'id' => $id]);
    }

    public function timeline(int $id): JsonResponse
    {
        $run = $this->findOwned($id);
        if ($run->status !== BacktestRun::STATUS_COMPLETED) {
            return ApiEnvelope::error('BACKTEST_NOT_READY', 'Timeline is available after the run completes.', 409);
        }

        return ApiEnvelope::success($this->engine->detail($run)['timeline']);
    }

    private function findOwned(int $id): BacktestRun
    {
        $profile = \activePortfolio();

        return BacktestRun::query()
            ->where('profile_id', $profile->id)
            ->where('id', $id)
            ->firstOrFail();
    }
}
