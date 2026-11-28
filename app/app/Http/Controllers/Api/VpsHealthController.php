<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VpsHealthSample;
use App\Models\VpsHealthDiskHourlyRollup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VpsHealthController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'hours' => ['sometimes', 'integer', 'in:1,6,24,72,168,720'],
        ]);
        $hours = (int) ($validated['hours'] ?? 24);
        $now = now();
        $since = $now->copy()->subHours($hours);
        $currentHour = $now->copy()->startOfHour();
        $firstHour = $currentHour->copy()->subHours($hours - 1);
        $latest = VpsHealthSample::query()
            ->orderByDesc('sampled_at')
            ->first(['sampled_at', 'status', 'issues', 'metrics']);
        if ($hours <= 72) {
            $samples = VpsHealthSample::query()->where('sampled_at', '>=', $since)
                ->orderBy('sampled_at')->get(['sampled_at', 'status', 'issues', 'metrics']);
            $granularity = 'minute';
        } else {
            // Long history is a sparse series of hour buckets. Include at most
            // the current bucket plus the preceding hours - 1 buckets.
            $samples = VpsHealthDiskHourlyRollup::query()->whereBetween('hour_start', [$firstHour, $currentHour])
                ->orderBy('hour_start')->get(['sampled_at', 'metrics'])
                ->map(fn ($row) => ['sampled_at' => $row->sampled_at, 'status' => null, 'issues' => [], 'metrics' => $row->metrics]);
            $granularity = 'hour';
        }

        return response()->json([
            'data' => [
                'range_hours' => $hours,
                'history_granularity' => $granularity,
                'latest' => $latest,
                'sample_count' => $samples->count(),
                'samples' => $samples,
                'last_sample_age_seconds' => $latest?->sampled_at?->diffInSeconds(now()),
            ],
        ]);
    }
}
