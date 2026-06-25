<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PortfolioSnapshot;
use App\Models\User;
use App\Services\DailyMarketSyncService;
use App\Services\PortfolioCalculationService;
use App\Services\PortfolioSnapshotRebuildService;
use App\Services\RelativeStrengthService;
use App\Services\StoplossService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Throwable;

class DashboardController extends Controller
{
    public function __construct(
        protected PortfolioCalculationService $portfolio,
        protected StoplossService $stoploss,
        protected RelativeStrengthService $relativeStrength,
        protected PortfolioSnapshotRebuildService $snapshotRebuild,
        protected DailyMarketSyncService $dailySync,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $summary = $this->portfolio->calculateForUser($user);
        $holdings = $summary['holdings'];

        $topGainer = collect($holdings)->sortByDesc('unrealized_profit')->first();
        $topLoser = collect($holdings)->sortBy('unrealized_profit')->first();

        $growth = $this->portfolioGrowthSeries($user);

        $benchmark = $this->relativeStrength->benchmarkStock();

        return response()->json([
            'portfolio_value' => $summary['portfolio_value'],
            'invested_value' => $summary['invested_value'],
            'total_gain_loss' => $summary['total_gain_loss'],
            'unrealized_profit' => $summary['unrealized_profit'],
            'realized_profit' => $summary['realized_profit'],
            'xirr' => $summary['xirr'],
            'daily_change' => $this->portfolio->dailyChange($user),
            'top_gainer' => $topGainer,
            'top_loser' => $topLoser,
            'allocation' => collect($holdings)->map(fn ($h) => [
                'symbol' => $h['symbol'],
                'allocation_market_percent' => $h['allocation_market_percent'],
                'allocation_invested_percent' => $h['allocation_invested_percent'],
                'market_value' => $h['market_value'],
            ])->values(),
            'stoploss_alerts' => $this->stoploss->getActiveAlertsForUser($user),
            'portfolio_growth' => $growth,
            ...($user->is_admin ? ['daily_market_sync' => $this->dailySync->status()] : []),
            'nifty_comparison' => [
                'benchmark' => $benchmark->only(['id', 'symbol', 'name']),
                'prices' => $benchmark->prices()->orderByDesc('price_date')->limit(90)->get(),
            ],
            'relative_strength_trends' => collect($holdings)->map(function ($h) {
                return [
                    'symbol' => $h['symbol'],
                    'metrics' => optional(\App\Models\StockMetric::query()->where('stock_id', $h['stock_id'])->first())?->only([
                        'relative_strength_1m',
                        'relative_strength_3m',
                        'relative_strength_6m',
                    ]),
                ];
            }),
        ]);
    }

    /**
     * Latest 365 snapshot days (ascending) for the growth chart.
     * Rebuilds once when empty but the user has transactions (e.g. pre-rebuild data).
     */
    protected function portfolioGrowthSeries(User $user): Collection
    {
        $growth = $this->fetchRecentGrowthSnapshots($user);

        if ($growth->isNotEmpty() || ! $user->transactions()->exists()) {
            return $growth;
        }

        try {
            $earliest = $user->transactions()->min('transaction_date');
            if ($earliest) {
                $this->snapshotRebuild->rebuildFromDate($user, Carbon::parse($earliest)->startOfDay());
            }
            $growth = $this->fetchRecentGrowthSnapshots($user);
        } catch (Throwable $e) {
            report($e);
        }

        return $growth;
    }

    protected function fetchRecentGrowthSnapshots(User $user): Collection
    {
        return PortfolioSnapshot::query()
            ->where('user_id', $user->id)
            ->orderByDesc('snapshot_date')
            ->limit(365)
            ->get(['snapshot_date', 'portfolio_value', 'invested_value'])
            ->sortBy('snapshot_date')
            ->values();
    }
}
