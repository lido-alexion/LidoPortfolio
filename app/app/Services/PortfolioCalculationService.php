<?php

namespace App\Services;

use App\Models\Holding;
use App\Models\PortfolioProfile;
use App\Models\PortfolioSnapshot;
use App\Models\StockPrice;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class PortfolioCalculationService
{
    public function __construct(
        protected XirrService $xirrService,
        protected StockQuoteService $quotes,
    ) {}

    public function calculateForProfile(PortfolioProfile $profile, ?Carbon $asOf = null): array
    {
        $holdings = Holding::query()
            ->with('stock.metrics')
            ->where('profile_id', $profile->id)
            ->where('quantity', '>', 0)
            ->get();

        $portfolioValue = 0.0;
        $investedValue = 0.0;
        $items = [];

        foreach ($holdings as $holding) {
            $latestClose = $this->latestClose($holding->stock_id, $asOf);
            $marketValue = (float) $holding->quantity * $latestClose;
            $invested = (float) $holding->invested_amount;
            $unrealized = $marketValue - $invested;

            $portfolioValue += $marketValue;
            $investedValue += $invested;

            $items[] = [
                'stock_id' => $holding->stock_id,
                'symbol' => $holding->stock->symbol,
                'name' => $holding->stock->name,
                'quantity' => (float) $holding->quantity,
                'avg_buy_price' => (float) $holding->avg_buy_price,
                'latest_close' => $latestClose,
                'market_value' => round($marketValue, 4),
                'invested_amount' => round($invested, 4),
                'unrealized_profit' => round($unrealized, 4),
                'realized_profit' => (float) $holding->realized_profit,
                'allocation_market_percent' => 0,
                'allocation_invested_percent' => 0,
            ];
        }

        foreach ($items as &$item) {
            $item['allocation_market_percent'] = $portfolioValue > 0
                ? round(($item['market_value'] / $portfolioValue) * 100, 2)
                : 0;
            $item['allocation_invested_percent'] = $investedValue > 0
                ? round(($item['invested_amount'] / $investedValue) * 100, 2)
                : 0;
        }
        unset($item);

        $totalRealized = $holdings->sum(fn ($h) => (float) $h->realized_profit);
        $unrealizedTotal = $portfolioValue - $investedValue;
        $totalGainLoss = $unrealizedTotal + $totalRealized;

        $asOfDate = ($asOf ?? now())->copy()->startOfDay();

        $transactions = Transaction::query()
            ->where('profile_id', $profile->id)
            ->orderBy('transaction_date')
            ->get();

        return [
            'portfolio_value' => round($portfolioValue, 4),
            'invested_value' => round($investedValue, 4),
            'unrealized_profit' => round($unrealizedTotal, 4),
            'realized_profit' => round($totalRealized, 4),
            'total_gain_loss' => round($totalGainLoss, 4),
            'holdings' => $items,
            'xirr' => $this->xirrService->calculateFromTransactions(
                $transactions,
                $portfolioValue,
                $asOfDate,
            ),
        ];
    }

    public function storeSnapshot(PortfolioProfile $profile, ?Carbon $date = null): PortfolioSnapshot
    {
        $date = ($date ?? now())->copy()->startOfDay();

        app(PortfolioSnapshotRebuildService::class)->rebuildDateRange($profile, $date, $date);

        return PortfolioSnapshot::query()->firstOrNew([
            'profile_id' => $profile->id,
            'snapshot_date' => $date->toDateString(),
        ]);
    }

    public function dailyChange(PortfolioProfile $profile): ?array
    {
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();

        $todaySnapshot = PortfolioSnapshot::query()
            ->where('profile_id', $profile->id)
            ->where('snapshot_date', $today)
            ->first();

        $yesterdaySnapshot = PortfolioSnapshot::query()
            ->where('profile_id', $profile->id)
            ->where('snapshot_date', $yesterday)
            ->first();

        if (! $todaySnapshot || ! $yesterdaySnapshot) {
            return null;
        }

        $change = (float) $todaySnapshot->portfolio_value - (float) $yesterdaySnapshot->portfolio_value;
        $percent = (float) $yesterdaySnapshot->portfolio_value > 0
            ? ($change / (float) $yesterdaySnapshot->portfolio_value) * 100
            : 0;

        return [
            'change' => round($change, 4),
            'change_percent' => round($percent, 2),
        ];
    }

    /**
     * Best/worst holdings by lifetime unrealized % and latest OHLCV day-over-day %.
     *
     * @param  array<int, array<string, mixed>>  $holdings
     * @return array{
     *     all_time: array{gainer: ?array, loser: ?array},
     *     latest_day: array{gainer: ?array, loser: ?array}
     * }
     */
    public function topMovers(array $holdings): array
    {
        $withDaily = $this->attachDailyChangePercent($holdings);

        $allTime = collect($withDaily)
            ->filter(fn (array $h) => (float) ($h['invested_amount'] ?? 0) > 0)
            ->map(function (array $h): array {
                $invested = (float) $h['invested_amount'];
                $h['change_percent'] = round(((float) $h['unrealized_profit'] / $invested) * 100, 2);

                return $h;
            });

        $latestDay = collect($withDaily)
            ->filter(fn (array $h) => $h['daily_change_percent'] !== null)
            ->map(function (array $h): array {
                $h['change_percent'] = (float) $h['daily_change_percent'];

                return $h;
            });

        return [
            'all_time' => [
                'gainer' => $this->pickTopMover($allTime, descending: true),
                'loser' => $this->pickTopMover($allTime, descending: false),
            ],
            'latest_day' => [
                'gainer' => $this->pickTopMover($latestDay, descending: true),
                'loser' => $this->pickTopMover($latestDay, descending: false),
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $holdings
     * @return array<int, array<string, mixed>>
     */
    protected function attachDailyChangePercent(array $holdings): array
    {
        if ($holdings === []) {
            return [];
        }

        $stockIds = collect($holdings)->pluck('stock_id')->unique()->values()->all();
        $recentByStock = [];

        $prices = StockPrice::query()
            ->whereIn('stock_id', $stockIds)
            ->orderBy('stock_id')
            ->orderByDesc('price_date')
            ->get(['stock_id', 'price_date', 'close_price']);

        foreach ($prices as $price) {
            $stockId = (int) $price->stock_id;
            if (! isset($recentByStock[$stockId])) {
                $recentByStock[$stockId] = [];
            }
            if (count($recentByStock[$stockId]) < 2) {
                $recentByStock[$stockId][] = $price;
            }
        }

        return array_map(function (array $holding) use ($recentByStock): array {
            $stockId = (int) ($holding['stock_id'] ?? 0);
            $rows = $recentByStock[$stockId] ?? [];
            $holding['daily_change_percent'] = null;

            if (count($rows) >= 2) {
                $latestClose = (float) $rows[0]->close_price;
                $previousClose = (float) $rows[1]->close_price;
                if ($previousClose > 0) {
                    $holding['daily_change_percent'] = round(
                        (($latestClose - $previousClose) / $previousClose) * 100,
                        2,
                    );
                }
            }

            return $holding;
        }, $holdings);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @return array{symbol: string, name: ?string, stock_id: int, change_percent: float}|null
     */
    protected function pickTopMover(Collection $items, bool $descending): ?array
    {
        if ($items->isEmpty()) {
            return null;
        }

        $sorted = $descending
            ? $items->sortByDesc('change_percent')
            : $items->sortBy('change_percent');

        $top = $sorted->first();

        return [
            'symbol' => (string) $top['symbol'],
            'name' => $top['name'] ?? null,
            'stock_id' => (int) $top['stock_id'],
            'change_percent' => (float) $top['change_percent'],
        ];
    }

    protected function latestClose(int $stockId, ?Carbon $asOf = null): float
    {
        return $this->quotes->latestClose($stockId, $asOf);
    }
}
