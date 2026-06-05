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

        $user = $request->user();
        $to = isset($validated['to_date'])
            ? Carbon::parse($validated['to_date'])->startOfDay()
            : now()->startOfDay();

        if (isset($validated['from_date'])) {
            $from = Carbon::parse($validated['from_date'])->startOfDay();
            $result = $this->rebuild->rebuildDateRange($user, $from, $to);
        } else {
            $earliest = $user->transactions()->min('transaction_date');
            $from = $earliest
                ? Carbon::parse($earliest)->startOfDay()
                : $to->copy();
            $result = $this->rebuild->rebuildFromDate($user, $from);
        }

        return response()->json([
            'message' => 'Portfolio history rebuilt',
            'rebuild' => $result,
        ]);
    }
}
