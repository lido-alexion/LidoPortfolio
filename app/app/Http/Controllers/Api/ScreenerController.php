<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Screener;
use App\Models\ScreenerRun;
use App\Services\Screener\ScreenerBacktestService;
use App\Services\Screener\ScreenerCatalog;
use App\Services\Screener\ScreenerRunService;
use App\Services\Screener\ScreenerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScreenerController extends Controller
{
    public function __construct(
        protected ScreenerService $screeners,
        protected ScreenerRunService $runs,
        protected ScreenerBacktestService $backtests,
    ) {}

    public function meta(): JsonResponse
    {
        return response()->json(['data' => $this->screeners->meta()]);
    }

    public function index(): JsonResponse
    {
        $list = $this->screeners->listForProfile(\activePortfolio());

        return response()->json([
            'data' => $list->values(),
            'count' => $list->count(),
        ]);
    }

    public function shared(): JsonResponse
    {
        $list = $this->screeners->listSharedForProfile(\activePortfolio());

        return response()->json([
            'data' => $list->values(),
            'count' => $list->count(),
        ]);
    }

    public function importShared(int $sourceId): JsonResponse
    {
        $data = $this->screeners->importShared(\activePortfolio(), $sourceId);

        return response()->json(['data' => $data], 201);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->screeners->create(\activePortfolio(), $request->all());

        return response()->json(['data' => $data], 201);
    }

    public function show(Screener $screener): JsonResponse
    {
        $screener->load(['watchlist:id,name']);

        return response()->json(['data' => $this->screeners->format($screener)]);
    }

    public function update(Request $request, Screener $screener): JsonResponse
    {
        $data = $this->screeners->update($screener, $request->all());

        return response()->json(['data' => $data]);
    }

    public function destroy(Screener $screener): JsonResponse
    {
        $this->screeners->delete($screener);

        return response()->json(['message' => 'Screener deleted.']);
    }

    public function run(Screener $screener): JsonResponse
    {
        // Holdings/watchlist: finish in one go. Universe: first chunk then client continues.
        if ($screener->scope === 'all_equities') {
            $result = $this->runs->start($screener, 'manual');
        } else {
            $run = $this->runs->runToCompletion($screener, 'manual', 20);
            $result = [
                'run' => $this->runs->formatRun($run, true),
                'continued' => false,
                'completed' => $run->status === 'completed',
            ];
        }

        return response()->json([
            'data' => $result['run'],
            'continued' => $result['continued'],
            'completed' => $result['completed'],
        ]);
    }

    public function runs(Screener $screener): JsonResponse
    {
        $query = ScreenerRun::query()->where('screener_id', $screener->id);
        $total = (clone $query)->count();
        $runs = (clone $query)
            ->orderByDesc('id')
            ->limit(ScreenerCatalog::RUN_HISTORY_UI_LIMIT)
            ->get()
            ->map(fn (ScreenerRun $r) => $this->runs->formatRun($r));

        return response()->json([
            'data' => $runs->values(),
            'count' => $runs->count(),
            'total' => $total,
            'limit' => ScreenerCatalog::RUN_HISTORY_UI_LIMIT,
        ]);
    }

    public function compareRuns(Screener $screener): JsonResponse
    {
        return response()->json([
            'data' => $this->runs->compareMatrix($screener),
        ]);
    }

    public function clearRuns(Screener $screener): JsonResponse
    {
        $deleted = $this->runs->clearRuns($screener);
        $backtestDaysCleared = $this->backtests->clearResults($screener);

        return response()->json([
            'message' => 'Run history cleared.',
            'deleted' => $deleted,
            'backtest_days_cleared' => $backtestDaysCleared,
        ]);
    }
}
