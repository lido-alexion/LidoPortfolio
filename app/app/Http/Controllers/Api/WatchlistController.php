<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Stock;
use App\Models\WatchlistItem;
use App\Services\WatchlistService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WatchlistController extends Controller
{
    public function __construct(protected WatchlistService $watchlist) {}

    public function index(Request $request): JsonResponse
    {
        $profile = \activePortfolio();
        $items = $this->watchlist->listForProfile($profile);

        return response()->json([
            'data' => $items->values(),
            'count' => $items->count(),
            'max_items' => WatchlistService::MAX_ITEMS_PER_PROFILE,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'stock_id' => ['required', 'integer', 'exists:portfolio_stocks,id'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $profile = \activePortfolio();
        $stock = Stock::query()->findOrFail($validated['stock_id']);
        $item = $this->watchlist->add($profile, $stock, $validated['note'] ?? null);

        return response()->json(['data' => $item], 201);
    }

    public function update(Request $request, WatchlistItem $watchlistItem): JsonResponse
    {
        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $item = $this->watchlist->updateNote($watchlistItem, $validated['note'] ?? null);

        return response()->json(['data' => $item]);
    }

    public function destroy(WatchlistItem $watchlistItem): JsonResponse
    {
        $this->watchlist->remove($watchlistItem);

        return response()->json(['message' => 'Removed from watchlist.']);
    }
}
