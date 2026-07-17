<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Watchlist;
use App\Services\WatchlistService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WatchlistsController extends Controller
{
    public function __construct(protected WatchlistService $watchlist) {}

    public function index(Request $request): JsonResponse
    {
        $profile = \activePortfolio();
        $watchlists = $this->watchlist->listWatchlistsForProfile($profile);

        return response()->json([
            'data' => $watchlists->values(),
            'count' => $watchlists->count(),
            'max_watchlists' => WatchlistService::MAX_WATCHLISTS_PER_PROFILE,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
        ]);

        $profile = \activePortfolio();
        $watchlist = $this->watchlist->createWatchlist($profile, $validated['name']);

        return response()->json(['data' => $watchlist], 201);
    }

    public function update(Request $request, Watchlist $watchlist): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
        ]);

        $watchlist = $this->watchlist->renameWatchlist($watchlist, $validated['name']);

        return response()->json(['data' => $watchlist]);
    }

    public function destroy(Watchlist $watchlist): JsonResponse
    {
        $this->watchlist->deleteWatchlist($watchlist);

        return response()->json(['message' => 'Watchlist deleted.']);
    }
}
