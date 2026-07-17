<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Stock;
use App\Models\Watchlist;
use App\Models\WatchlistItem;
use App\Services\WatchlistService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WatchlistController extends Controller
{
    public function __construct(protected WatchlistService $watchlist) {}

    public function index(Request $request, Watchlist $watchlist): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'sort' => ['nullable', 'string', 'max:32'],
        ]);

        $items = $this->watchlist->listItems(
            $watchlist,
            $validated['search'] ?? null,
            $validated['sort'] ?? 'symbol',
        );

        return response()->json([
            'data' => $items->values(),
            'count' => $items->count(),
            'watchlist' => [
                'id' => $watchlist->id,
                'name' => $watchlist->name,
            ],
            'max_items' => WatchlistService::MAX_ITEMS_PER_WATCHLIST,
        ]);
    }

    public function membership(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'stock_id' => ['required', 'integer', 'exists:portfolio_stocks,id'],
        ]);

        $profile = \activePortfolio();
        $watchlistIds = $this->watchlist->watchlistIdsContainingStock(
            $profile,
            (int) $validated['stock_id'],
        );

        return response()->json([
            'stock_id' => (int) $validated['stock_id'],
            'watchlist_ids' => $watchlistIds,
        ]);
    }

    public function store(Request $request, Watchlist $watchlist): JsonResponse
    {
        $validated = $request->validate([
            'stock_id' => ['required', 'integer', 'exists:portfolio_stocks,id'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $stock = Stock::query()->findOrFail($validated['stock_id']);
        $item = $this->watchlist->add($watchlist, $stock, $validated['note'] ?? null);

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
