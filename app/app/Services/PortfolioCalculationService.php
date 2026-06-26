<?php

namespace App\Services;

use App\Models\Holding;
use App\Models\PortfolioProfile;
use App\Models\PortfolioSnapshot;
use App\Models\Transaction;
use Carbon\Carbon;

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

    protected function latestClose(int $stockId, ?Carbon $asOf = null): float
    {
        return $this->quotes->latestClose($stockId, $asOf);
    }
}
