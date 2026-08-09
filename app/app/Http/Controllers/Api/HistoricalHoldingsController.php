<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\HistoricalHoldingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HistoricalHoldingsController extends Controller
{
    public function __construct(
        protected HistoricalHoldingsService $historicalHoldings,
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
}
