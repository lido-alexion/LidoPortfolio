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
use App\Services\PortfolioCalculationService;
use App\Services\PortfolioLoggerService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Review Engine — observational metrics only; never mutates historical facts.
 */
class ReviewEngine
{
    public function __construct(
        protected PortfolioCalculationService $portfolio,
        protected PortfolioLoggerService $logger,
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
            ->where('profile_id', $profile->id)
            ->get();

        $byStatus = [];
        foreach ($recs as $rec) {
            $byStatus[$rec->status] = ($byStatus[$rec->status] ?? 0) + 1;
        }

        $accepted = (int) ($byStatus[TradingRecommendation::STATUS_ACCEPTED] ?? 0);
        $rejected = (int) ($byStatus[TradingRecommendation::STATUS_REJECTED] ?? 0);
        $deferred = (int) ($byStatus[TradingRecommendation::STATUS_DEFERRED] ?? 0);
        $executed = (int) ($byStatus[TradingRecommendation::STATUS_EXECUTED] ?? 0);
        $pending = (int) ($byStatus[TradingRecommendation::STATUS_PENDING_REVIEW] ?? 0)
            + (int) ($byStatus['active'] ?? 0);
        $decided = $accepted + $rejected + $deferred + $executed;
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
            ->whereHas('recommendation', fn ($q) => $q->where('profile_id', $profile->id))
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
                'created_at' => optional($r->created_at)?->toIso8601String(),
            ])
            ->all();

        return [
            'portfolio' => [
                'portfolio_value' => $summary['portfolio_value'] ?? null,
                'invested_value' => $summary['invested_value'] ?? null,
                'unrealized_profit' => $summary['unrealized_profit'] ?? null,
                'realized_profit' => $summary['realized_profit'] ?? null,
                'xirr' => $summary['xirr'] ?? null,
            ],
            'recommendation_counts' => [
                'total' => $recs->count(),
                'pending_review' => $pending,
                'accepted' => $accepted,
                'rejected' => $rejected,
                'deferred' => $deferred,
                'executed' => $executed,
                'expired' => (int) ($byStatus[TradingRecommendation::STATUS_EXPIRED] ?? 0),
                'cancelled' => (int) ($byStatus[TradingRecommendation::STATUS_CANCELLED] ?? 0),
                'acceptance_rate_pct' => $acceptanceAmongDecided,
                'by_status' => $byStatus,
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
            'outcomes' => $this->recommendationOutcomes($profile, 50),
        ];
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
            ->where('profile_id', $profile->id)
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
                // For SELL recommendations, a price drop is a favourable outcome.
                if (strtoupper((string) $rec->recommendation_type) === 'SELL') {
                    $gainPct = -$gainPct;
                    $gainAbs = -$gainAbs;
                }
            }

            $out[] = [
                'recommendation_id' => $rec->id,
                'symbol' => $rec->security?->symbol,
                'name' => $rec->security?->name,
                'recommendation_type' => $rec->recommendation_type,
                'status' => $rec->status,
                'reference_price' => $ref,
                'current_price' => $current,
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
        $lookback = (int) config('trading_os.review.default_lookback_days', 90);
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
            ->where('profile_id', $profile->id)
            ->where('generated_at', '>=', $periodStart)
            ->where('generated_at', '<=', $periodEnd->copy()->endOfDay())
            ->get();

        $executed = $recs->where('status', TradingRecommendation::STATUS_EXECUTED)->count();
        $accepted = $recs->where('status', TradingRecommendation::STATUS_ACCEPTED)->count();
        $rejected = $recs->where('status', TradingRecommendation::STATUS_REJECTED)->count();
        $deferred = $recs->where('status', TradingRecommendation::STATUS_DEFERRED)->count();
        $pending = $recs->whereIn('status', [
            TradingRecommendation::STATUS_PENDING_REVIEW,
            'active',
        ])->count();
        $decided = $accepted + $rejected + $deferred + $executed;
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
            'recommendation_executed' => (float) $executed,
            'recommendation_accepted' => (float) $accepted,
            'recommendation_rejected' => (float) $rejected,
            'recommendation_deferred' => (float) $deferred,
            'recommendation_pending_review' => (float) $pending,
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

        $this->logger->log('daily', 'ReviewEngine', 'info', 'Review report generated', [
            'profile_id' => $profile->id,
            'report_id' => $report->id,
        ]);

        return ['report' => $report->fresh('metrics'), 'metrics' => $metrics];
    }

    /**
     * @return list<ReviewReport>
     */
    public function listReports(PortfolioProfile $profile, int $limit = 20): array
    {
        return ReviewReport::query()
            ->with('metrics')
            ->where('profile_id', $profile->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->all();
    }

    public function findReport(PortfolioProfile $profile, int $id): ?ReviewReport
    {
        return ReviewReport::query()
            ->with('metrics')
            ->where('profile_id', $profile->id)
            ->where('id', $id)
            ->first();
    }
}
