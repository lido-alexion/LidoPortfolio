<?php

namespace App\Engines\Review;

use App\Models\PortfolioProfile;
use App\Models\RecommendationReview;
use App\Models\ReviewMetric;
use App\Models\ReviewReport;
use App\Models\StockPrice;
use App\Models\TradingOrder;
use App\Models\TradingRecommendation;
use App\Models\Transaction;
use App\Repositories\Tos\ReviewReportRepository;
use App\Services\PortfolioCalculationService;
use App\Services\PortfolioLoggerService;
use App\Support\TradingOsConfig;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Review Engine — observational metrics only; never mutates historical facts.
 */
class ReviewEngine
{
    public function __construct(
        protected PortfolioCalculationService $portfolio,
        protected PortfolioLoggerService $logger,
        protected ReviewReportRepository $reports,
    ) {}

    /**
     * Dashboard payload for the Review UI (MVP usability focus).
     *
     * @return array<string,mixed>
     */
    public function dashboard(PortfolioProfile $profile): array
    {
        $summary = $this->portfolio->calculateForProfile($profile);

        $recs = TradingRecommendation::query()
            ->forProfile($profile)
            ->get();

        $actionable = $recs->filter(fn (TradingRecommendation $r) => $r->isActionable());
        $informational = $recs->filter(fn (TradingRecommendation $r) => $r->isInformational());

        $actionableCounts = $this->countByStatus($actionable);
        $informationalCounts = $this->countByStatus($informational);

        $accepted = (int) ($actionableCounts[TradingRecommendation::STATUS_PENDING_EXECUTION] ?? 0)
            + (int) ($actionableCounts[TradingRecommendation::STATUS_ACCEPTED] ?? 0);
        $rejected = (int) ($actionableCounts[TradingRecommendation::STATUS_REJECTED] ?? 0);
        $deferred = (int) ($actionableCounts[TradingRecommendation::STATUS_DEFERRED] ?? 0);
        $executed = (int) ($actionableCounts[TradingRecommendation::STATUS_EXECUTED] ?? 0);
        $cancelled = (int) ($actionableCounts[TradingRecommendation::STATUS_CANCELLED] ?? 0);
        $pending = (int) ($actionableCounts[TradingRecommendation::STATUS_PENDING_REVIEW] ?? 0)
            + (int) ($actionableCounts['active'] ?? 0);
        $decided = $accepted + $rejected + $deferred + $executed + $cancelled;
        $acceptanceAmongDecided = $decided > 0
            ? round((($accepted + $executed) / $decided) * 100, 2)
            : null;

        $orders = TradingOrder::query()
            ->with('security')
            ->where('profile_id', $profile->id)
            ->orderByDesc('id')
            ->limit(25)
            ->get();

        $reviewActions = RecommendationReview::query()
            ->whereHas('recommendation', function ($q) use ($profile) {
                $q->forProfile($profile)->actionableTypes();
            })
            ->with(['user', 'recommendation.security'])
            ->orderByDesc('id')
            ->limit(25)
            ->get()
            ->map(fn (RecommendationReview $r) => [
                'id' => $r->id,
                'decision' => $r->decision,
                'notes' => $r->notes,
                'user' => $r->user?->name ?? $r->user?->email,
                'symbol' => $r->recommendation?->security?->symbol,
                'recommendation_id' => $r->recommendation_id,
                'recommendation_type' => $r->recommendation?->recommendation_type,
                'created_at' => optional($r->created_at)?->toIso8601String(),
            ])
            ->all();

        $allOutcomes = $this->recommendationOutcomes($profile, 80);
        $actionableOutcomes = array_values(array_filter(
            $allOutcomes,
            fn ($o) => ($o['category'] ?? '') === 'actionable'
                || in_array(strtoupper((string) ($o['portfolio_action'] ?? $o['recommendation_type'] ?? '')), TradingRecommendation::ACTIONABLE_ACTIONS, true)
                || in_array(strtoupper((string) ($o['recommendation_type'] ?? '')), ['BUY', 'SELL'], true),
        ));
        $informationalOutcomes = array_values(array_filter(
            $allOutcomes,
            fn ($o) => ($o['category'] ?? '') === 'informational'
                || in_array(strtoupper((string) ($o['portfolio_action'] ?? $o['recommendation_type'] ?? '')), TradingRecommendation::INFORMATIONAL_ACTIONS, true)
                || in_array(strtoupper((string) ($o['recommendation_type'] ?? '')), ['HOLD', 'WATCH'], true),
        ));

        $actionableSummary = [
            'total' => $actionable->count(),
            'pending_review' => $pending,
            'accepted' => $accepted,
            'pending_execution' => $accepted,
            'rejected' => $rejected,
            'deferred' => $deferred,
            'executed' => $executed,
            'expired' => (int) ($actionableCounts[TradingRecommendation::STATUS_EXPIRED] ?? 0),
            'cancelled' => $cancelled,
            'acceptance_rate_pct' => $acceptanceAmongDecided,
            'by_status' => $actionableCounts,
        ];

        return [
            'portfolio' => [
                'portfolio_value' => $summary['portfolio_value'] ?? null,
                'invested_value' => $summary['invested_value'] ?? null,
                'unrealized_profit' => $summary['unrealized_profit'] ?? null,
                'realized_profit' => $summary['realized_profit'] ?? null,
                'xirr' => $summary['xirr'] ?? null,
            ],
            // Backward-compatible alias: review stats are actionable-only.
            'recommendation_counts' => $actionableSummary,
            'actionable_counts' => $actionableSummary,
            'informational_counts' => [
                'total' => $informational->count(),
                'published' => (int) ($informationalCounts[TradingRecommendation::STATUS_PUBLISHED] ?? 0),
                'expired' => (int) ($informationalCounts[TradingRecommendation::STATUS_EXPIRED] ?? 0),
                'cancelled' => (int) ($informationalCounts[TradingRecommendation::STATUS_CANCELLED] ?? 0),
                'archived' => (int) ($informationalCounts[TradingRecommendation::STATUS_ARCHIVED] ?? 0),
                'by_status' => $informationalCounts,
            ],
            'orders' => $orders->map(fn (TradingOrder $o) => [
                'id' => $o->id,
                'status' => $o->status,
                'side' => $o->side,
                'quantity' => (float) $o->quantity,
                'symbol' => $o->security?->symbol,
                'recommendation_id' => $o->recommendation_id,
                'executed_at' => optional($o->executed_at)?->toIso8601String(),
                'cancelled_at' => optional($o->cancelled_at)?->toIso8601String(),
                'created_at' => optional($o->created_at)?->toIso8601String(),
            ])->all(),
            'recent_reviews' => $reviewActions,
            'outcomes' => $actionableOutcomes,
            'informational_outcomes' => $informationalOutcomes,
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, TradingRecommendation>  $recs
     * @return array<string, int>
     */
    protected function countByStatus($recs): array
    {
        $byStatus = [];
        foreach ($recs as $rec) {
            $byStatus[$rec->status] = ($byStatus[$rec->status] ?? 0) + 1;
        }

        return $byStatus;
    }

    /**
     * Compare recommendation reference price vs current close.
     *
     * @return list<array<string,mixed>>
     */
    public function recommendationOutcomes(PortfolioProfile $profile, int $limit = 50): array
    {
        $recs = TradingRecommendation::query()
            ->with('security')
            ->forProfile($profile)
            ->whereNotIn('status', [TradingRecommendation::STATUS_CANCELLED])
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $securityIds = $recs->pluck('security_id')->unique()->filter()->all();
        $latestCloses = [];
        if ($securityIds !== []) {
            $rows = StockPrice::query()
                ->select('stock_id', DB::raw('MAX(price_date) as max_date'))
                ->whereIn('stock_id', $securityIds)
                ->groupBy('stock_id')
                ->get();
            foreach ($rows as $row) {
                $close = StockPrice::query()
                    ->where('stock_id', $row->stock_id)
                    ->where('price_date', $row->max_date)
                    ->value('close_price');
                $latestCloses[(int) $row->stock_id] = $close !== null ? (float) $close : null;
            }
        }

        $out = [];
        foreach ($recs as $rec) {
            $ref = $rec->reference_price !== null
                ? (float) $rec->reference_price
                : (isset($rec->evidence['indicators']['close']) ? (float) $rec->evidence['indicators']['close'] : null);
            $current = $latestCloses[(int) $rec->security_id] ?? null;
            $gainPct = null;
            $gainAbs = null;
            if ($ref !== null && $ref > 0 && $current !== null) {
                $gainAbs = round($current - $ref, 4);
                $gainPct = round((($current - $ref) / $ref) * 100, 4);
                // For EXIT / REDUCE / legacy SELL, a price drop is a favourable outcome.
                $action = method_exists($rec, 'portfolioAction') ? $rec->portfolioAction() : strtoupper((string) $rec->recommendation_type);
                if (in_array($action, ['EXIT_POSITION', 'REDUCE_POSITION', 'SELL'], true)) {
                    $gainPct = -$gainPct;
                    $gainAbs = -$gainAbs;
                }
            }

            $out[] = [
                'recommendation_id' => $rec->id,
                'symbol' => $rec->security?->symbol,
                'name' => $rec->security?->name,
                'recommendation_type' => $rec->recommendation_type,
                'portfolio_action' => $rec->portfolioAction(),
                'ui_label' => $rec->uiLabel(),
                'market_opinion' => $rec->market_opinion,
                'category' => $rec->category(),
                'status' => $rec->status,
                'reference_price' => $ref,
                'current_price' => $current,
                'current_allocation_pct' => $rec->current_allocation_pct !== null ? (float) $rec->current_allocation_pct : null,
                'target_allocation_pct' => $rec->target_allocation_pct !== null ? (float) $rec->target_allocation_pct : null,
                'gain_loss' => $gainAbs,
                'gain_loss_pct' => $gainPct,
                'generated_at' => optional($rec->generated_at)?->toIso8601String(),
                'expires_at' => optional($rec->expires_at)?->toIso8601String(),
            ];
        }

        return $out;
    }

    /**
     * @return array{report: ReviewReport, metrics: list<ReviewMetric>}
     */
    public function generate(PortfolioProfile $profile, ?Carbon $periodStart = null, ?Carbon $periodEnd = null): array
    {
        $lookback = TradingOsConfig::reviewDefaultLookbackDays();
        $periodEnd ??= Carbon::now()->startOfDay();
        $periodStart ??= $periodEnd->copy()->subDays($lookback);

        $summary = $this->portfolio->calculateForProfile($profile);

        $sells = Transaction::query()
            ->where('profile_id', $profile->id)
            ->where('type', 'sell')
            ->whereDate('transaction_date', '>=', $periodStart->toDateString())
            ->whereDate('transaction_date', '<=', $periodEnd->toDateString())
            ->get();

        $wins = 0;
        $losses = 0;
        $gainSum = 0.0;
        $lossSum = 0.0;
        foreach ($sells as $sell) {
            $pl = (float) ($sell->realized_pl ?? 0);
            if ($pl > 0) {
                $wins++;
                $gainSum += $pl;
            } elseif ($pl < 0) {
                $losses++;
                $lossSum += abs($pl);
            }
        }
        $closed = $wins + $losses;
        $winRate = $closed > 0 ? ($wins / $closed) * 100 : null;
        $avgGain = $wins > 0 ? $gainSum / $wins : null;
        $avgLoss = $losses > 0 ? $lossSum / $losses : null;
        $profitFactor = ($lossSum > 0) ? ($gainSum / $lossSum) : ($gainSum > 0 ? null : 0.0);
        $expectancy = $closed > 0 ? (($gainSum - $lossSum) / $closed) : null;

        $recs = TradingRecommendation::query()
            ->forProfile($profile)
            ->where('generated_at', '>=', $periodStart)
            ->where('generated_at', '<=', $periodEnd->copy()->endOfDay())
            ->get();

        $actionable = $recs->filter(fn (TradingRecommendation $r) => $r->isActionable());
        $informational = $recs->filter(fn (TradingRecommendation $r) => $r->isInformational());

        $executed = $actionable->where('status', TradingRecommendation::STATUS_EXECUTED)->count();
        $accepted = $actionable->whereIn('status', [
            TradingRecommendation::STATUS_PENDING_EXECUTION,
            TradingRecommendation::STATUS_ACCEPTED,
        ])->count();
        $rejected = $actionable->where('status', TradingRecommendation::STATUS_REJECTED)->count();
        $deferred = $actionable->where('status', TradingRecommendation::STATUS_DEFERRED)->count();
        $pending = $actionable->whereIn('status', [
            TradingRecommendation::STATUS_PENDING_REVIEW,
            'active',
        ])->count();
        $publishedInfo = $informational->where('status', TradingRecommendation::STATUS_PUBLISHED)->count();
        $cancelled = $actionable->where('status', TradingRecommendation::STATUS_CANCELLED)->count();
        $decided = $accepted + $rejected + $deferred + $executed + $cancelled;
        $acceptanceRate = $decided > 0 ? (($accepted + $executed) / $decided) * 100 : null;

        $metricMap = [
            'portfolio_value' => (float) ($summary['portfolio_value'] ?? 0),
            'invested_value' => (float) ($summary['invested_value'] ?? 0),
            'unrealized_profit' => (float) ($summary['unrealized_profit'] ?? 0),
            'realized_profit' => (float) ($summary['realized_profit'] ?? 0),
            'xirr' => isset($summary['xirr']) ? (float) $summary['xirr'] : null,
            'win_rate_pct' => $winRate,
            'average_gain' => $avgGain,
            'average_loss' => $avgLoss,
            'profit_factor' => $profitFactor,
            'expectancy' => $expectancy,
            'sells_closed' => (float) $closed,
            'recommendation_count' => (float) $recs->count(),
            'actionable_recommendation_count' => (float) $actionable->count(),
            'informational_recommendation_count' => (float) $informational->count(),
            'recommendation_executed' => (float) $executed,
            'recommendation_accepted' => (float) $accepted,
            'recommendation_rejected' => (float) $rejected,
            'recommendation_deferred' => (float) $deferred,
            'recommendation_pending_review' => (float) $pending,
            'informational_published' => (float) $publishedInfo,
            'recommendation_acceptance_rate_pct' => $acceptanceRate,
        ];

        $report = null;
        $metrics = [];

        DB::transaction(function () use ($profile, $periodStart, $periodEnd, $metricMap, $summary, &$report, &$metrics) {
            $report = ReviewReport::query()->create([
                'profile_id' => $profile->id,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'status' => 'completed',
                'generated_at' => now(),
                'summary_json' => [
                    'portfolio_value' => $summary['portfolio_value'] ?? null,
                    'xirr' => $summary['xirr'] ?? null,
                    'methodology' => [
                        'win_rate' => 'Share of sell transactions with realized_pl > 0 in period',
                        'profit_factor' => 'Sum gains / sum abs losses on sells',
                        'expectancy' => 'Net realized P/L / closed sells',
                        'acceptance_rate' => '(Accepted + Executed) / decided recommendations in period',
                    ],
                ],
            ]);

            foreach ($metricMap as $name => $value) {
                $metrics[] = ReviewMetric::query()->create([
                    'report_id' => $report->id,
                    'metric_name' => $name,
                    'metric_value' => $value,
                    'created_at' => now(),
                ]);
            }
        });

        $this->logger->event('ReviewEngine', 'review.generated', 'info', 'Review report generated', [
            'profile_id' => $profile->id,
            'report_id' => $report->id,
        ]);

        return ['report' => $report->fresh('metrics'), 'metrics' => $metrics];
    }

    /**
     * @return LengthAwarePaginator<int, ReviewReport>
     */
    public function paginateReports(PortfolioProfile $profile, int $page = 1, int $pageSize = 20): LengthAwarePaginator
    {
        return $this->reports->paginateReports($profile, $page, $pageSize);
    }

    /**
     * @return list<ReviewReport>
     */
    public function listReports(PortfolioProfile $profile, int $limit = 20): array
    {
        return $this->paginateReports($profile, 1, $limit)->items();
    }

    public function findReport(PortfolioProfile $profile, int $id): ?ReviewReport
    {
        return $this->reports->findForProfile($profile, $id);
    }
}
