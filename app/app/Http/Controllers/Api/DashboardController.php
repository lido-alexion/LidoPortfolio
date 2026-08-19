<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PortfolioProfile;
use App\Models\PortfolioSnapshot;
use App\Services\CashManagementService;
use App\Services\DailyMarketSyncService;
use App\Services\PortfolioCalculationService;
use App\Services\PortfolioSnapshotRebuildService;
use App\Services\RelativeStrengthService;
use App\Services\AlertService;
use App\Services\Analytics\MarketAnalyticsService;
use App\Services\Analytics\PortfolioAnalyticsService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Throwable;

class DashboardController extends Controller
{
    public function __construct(
        protected PortfolioCalculationService $portfolio,
        protected AlertService $alerts,
        protected RelativeStrengthService $relativeStrength,
        protected PortfolioSnapshotRebuildService $snapshotRebuild,
        protected DailyMarketSyncService $dailySync,
        protected CashManagementService $cash,
        protected \App\Services\StrategyConfigurationService $strategies,
        protected PortfolioAnalyticsService $portfolioAnalytics,
        protected MarketAnalyticsService $marketAnalytics,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $profile = \activePortfolio();
        $summary = $this->portfolio->calculateForProfile($profile);
        $holdings = $summary['holdings'];
        $topMovers = $this->portfolio->topMovers($holdings);

        $growth = $this->portfolioGrowthSeries($profile);

        $benchmark = $this->relativeStrength->benchmarkStock();
        $cash = $this->cash->summary($profile);
        $capital = null;
        try {
            $capital = app(\App\Services\Strategy\PortfolioCapitalAccountingService::class)->snapshot($profile);
            $cash['available_physical_cash'] = $capital['physical_cash']['available_physical_cash'];
            $cash['required_cash_reserve'] = $capital['od19']['required_cash_reserve'];
            $cash['unallocated_cash'] = $capital['od20']['unallocated_cash'];
            $cash['reserve_shortfall_exists'] = $capital['od19']['reserve_shortfall_exists'];
        } catch (\Throwable) {
            $capital = null;
        }
        $strategy = null;
        try {
            $strategy = $this->strategies->summaryCard($profile);
        } catch (\Throwable) {
            $strategy = null;
        }

        $portfolioAnalytics = null;
        $marketAnalytics = null;
        try {
            $portfolioAnalytics = $this->portfolioAnalytics->forProfile($profile);
        } catch (\Throwable) {
            $portfolioAnalytics = null;
        }
        try {
            $marketAnalytics = $this->marketAnalytics->summary($profile);
        } catch (\Throwable) {
            $marketAnalytics = null;
        }

        return response()->json([
            'portfolio_value' => $summary['portfolio_value'],
            'invested_value' => $summary['invested_value'],
            'total_gain_loss' => $summary['total_gain_loss'],
            'unrealized_profit' => $summary['unrealized_profit'],
            'realized_profit' => $summary['realized_profit'],
            'xirr' => $summary['xirr'],
            'cash_balance' => $cash['cash_balance'],
            'reserved_cash' => $cash['reserved_cash'],
            'available_investable_cash' => $cash['available_investable_cash'],
            'cash' => $cash,
            'capital' => $capital,
            'reserve_shortfall_exists' => (bool) data_get($capital, 'od19.reserve_shortfall_exists', false),
            'reserve_shortfall_warning' => data_get($capital, 'od21.dashboard_reserve_warning'),
            'strategy' => $strategy,
            'portfolio_analytics' => $portfolioAnalytics,
            'market_analytics' => $marketAnalytics,
            'daily_change' => $this->portfolio->dailyChange($profile),
            'top_movers' => $topMovers,
            'top_gainer' => $topMovers['all_time']['gainer'],
            'top_loser' => $topMovers['all_time']['loser'],
            'allocation' => collect($holdings)->map(fn ($h) => [
                'symbol' => $h['symbol'],
                'allocation_market_percent' => $h['allocation_market_percent'],
                'allocation_invested_percent' => $h['allocation_invested_percent'],
                'market_value' => $h['market_value'],
            ])->values(),
            'alerts' => $this->alerts->getActiveForProfile($profile),
            'portfolio_growth' => $growth,
            ...($user->is_admin ? ['daily_market_sync' => $this->dailySync->status()] : []),
            'nifty_comparison' => [
                'benchmark' => $benchmark->only(['id', 'symbol', 'name']),
                'prices' => $benchmark->prices()->orderByDesc('price_date')->limit(90)->get(),
            ],
            // Per-stock RS trends remain available for BC but Dashboard UI should prefer portfolio_analytics averages.
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
     * Rebuilds once when empty but the profile has transactions (e.g. pre-rebuild data).
     */
    protected function portfolioGrowthSeries(PortfolioProfile $profile): Collection
    {
        $growth = $this->fetchRecentGrowthSnapshots($profile);

        if ($growth->isNotEmpty() || ! $profile->transactions()->exists()) {
            return $growth;
        }

        try {
            $earliest = $profile->transactions()->min('transaction_date');
            if ($earliest) {
                $this->snapshotRebuild->rebuildFromDate($profile, Carbon::parse($earliest)->startOfDay());
            }
            $growth = $this->fetchRecentGrowthSnapshots($profile);
        } catch (Throwable $e) {
            report($e);
        }

        return $growth;
    }

    protected function fetchRecentGrowthSnapshots(PortfolioProfile $profile): Collection
    {
        return PortfolioSnapshot::query()
            ->where('profile_id', $profile->id)
            ->orderByDesc('snapshot_date')
            ->limit(365)
            ->get(['snapshot_date', 'portfolio_value', 'invested_value'])
            ->sortBy('snapshot_date')
            ->values();
    }
}
