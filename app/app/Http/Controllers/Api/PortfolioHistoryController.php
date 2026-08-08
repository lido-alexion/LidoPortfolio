<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PortfolioSnapshot;
use App\Services\PortfolioSnapshotRebuildService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PortfolioHistoryController extends Controller
{
    public function __construct(
        protected PortfolioSnapshotRebuildService $rebuild,
    ) {}

    public function snapshots(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'before_or_equal:today'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:2000'],
        ]);

        $profile = \activePortfolio();
        $query = PortfolioSnapshot::query()
            ->where('profile_id', $profile->id)
            ->orderByDesc('snapshot_date');

        if (! empty($validated['from_date'])) {
            $query->where('snapshot_date', '>=', Carbon::parse($validated['from_date'])->toDateString());
        }
        if (! empty($validated['to_date'])) {
            $query->where('snapshot_date', '<=', Carbon::parse($validated['to_date'])->toDateString());
        }

        $limit = (int) ($validated['limit'] ?? 365);
        $rows = $query
            ->limit($limit)
            ->get(['snapshot_date', 'portfolio_value', 'invested_value'])
            ->sortBy('snapshot_date')
            ->values()
            ->map(fn (PortfolioSnapshot $row) => [
                'snapshot_date' => $row->snapshot_date->toDateString(),
                'portfolio_value' => (string) $row->portfolio_value,
                'invested_value' => (string) $row->invested_value,
            ]);

        $first = $rows->first();
        $last = $rows->last();

        return response()->json([
            'snapshots' => $rows,
            'meta' => [
                'count' => $rows->count(),
                'from_date' => is_array($first) ? $first['snapshot_date'] : null,
                'to_date' => is_array($last) ? $last['snapshot_date'] : null,
                'limit' => $limit,
            ],
        ]);
    }

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
