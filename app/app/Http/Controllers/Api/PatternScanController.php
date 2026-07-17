<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PatternScanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PatternScanController extends Controller
{
    public function __construct(protected PatternScanService $scan) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'scope' => ['required', 'string', 'in:holdings,watchlist'],
            'watchlist_id' => ['nullable', 'integer', 'exists:portfolio_watchlists,id'],
        ]);

        $profile = \activePortfolio();
        $actionableOnly = $request->boolean('actionable_only', true);
        $watchlistId = isset($validated['watchlist_id']) ? (int) $validated['watchlist_id'] : null;

        // Persist scan results for a specific watchlist so icons survive list switches.
        if ($validated['scope'] === 'watchlist' && $watchlistId === null) {
            // Prefer an explicit watchlist; fall back to first list for the profile.
            $watchlistId = \App\Models\Watchlist::query()
                ->where('profile_id', $profile->id)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->value('id');
            $watchlistId = $watchlistId !== null ? (int) $watchlistId : null;
        }

        $payload = $this->scan->scan(
            $profile,
            $validated['scope'],
            $actionableOnly,
            $watchlistId,
        );

        return response()->json($payload);
    }
}
