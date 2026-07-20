<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ScreenerRun;
use App\Services\Screener\ScreenerRunService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScreenerRunController extends Controller
{
    public function __construct(
        protected ScreenerRunService $runs,
    ) {}

    public function show(Request $request, ScreenerRun $screenerRun): JsonResponse
    {
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(200, max(10, (int) $request->query('per_page', 100)));

        return response()->json([
            'data' => $this->runs->formatRun($screenerRun, true, $page, $perPage),
        ]);
    }

    public function continue(ScreenerRun $screenerRun): JsonResponse
    {
        $result = $this->runs->continue($screenerRun);

        return response()->json([
            'data' => $result['run'],
            'continued' => $result['continued'],
            'completed' => $result['completed'],
        ]);
    }
}
