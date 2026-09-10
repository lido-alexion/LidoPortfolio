<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Analytics\AccountPerformanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountPerformanceController extends Controller
{
    public function __construct(private AccountPerformanceService $performance) {}

    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['required', 'date', 'before:to'],
            'to' => ['required', 'date', 'after:from', 'before_or_equal:today'],
            'portfolio_ids' => ['sometimes', 'array'],
            'portfolio_ids.*' => ['integer', 'distinct'],
        ]);

        return response()->json(['data' => $this->performance->calculate(
            $request->user(), $validated['from'], $validated['to'],
            array_key_exists('portfolio_ids', $validated) ? $validated['portfolio_ids'] : null,
        )]);
    }
}
