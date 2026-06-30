<?php

namespace App\Services;

use App\Models\PortfolioProfile;
use App\Models\Stock;
use App\Models\WatchlistItem;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class WatchlistService
{
    public const MAX_ITEMS_PER_PROFILE = 100;

    public function __construct(protected MarketPriceService $marketPrices) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function listForProfile(PortfolioProfile $profile): Collection
    {
        return WatchlistItem::query()
            ->with('stock')
            ->where('profile_id', $profile->id)
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (WatchlistItem $item) => $this->formatItem($item));
    }

    public function findForProfileStock(PortfolioProfile $profile, int $stockId): ?WatchlistItem
    {
        return WatchlistItem::query()
            ->where('profile_id', $profile->id)
            ->where('stock_id', $stockId)
            ->first();
    }

    public function add(PortfolioProfile $profile, Stock $stock, ?string $note = null): array
    {
        if ($stock->is_benchmark || ! $stock->is_active) {
            throw ValidationException::withMessages([
                'stock_id' => ['This stock cannot be added to the watchlist.'],
            ]);
        }

        $existing = $this->findForProfileStock($profile, (int) $stock->id);
        if ($existing) {
            throw ValidationException::withMessages([
                'stock_id' => ['This stock is already on your watchlist.'],
            ]);
        }

        $count = WatchlistItem::query()->where('profile_id', $profile->id)->count();
        if ($count >= self::MAX_ITEMS_PER_PROFILE) {
            throw ValidationException::withMessages([
                'watchlist' => ['Watchlist is full (maximum '.self::MAX_ITEMS_PER_PROFILE.' stocks).'],
            ]);
        }

        $item = WatchlistItem::query()->create([
            'profile_id' => $profile->id,
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
        $item->delete();
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
     * @return array<string, mixed>
     */
    protected function formatItem(WatchlistItem $item): array
    {
        $stock = $item->stock;
        $summary = $stock
            ? $this->marketPrices->summaryForStock($stock)
            : [
                'price_count' => 0,
                'has_price_history' => false,
                'latest_close' => null,
                'latest_price_date' => null,
            ];

        return [
            'id' => $item->id,
            'stock_id' => $item->stock_id,
            'note' => $item->note,
            'created_at' => $item->created_at?->toIso8601String(),
            'updated_at' => $item->updated_at?->toIso8601String(),
            'stock' => $stock?->only(['id', 'symbol', 'name', 'exchange']),
            ...$summary,
        ];
    }
}
