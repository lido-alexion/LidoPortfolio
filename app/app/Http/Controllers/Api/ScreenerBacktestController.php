<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Screener;
use App\Models\ScreenerBacktest;
use App\Services\Screener\ScreenerBacktestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScreenerBacktestController extends Controller
{
    public function __construct(
        protected ScreenerBacktestService $backtests,
    ) {}

    public function start(Request $request, Screener $screener): JsonResponse
    {
        $result = $this->backtests->start(
            $screener,
            (string) $request->input('range', '1y'),
            (string) $request->input('session_token', ''),
        );

        return response()->json([
            'data' => $result['backtest'],
            'continued' => $result['continued'],
            'completed' => $result['completed'],
        ]);
    }

    public function continue(ScreenerBacktest $screenerBacktest): JsonResponse
    {
        $result = $this->backtests->continue($screenerBacktest);

        return response()->json([
            'data' => $result['backtest'],
            'continued' => $result['continued'],
            'completed' => $result['completed'],
        ]);
    }

    public function matrix(ScreenerBacktest $screenerBacktest): JsonResponse
    {
        return response()->json([
            'data' => $this->backtests->matrix($screenerBacktest),
        ]);
    }

    public function discardSession(string $token): JsonResponse
    {
        $deleted = $this->backtests->discardSession($token);

        return response()->json([
            'message' => 'Backtest session discarded.',
            'deleted' => $deleted,
        ]);
    }
}
