<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TradingStrategy;
use App\Services\Analytics\StrategyPerformanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StrategyPerformanceController extends Controller
{
    public function __construct(private StrategyPerformanceService $performance) {}

    public function show(Request $request, int $strategy): JsonResponse
    {
        $validated = $request->validate(['to' => ['required', 'date', 'before_or_equal:today']]);
        $row = TradingStrategy::query()
            ->where('profile_id', \activePortfolio()->id)->whereKey($strategy)->firstOrFail();

        return response()->json(['data' => $this->performance->calculate($row, $validated['to'])]);
    }
}
