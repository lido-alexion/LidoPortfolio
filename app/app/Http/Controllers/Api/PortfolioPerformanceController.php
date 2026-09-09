<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Analytics\PortfolioPerformanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PortfolioPerformanceController extends Controller
{
    public function __construct(private PortfolioPerformanceService $performance) {}

    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['required', 'date', 'before:to'],
            'to' => ['required', 'date', 'after:from', 'before_or_equal:today'],
        ]);

        return response()->json(['data' => $this->performance->calculate(
            \activePortfolio(), $validated['from'], $validated['to'],
        )]);
    }
}
