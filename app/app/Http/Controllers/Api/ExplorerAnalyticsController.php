<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ExploratoryAnalyticsService;
use App\Support\StockValidationUserMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExplorerAnalyticsController extends Controller
{
    public function __construct(protected ExploratoryAnalyticsService $explorer) {}

    public function analyze(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'symbol' => ['required', 'string', 'max:20'],
            'exchange' => ['nullable', 'string', 'in:NSE,BSE'],
            'benchmark_symbol' => ['nullable', 'string', 'max:20'],
            'periods' => ['nullable', 'array'],
            'periods.*' => ['integer', 'in:1,3,6,12'],
        ]);

        $periods = $validated['periods'] ?? [1, 3, 6, 12];
        $periods = array_values(array_unique(array_map('intval', $periods)));
        sort($periods);

        $result = $this->explorer->analyze(
            \activePortfolio(),
            $validated['symbol'],
            $validated['exchange'] ?? 'NSE',
            $validated['benchmark_symbol'] ?? 'NIFTY50',
            $periods,
        );

        if (! ($result['valid'] ?? false)) {
            $errors = $result['errors'] ?? [];
            $symbol = $validated['symbol'];
            $exchange = $validated['exchange'] ?? 'NSE';

            return response()->json([
                'message' => StockValidationUserMessage::fromErrors($errors, $symbol, $exchange),
                'errors' => StockValidationUserMessage::normalizeErrors($errors),
            ], 422);
        }

        return response()->json(['data' => $result]);
    }
}
