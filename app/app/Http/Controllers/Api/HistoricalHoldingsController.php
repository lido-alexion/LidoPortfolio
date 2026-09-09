<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\HistoricalHoldingsService;
use App\Services\PortfolioDateComparisonService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HistoricalHoldingsController extends Controller
{
    public function __construct(
        protected HistoricalHoldingsService $historicalHoldings,
        protected PortfolioDateComparisonService $comparison,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'as_of' => ['required', 'date', 'date_format:Y-m-d', 'before_or_equal:today'],
        ]);

        $profile = \activePortfolio();
        $payload = $this->historicalHoldings->asOf($profile, $validated['as_of']);

        return response()->json($payload);
    }

    public function compare(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date_a' => ['required', 'date_format:Y-m-d', 'before:date_b', 'before_or_equal:today'],
            'date_b' => ['required', 'date_format:Y-m-d', 'after:date_a', 'before_or_equal:today'],
        ]);

        return response()->json([
            'data' => $this->comparison->compare(\activePortfolio(), $validated['date_a'], $validated['date_b']),
        ]);
    }
}
