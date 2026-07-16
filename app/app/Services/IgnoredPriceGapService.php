<?php

namespace App\Services;

use App\Models\IgnoredPriceGap;
use App\Models\Stock;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class IgnoredPriceGapService
{
    /**
     * @return Collection<int, IgnoredPriceGap>
     */
    public function listWithStock(): Collection
    {
        return IgnoredPriceGap::query()
            ->with(['stock:id,symbol,exchange,isin'])
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * @return array<int, array{id: int, stock_id: int, symbol: string, exchange: string, gap_from: string, gap_to: string, gap_days: int, ignored_at: string}>
     */
    public function listForApi(): array
    {
        return $this->listWithStock()
            ->map(fn (IgnoredPriceGap $row) => $this->formatRow($row))
            ->values()
            ->all();
    }

    public function ignore(int $stockId, string $gapFrom, string $gapTo, ?int $userId = null): IgnoredPriceGap
    {
        $stock = Stock::query()->findOrFail($stockId);
        $from = Carbon::parse($gapFrom)->toDateString();
        $to = Carbon::parse($gapTo)->toDateString();

        if ($from > $to) {
            throw new \InvalidArgumentException('gap_from must be on or before gap_to.');
        }

        $row = IgnoredPriceGap::query()->firstOrCreate(
            [
                'stock_id' => $stock->id,
                'gap_from' => $from,
                'gap_to' => $to,
            ],
            ['ignored_by_user_id' => $userId],
        );

        return $row->fresh(['stock']);
    }

    public function remove(int $id): bool
    {
        return IgnoredPriceGap::query()->whereKey($id)->delete() > 0;
    }

    /**
     * @return array<int, string>
     */
    public function ignoredKeys(): array
    {
        return IgnoredPriceGap::query()
            ->get(['stock_id', 'gap_from', 'gap_to'])
            ->map(fn (IgnoredPriceGap $row) => $this->gapKey(
                (int) $row->stock_id,
                $row->gap_from->toDateString(),
                $row->gap_to->toDateString(),
            ))
            ->all();
    }

    public function isIgnored(int $stockId, string $gapFrom, string $gapTo): bool
    {
        $from = Carbon::parse($gapFrom)->toDateString();
        $to = Carbon::parse($gapTo)->toDateString();

        foreach ($this->ignoredRangesForStock($stockId) as $ignored) {
            if ($ignored['gap_from'] === $from && $ignored['gap_to'] === $to) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array{from: Carbon, to: Carbon}>  $ranges
     * @return array<int, array{from: Carbon, to: Carbon}>
     */
    public function filterRanges(int $stockId, array $ranges): array
    {
        if ($ranges === []) {
            return [];
        }

        $ignored = $this->ignoredRangesForStock($stockId);
        if ($ignored === []) {
            return $ranges;
        }

        return array_values(array_filter($ranges, function (array $range) use ($ignored): bool {
            $from = $range['from']->toDateString();
            $to = $range['to']->toDateString();

            foreach ($ignored as $row) {
                if ($row['gap_from'] === $from && $row['gap_to'] === $to) {
                    return false;
                }
            }

            return true;
        }));
    }

    public function gapKey(int $stockId, string $gapFrom, string $gapTo): string
    {
        return $stockId.':'.Carbon::parse($gapFrom)->toDateString().':'.Carbon::parse($gapTo)->toDateString();
    }

  /**
     * @return array{id: int, stock_id: int, symbol: string, exchange: string, gap_from: string, gap_to: string, gap_days: int, ignored_at: string}
     */
    public function formatRow(IgnoredPriceGap $row): array
    {
        $from = $row->gap_from->toDateString();
        $to = $row->gap_to->toDateString();

        return [
            'id' => (int) $row->id,
            'stock_id' => (int) $row->stock_id,
            'symbol' => (string) ($row->stock?->symbol ?? ''),
            'exchange' => (string) ($row->stock?->exchange ?? ''),
            'gap_from' => $from,
            'gap_to' => $to,
            'gap_days' => $this->gapDays($from, $to),
            'ignored_at' => $row->created_at?->toIso8601String() ?? '',
        ];
    }

    public function gapDays(string $gapFrom, string $gapTo): int
    {
        return (int) Carbon::parse($gapFrom)->diffInDays(Carbon::parse($gapTo), false);
    }

    /**
     * @return array<int, array{gap_from: string, gap_to: string}>
     */
    protected function ignoredRangesForStock(int $stockId): array
    {
        return IgnoredPriceGap::query()
            ->where('stock_id', $stockId)
            ->get(['gap_from', 'gap_to'])
            ->map(fn (IgnoredPriceGap $row) => [
                'gap_from' => $row->gap_from->toDateString(),
                'gap_to' => $row->gap_to->toDateString(),
            ])
            ->all();
    }
}
