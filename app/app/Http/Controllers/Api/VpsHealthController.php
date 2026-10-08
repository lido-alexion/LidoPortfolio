<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VpsHealthSample;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VpsHealthController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'hours' => ['sometimes', 'integer', 'in:1,6,24,72'],
        ]);
        $hours = (int) ($validated['hours'] ?? 24);
        $since = now()->subHours($hours);
        $latest = VpsHealthSample::query()
            ->orderByDesc('sampled_at')
            ->first(['sampled_at', 'status', 'issues', 'metrics']);
        $samples = VpsHealthSample::query()
            ->where('sampled_at', '>=', $since)
            ->orderBy('sampled_at')
            ->get(['sampled_at', 'status', 'issues', 'metrics']);

        return response()->json([
            'data' => [
                'range_hours' => $hours,
                'latest' => $latest,
                'sample_count' => $samples->count(),
                'samples' => $samples,
                'last_sample_age_seconds' => $latest?->sampled_at?->diffInSeconds(now()),
            ],
        ]);
    }
}
