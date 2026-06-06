<?php

namespace App\Services;

use App\Models\Holding;
use App\Models\PortfolioSnapshot;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;

class PortfolioCalculationService
{
    public function __construct(
        protected XirrService $xirrService,
        protected StockQuoteService $quotes,
    ) {}

    public function calculateForUser(User $user, ?Carbon $asOf = null): array
    {
        $holdings = Holding::query()
            ->with('stock.metrics')
            ->where('user_id', $user->id)
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
                'allocation_percent' => 0,
            ];
        }

        foreach ($items as &$item) {
            $item['allocation_percent'] = $portfolioValue > 0
                ? round(($item['market_value'] / $portfolioValue) * 100, 2)
                : 0;
        }
        unset($item);

        $totalRealized = $holdings->sum(fn ($h) => (float) $h->realized_profit);
        $unrealizedTotal = $portfolioValue - $investedValue;
        $totalGainLoss = $unrealizedTotal + $totalRealized;

        $asOfDate = ($asOf ?? now())->copy()->startOfDay();

        $transactions = Transaction::query()
            ->where('user_id', $user->id)
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

    public function storeSnapshot(User $user, ?Carbon $date = null): PortfolioSnapshot
    {
        $date = ($date ?? now())->copy()->startOfDay();

        app(PortfolioSnapshotRebuildService::class)->rebuildDateRange($user, $date, $date);

        return PortfolioSnapshot::query()->firstOrNew([
            'user_id' => $user->id,
            'snapshot_date' => $date->toDateString(),
        ]);
    }

    public function dailyChange(User $user): ?array
    {
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();

        $todaySnapshot = PortfolioSnapshot::query()
            ->where('user_id', $user->id)
            ->where('snapshot_date', $today)
            ->first();

        $yesterdaySnapshot = PortfolioSnapshot::query()
            ->where('user_id', $user->id)
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

    protected function latestClose(int $stockId, ?Carbon $asOf = null): float
    {
        return $this->quotes->latestClose($stockId, $asOf);
    }
}
