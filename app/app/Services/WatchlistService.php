<?php

namespace App\Services;

use App\Models\Holding;
use App\Models\PortfolioProfile;
use App\Models\Stock;
use App\Models\Watchlist;
use App\Models\WatchlistItem;
use App\Models\WatchlistPatternScan;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class WatchlistService
{
    public const MAX_WATCHLISTS_PER_PROFILE = 20;

    public const MAX_ITEMS_PER_WATCHLIST = 100;

    public const DEFAULT_WATCHLIST_NAME = 'My Watchlist';

    public function __construct(
        protected MarketPriceService $marketPrices,
        protected PatternScanService $patternScan,
    ) {}

    public function ensureDefaultWatchlist(PortfolioProfile $profile): Watchlist
    {
        $existing = Watchlist::query()
            ->where('profile_id', $profile->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();

        if ($existing) {
            return $existing;
        }

        return Watchlist::query()->create([
            'profile_id' => $profile->id,
            'name' => self::DEFAULT_WATCHLIST_NAME,
            'sort_order' => 0,
        ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function listWatchlistsForProfile(PortfolioProfile $profile): Collection
    {
        $this->ensureDefaultWatchlist($profile);

        return Watchlist::query()
            ->where('profile_id', $profile->id)
            ->withCount('items')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (Watchlist $watchlist) => $this->formatWatchlist($watchlist));
    }

    public function createWatchlist(PortfolioProfile $profile, string $name): array
    {
        $normalized = $this->normalizeWatchlistName($name);

        $count = Watchlist::query()->where('profile_id', $profile->id)->count();
        if ($count >= self::MAX_WATCHLISTS_PER_PROFILE) {
            throw ValidationException::withMessages([
                'name' => ['Maximum '.self::MAX_WATCHLISTS_PER_PROFILE.' watchlists per portfolio.'],
            ]);
        }

        if (Watchlist::query()
            ->where('profile_id', $profile->id)
            ->where('name', $normalized)
            ->exists()) {
            throw ValidationException::withMessages([
                'name' => ['A watchlist with this name already exists.'],
            ]);
        }

        $maxSort = (int) Watchlist::query()
            ->where('profile_id', $profile->id)
            ->max('sort_order');

        $watchlist = Watchlist::query()->create([
            'profile_id' => $profile->id,
            'name' => $normalized,
            'sort_order' => $maxSort + 1,
        ]);

        return $this->formatWatchlist($watchlist->loadCount('items'));
    }

    public function renameWatchlist(Watchlist $watchlist, string $name): array
    {
        $normalized = $this->normalizeWatchlistName($name);

        if (Watchlist::query()
            ->where('profile_id', $watchlist->profile_id)
            ->where('name', $normalized)
            ->where('id', '!=', $watchlist->id)
            ->exists()) {
            throw ValidationException::withMessages([
                'name' => ['A watchlist with this name already exists.'],
            ]);
        }

        $watchlist->name = $normalized;
        $watchlist->save();

        return $this->formatWatchlist($watchlist->loadCount('items'));
    }

    public function deleteWatchlist(Watchlist $watchlist): void
    {
        $count = Watchlist::query()
            ->where('profile_id', $watchlist->profile_id)
            ->count();

        if ($count <= 1) {
            throw ValidationException::withMessages([
                'watchlist' => ['Cannot delete your only watchlist.'],
            ]);
        }

        $watchlist->delete();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function listItems(
        Watchlist $watchlist,
        ?string $search = null,
        string $sort = 'symbol',
    ): Collection {
        $this->assertWatchlistBelongsToProfile($watchlist);

        $items = WatchlistItem::query()
            ->with('stock')
            ->where('watchlist_id', $watchlist->id)
            ->get();

        $matchesByStock = $this->patternScan->validMatchesByStockForWatchlist($watchlist);
        $holdingsByStock = Holding::query()
            ->where('profile_id', $watchlist->profile_id)
            ->where('quantity', '>', 0)
            ->whereIn('stock_id', $items->pluck('stock_id'))
            ->get()
            ->keyBy(fn (Holding $holding) => (int) $holding->stock_id);

        $items = $items->map(fn (WatchlistItem $item) => $this->formatItem(
            $item,
            $matchesByStock[(int) $item->stock_id] ?? [],
            $holdingsByStock->get((int) $item->stock_id),
        ));

        if ($search !== null && trim($search) !== '') {
            $needle = mb_strtolower(trim($search));
            $items = $items->filter(function (array $item) use ($needle) {
                $symbol = mb_strtolower((string) ($item['stock']['symbol'] ?? ''));
                $name = mb_strtolower((string) ($item['stock']['name'] ?? ''));
                $note = mb_strtolower((string) ($item['note'] ?? ''));

                return str_contains($symbol, $needle)
                    || str_contains($name, $needle)
                    || str_contains($note, $needle);
            });
        }

        return $this->sortItems($items, $sort)->values();
    }

    public function findItemForWatchlistStock(Watchlist $watchlist, int $stockId): ?WatchlistItem
    {
        return WatchlistItem::query()
            ->where('watchlist_id', $watchlist->id)
            ->where('stock_id', $stockId)
            ->first();
    }

    /**
     * @return list<int>
     */
    public function watchlistIdsContainingStock(PortfolioProfile $profile, int $stockId): array
    {
        return WatchlistItem::query()
            ->where('profile_id', $profile->id)
            ->where('stock_id', $stockId)
            ->pluck('watchlist_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function add(Watchlist $watchlist, Stock $stock, ?string $note = null): array
    {
        $this->assertWatchlistBelongsToProfile($watchlist);

        if ($stock->is_benchmark || ! $stock->is_active) {
            throw ValidationException::withMessages([
                'stock_id' => ['This stock cannot be added to the watchlist.'],
            ]);
        }

        $existing = $this->findItemForWatchlistStock($watchlist, (int) $stock->id);
        if ($existing) {
            throw ValidationException::withMessages([
                'stock_id' => ['This stock is already on this watchlist.'],
            ]);
        }

        $count = WatchlistItem::query()->where('watchlist_id', $watchlist->id)->count();
        if ($count >= self::MAX_ITEMS_PER_WATCHLIST) {
            throw ValidationException::withMessages([
                'watchlist' => ['Watchlist is full (maximum '.self::MAX_ITEMS_PER_WATCHLIST.' stocks).'],
            ]);
        }

        $item = WatchlistItem::query()->create([
            'profile_id' => $watchlist->profile_id,
            'watchlist_id' => $watchlist->id,
            'stock_id' => $stock->id,
            'note' => $this->normalizeNote($note),
        ]);
        $item->setRelation('stock', $stock);

        return $this->formatItem($item);
    }

    public function updateNote(WatchlistItem $item, ?string $note): array
    {
        $item->note = $this->normalizeNote($note);
        $item->save();
        $item->loadMissing('stock');

        return $this->formatItem($item);
    }

    public function remove(WatchlistItem $item): void
    {
        WatchlistPatternScan::query()
            ->where('watchlist_id', $item->watchlist_id)
            ->where('stock_id', $item->stock_id)
            ->delete();

        $item->delete();
    }

    protected function assertWatchlistBelongsToProfile(Watchlist $watchlist): void
    {
        $profile = \activePortfolio();

        if ($profile === null || (int) $watchlist->profile_id !== (int) $profile->id) {
            throw ValidationException::withMessages([
                'watchlist' => ['Watchlist not found.'],
            ]);
        }
    }

    protected function normalizeWatchlistName(string $name): string
    {
        $trimmed = trim($name);

        if ($trimmed === '') {
            throw ValidationException::withMessages([
                'name' => ['Name is required.'],
            ]);
        }

        if (mb_strlen($trimmed) > 80) {
            throw ValidationException::withMessages([
                'name' => ['Name must be 80 characters or fewer.'],
            ]);
        }

        if (! preg_match('/^[A-Za-z0-9 _-]+$/', $trimmed)) {
            throw ValidationException::withMessages([
                'name' => ['Use only letters, numbers, spaces, hyphens, and underscores.'],
            ]);
        }

        return $trimmed;
    }

    protected function normalizeNote(?string $note): ?string
    {
        if ($note === null) {
            return null;
        }

        $trimmed = trim($note);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, 500);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @return Collection<int, array<string, mixed>>
     */
    protected function sortItems(Collection $items, string $sort): Collection
    {
        $descending = str_starts_with($sort, '-');
        $field = ltrim($sort, '-');

        $allowed = ['symbol', 'latest_close', 'updated_at', 'name', 'daily_change_percent'];
        if (! in_array($field, $allowed, true)) {
            $field = 'symbol';
            $descending = false;
        }

        return $items->sort(function (array $a, array $b) use ($field, $descending) {
            $left = match ($field) {
                'symbol' => mb_strtoupper((string) ($a['stock']['symbol'] ?? '')),
                'name' => mb_strtolower((string) ($a['stock']['name'] ?? '')),
                'latest_close' => $a['latest_close'],
                'daily_change_percent' => $a['daily_change_percent'],
                'updated_at' => $a['updated_at'] ?? '',
                default => '',
            };
            $right = match ($field) {
                'symbol' => mb_strtoupper((string) ($b['stock']['symbol'] ?? '')),
                'name' => mb_strtolower((string) ($b['stock']['name'] ?? '')),
                'latest_close' => $b['latest_close'],
                'daily_change_percent' => $b['daily_change_percent'],
                'updated_at' => $b['updated_at'] ?? '',
                default => '',
            };

            if ($left === $right) {
                return 0;
            }

            if ($left === null) {
                return 1;
            }

            if ($right === null) {
                return -1;
            }

            $cmp = $left <=> $right;

            return $descending ? -$cmp : $cmp;
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatWatchlist(Watchlist $watchlist): array
    {
        return [
            'id' => $watchlist->id,
            'name' => $watchlist->name,
            'sort_order' => $watchlist->sort_order,
            'item_count' => (int) ($watchlist->items_count ?? $watchlist->items()->count()),
            'created_at' => $watchlist->created_at?->toIso8601String(),
            'updated_at' => $watchlist->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $patternMatches
     * @return array<string, mixed>
     */
    protected function formatItem(
        WatchlistItem $item,
        array $patternMatches = [],
        ?Holding $holding = null,
    ): array
    {
        $stock = $item->stock;
        $summary = $stock
            ? $this->marketPrices->summaryForStock($stock)
            : [
                'price_count' => 0,
                'has_price_history' => false,
                'latest_close' => null,
                'latest_price_date' => null,
                'previous_close' => null,
                'daily_change' => null,
                'daily_change_percent' => null,
                'is_price_fresh' => false,
            ];
        $holding ??= Holding::query()
            ->where('profile_id', $item->profile_id)
            ->where('stock_id', $item->stock_id)
            ->where('quantity', '>', 0)
            ->first();
        $holdingSummary = null;
        if ($holding) {
            $latestClose = $summary['latest_close'];
            $unrealizedProfit = $latestClose !== null
                ? round(((float) $holding->quantity * (float) $latestClose) - (float) $holding->invested_amount, 4)
                : null;
            $holdingSummary = [
                'quantity' => (float) $holding->quantity,
                'avg_buy_price' => (float) $holding->avg_buy_price,
                'invested_amount' => (float) $holding->invested_amount,
                'unrealized_profit' => $unrealizedProfit,
            ];
        }

        return [
            'id' => $item->id,
            'watchlist_id' => $item->watchlist_id,
            'stock_id' => $item->stock_id,
            'note' => $item->note,
            'created_at' => $item->created_at?->toIso8601String(),
            'updated_at' => $item->updated_at?->toIso8601String(),
            'stock' => $stock?->only(['id', 'symbol', 'name', 'exchange']),
            'holding' => $holdingSummary,
            'pattern_matches' => array_values($patternMatches),
            ...$summary,
        ];
    }
}
