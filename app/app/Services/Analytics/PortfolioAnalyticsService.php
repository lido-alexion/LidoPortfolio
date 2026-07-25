<?php

namespace App\Services\Analytics;

use App\Models\EvaluationResult;
use App\Models\EvaluationRun;
use App\Models\Holding;
use App\Models\PortfolioProfile;
use App\Models\StockMetric;
use App\Services\CashManagementService;
use App\Services\PortfolioCalculationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SD-031 — Portfolio Analytics owner (portfolio-wide only).
 */
class PortfolioAnalyticsService
{
    public function __construct(
        protected PortfolioCalculationService $portfolio,
        protected CashManagementService $cash,
        protected MarketAnalyticsService $marketAnalytics,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forProfile(PortfolioProfile $profile, bool $useCache = true): array
    {
        if ($useCache && Schema::hasTable('portfolio_analytics_snapshots')) {
            $row = DB::table('portfolio_analytics_snapshots')
                ->where('profile_id', $profile->id)
                ->where('category', 'portfolio')
                ->where('cache_key', 'default')
                ->where('computed_at', '>=', now()->subMinutes(15))
                ->first();
            if ($row) {
                $payload = json_decode($row->payload_json, true);
                if (is_array($payload)) {
                    $payload['cached'] = true;

                    return $payload;
                }
            }
        }

        $payload = $this->compute($profile);
        $this->persist($profile->id, 'portfolio', $payload);

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    protected function compute(PortfolioProfile $profile): array
    {
        $summary = $this->portfolio->calculateForProfile($profile);
        $cash = $this->cash->summary($profile);
        $daily = $this->portfolio->dailyChange($profile);
        $holdings = $summary['holdings'] ?? [];
        $n = count($holdings);
        $portfolioValue = (float) ($summary['portfolio_value'] ?? 0);
        $invested = (float) ($summary['invested_value'] ?? 0);

        $allocs = array_map(fn ($h) => (float) ($h['allocation_market_percent'] ?? 0), $holdings);
        $largest = $allocs !== [] ? max($allocs) : 0.0;
        $hhi = 0.0;
        foreach ($allocs as $a) {
            $hhi += ($a / 100.0) ** 2;
        }
        $diversification = $n > 0 ? round(max(0, 100 - ($hhi * 100)), 2) : null;

        $stockIds = array_column($holdings, 'stock_id');
        $metrics = StockMetric::query()->whereIn('stock_id', $stockIds)->get()->keyBy('stock_id');
        $rsVals = [];
        foreach ($holdings as $h) {
            $m = $metrics->get($h['stock_id']);
            if ($m && $m->relative_strength_3m !== null) {
                $rsVals[] = (float) $m->relative_strength_3m;
            }
        }

        $avgEval = $this->averageEvaluationScores($profile, $stockIds);

        $betas = [];
        foreach ($holdings as $h) {
            // Soft beta proxy from allocation-weighted vol later; use RS spread as placeholder diversity
            $betas[] = 1.0;
        }
        $portfolioBeta = $n > 0 ? round(array_sum($betas) / $n, 2) : null;

        $cashBal = (float) ($cash['cash_balance'] ?? 0);
        $reserved = (float) ($cash['reserved_cash'] ?? 0);
        $available = (float) ($cash['available_investable_cash'] ?? 0);
        $util = ($cashBal + $portfolioValue) > 0
            ? round(($portfolioValue / ($cashBal + $portfolioValue)) * 100, 2)
            : null;

        $totalReturnPct = $invested > 0
            ? round((((float) $summary['total_gain_loss']) / $invested) * 100, 2)
            : null;

        $portfolioScore = null;
        if ($avgEval['overall'] !== null) {
            $portfolioScore = $avgEval['overall'];
        } elseif ($rsVals !== []) {
            $portfolioScore = round(array_sum($rsVals) / count($rsVals), 2);
        }

        return [
            'owner' => 'portfolio_analytics',
            'portfolio_value' => $portfolioValue,
            'invested_value' => $invested,
            'todays_pnl' => $daily['change'] ?? null,
            'todays_pnl_pct' => $daily['change_percent'] ?? null,
            'total_return' => $summary['total_gain_loss'] ?? null,
            'total_return_pct' => $totalReturnPct,
            'xirr' => $summary['xirr'] ?? null,
            'portfolio_score' => $portfolioScore,
            'portfolio_beta' => $portfolioBeta,
            'portfolio_volatility_pct' => $avgEval['risk'],
            'portfolio_correlation' => null, // deferred — needs pairwise returns
            'cash_available' => $available,
            'cash_reserved' => $reserved,
            'cash_balance' => $cashBal,
            'cash_utilisation_pct' => $util,
            'sector_allocation' => [], // sector master not fully wired in V1
            'diversification_score' => $diversification,
            'average_relative_strength' => $rsVals !== [] ? round(array_sum($rsVals) / count($rsVals), 2) : null,
            'average_momentum_score' => $avgEval['momentum'],
            'average_trend_score' => $avgEval['trend'],
            'average_risk_score' => $avgEval['risk'],
            'average_holding_period_days' => $this->averageHoldingPeriodDays($profile),
            'number_of_positions' => $n,
            'largest_position_pct' => round($largest, 2),
            'concentration_index' => round($hhi, 4),
            'allocation' => array_map(fn ($h) => [
                'symbol' => $h['symbol'],
                'allocation_pct' => $h['allocation_market_percent'],
                'market_value' => $h['market_value'],
            ], $holdings),
            'market_context' => $this->marketContext(),
            'computed_at' => now()->toIso8601String(),
            'cached' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function marketContext(): array
    {
        try {
            $m = $this->marketAnalytics->latest();

            return [
                'market_phase' => $m['market_phase'] ?? null,
                'sentiment_score' => $m['sentiment']['score'] ?? null,
                'sentiment_label' => $m['sentiment']['label'] ?? null,
                'market_risk_label' => $m['risk']['label'] ?? null,
                'alignment_note' => 'Portfolio averages should be interpreted in the context of current market phase and sentiment.',
            ];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param  list<int>  $stockIds
     * @return array{overall:?float,momentum:?float,trend:?float,risk:?float}
     */
    protected function averageEvaluationScores(PortfolioProfile $profile, array $stockIds): array
    {
        $empty = ['overall' => null, 'momentum' => null, 'trend' => null, 'risk' => null];
        if ($stockIds === []) {
            return $empty;
        }

        $run = EvaluationRun::query()
            ->where('profile_id', $profile->id)
            ->where('status', 'completed')
            ->orderByDesc('id')
            ->first();
        if (! $run) {
            return $empty;
        }

        $results = EvaluationResult::query()
            ->where('evaluation_run_id', $run->id)
            ->with('candidate')
            ->get()
            ->filter(fn ($r) => in_array((int) ($r->candidate->security_id ?? 0), $stockIds, true));

        if ($results->isEmpty()) {
            return $empty;
        }

        $overall = [];
        $mom = [];
        $trend = [];
        $risk = [];
        foreach ($results as $r) {
            $overall[] = (float) $r->score;
            $scores = $r->evidence['indicator_scores'] ?? $r->evidence['factor_scores'] ?? [];
            if (isset($scores['momentum_score'])) {
                $mom[] = (float) $scores['momentum_score'];
            } elseif (isset($scores['momentum'])) {
                $mom[] = (float) $scores['momentum'];
            }
            if (isset($scores['trend_score'])) {
                $trend[] = (float) $scores['trend_score'];
            } elseif (isset($scores['trend'])) {
                $trend[] = (float) $scores['trend'];
            }
            if (isset($scores['risk_score'])) {
                $risk[] = (float) $scores['risk_score'];
            }
        }

        $avg = fn (array $a) => $a !== [] ? round(array_sum($a) / count($a), 2) : null;

        return [
            'overall' => $avg($overall),
            'momentum' => $avg($mom),
            'trend' => $avg($trend),
            'risk' => $avg($risk),
        ];
    }

    protected function averageHoldingPeriodDays(PortfolioProfile $profile): ?float
    {
        $holdings = Holding::query()
            ->where('profile_id', $profile->id)
            ->where('quantity', '>', 0)
            ->get();
        if ($holdings->isEmpty()) {
            return null;
        }
        $days = [];
        foreach ($holdings as $h) {
            if ($h->created_at) {
                $days[] = max(1, $h->created_at->diffInDays(now()));
            }
        }

        return $days !== [] ? round(array_sum($days) / count($days), 1) : null;
    }

    protected function persist(int $profileId, string $category, array $payload): void
    {
        if (! Schema::hasTable('portfolio_analytics_snapshots')) {
            return;
        }
        DB::table('portfolio_analytics_snapshots')->updateOrInsert(
            [
                'profile_id' => $profileId,
                'category' => $category,
                'cache_key' => 'default',
            ],
            [
                'payload_json' => json_encode($payload),
                'computed_at' => now(),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }
}
