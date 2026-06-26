<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PortfolioSnapshotRebuildService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PortfolioHistoryController extends Controller
{
    public function __construct(
        protected PortfolioSnapshotRebuildService $rebuild,
    ) {}

    public function rebuild(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from_date' => ['nullable', 'date', 'before_or_equal:today'],
            'to_date' => ['nullable', 'date', 'before_or_equal:today'],
        ]);

        $profile = \activePortfolio();
        $to = isset($validated['to_date'])
            ? Carbon::parse($validated['to_date'])->startOfDay()
            : now()->startOfDay();

        if (isset($validated['from_date'])) {
            $from = Carbon::parse($validated['from_date'])->startOfDay();
            $result = $this->rebuild->rebuildDateRange($profile, $from, $to);
        } else {
            $earliest = $profile->transactions()->min('transaction_date');
            $from = $earliest
                ? Carbon::parse($earliest)->startOfDay()
                : $to->copy();
            $result = $this->rebuild->rebuildFromDate($profile, $from);
        }

        return response()->json([
            'message' => 'Portfolio history rebuilt',
            'rebuild' => $result,
        ]);
    }
}
