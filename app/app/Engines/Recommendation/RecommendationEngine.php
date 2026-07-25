<?php

namespace App\Engines\Recommendation;

use App\Models\EvaluationResult;
use App\Models\EvaluationRun;
use App\Models\Holding;
use App\Models\PortfolioProfile;
use App\Models\RecommendationReview;
use App\Models\StockPrice;
use App\Models\TradingRecommendation;
use App\Models\User;
use App\Services\PortfolioCalculationService;
use App\Services\PortfolioLoggerService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Recommendation Engine — converts ranked opportunities into BUY/SELL/WATCH/HOLD
 * and owns the user-review lifecycle (pending_review → accepted|rejected|deferred).
 */
class RecommendationEngine
{
    public function __construct(
        protected PortfolioCalculationService $portfolio,
        protected PortfolioLoggerService $logger,
    ) {}

    /**
     * @return array{recommendations: list<TradingRecommendation>, batch_id: string}
     */
    public function generate(PortfolioProfile $profile, ?EvaluationRun $evaluationRun = null): array
    {
        $evaluationRun ??= EvaluationRun::query()
            ->where('profile_id', $profile->id)
            ->where('status', 'completed')
            ->orderByDesc('id')
            ->first();

        if (! $evaluationRun) {
            throw new \RuntimeException('No completed evaluation run available for recommendations.');
        }

        $config = config('trading_os.recommendation', []);
        $buyMin = (float) ($config['buy_score_min'] ?? 65);
        $watchMin = (float) ($config['watch_score_min'] ?? 45);
        $sellMax = (float) ($config['sell_score_max'] ?? 35);
        $expiryHours = (int) ($config['expiry_hours'] ?? 48);
        $defaultPct = (float) ($config['default_position_pct'] ?? 5);
        $riskCfg = $config['risk'] ?? [];

        $results = EvaluationResult::query()
            ->where('evaluation_run_id', $evaluationRun->id)
            ->with(['candidate.security'])
            ->orderBy('rank')
            ->get();

        $heldIds = Holding::query()
            ->where('profile_id', $profile->id)
            ->where('quantity', '>', 0)
            ->pluck('quantity', 'stock_id')
            ->all();

        $portfolioValue = 0.0;
        try {
            $summary = $this->portfolio->calculateForProfile($profile);
            $portfolioValue = (float) ($summary['portfolio_value'] ?? 0);
        } catch (Throwable) {
            $portfolioValue = 0.0;
        }

        $expiresAt = Carbon::now()->addHours($expiryHours);
        $created = [];

        DB::transaction(function () use (
            $profile,
            $results,
            $heldIds,
            $buyMin,
            $watchMin,
            $sellMax,
            $expiresAt,
            $defaultPct,
            $portfolioValue,
            $riskCfg,
            &$created,
        ) {
            // New batch supersedes unreviewed / deferred items only.
            TradingRecommendation::query()
                ->where('profile_id', $profile->id)
                ->whereIn('status', [
                    TradingRecommendation::STATUS_PENDING_REVIEW,
                    TradingRecommendation::STATUS_DEFERRED,
                    'active', // legacy
                ])
                ->update(['status' => TradingRecommendation::STATUS_CANCELLED]);

            foreach ($results as $result) {
                $securityId = (int) $result->candidate->security_id;
                $score = (float) $result->score;
                $isHeld = isset($heldIds[$securityId]) && (float) $heldIds[$securityId] > 0;
                $atrPct = $result->evidence['indicators']['atr_pct'] ?? null;
                $close = $result->evidence['indicators']['close'] ?? null;
                $referencePrice = is_numeric($close)
                    ? (float) $close
                    : $this->latestClose($securityId);

                $type = $this->decideType($score, $isHeld, $buyMin, $watchMin, $sellMax);
                $risk = $this->riskLevel($atrPct, $riskCfg);
                $failedChecks = $result->failed_rules ?? [];
                $positionSize = null;
                if ($type === 'BUY' && $portfolioValue > 0) {
                    $positionSize = round($portfolioValue * ($defaultPct / 100.0), 2);
                }

                $priority = (int) max(1, min(100, round($score)));

                $created[] = TradingRecommendation::query()->create([
                    'profile_id' => $profile->id,
                    'evaluation_result_id' => $result->id,
                    'security_id' => $securityId,
                    'recommendation_type' => $type,
                    'priority' => $priority,
                    'confidence' => $result->confidence,
                    'risk_level' => $risk,
                    'suggested_position_size' => $positionSize,
                    'reference_price' => $referencePrice,
                    'status' => TradingRecommendation::STATUS_PENDING_REVIEW,
                    'evidence' => [
                        'score' => $score,
                        'rank' => $result->rank,
                        'passed_rules' => $result->passed_rules,
                        'failed_rules' => $result->failed_rules,
                        'indicators' => $result->evidence['indicators'] ?? [],
                        'discovery' => $result->evidence['discovery'] ?? [],
                        'held' => $isHeld,
                    ],
                    'failed_checks' => $failedChecks,
                    'version' => 1,
                    'expires_at' => $expiresAt,
                    'generated_at' => now(),
                ]);
            }
        });

        $this->logger->log('daily', 'RecommendationEngine', 'info', 'Recommendations generated', [
            'profile_id' => $profile->id,
            'count' => count($created),
            'evaluation_run_id' => $evaluationRun->id,
        ]);

        return [
            'recommendations' => $created,
            'batch_id' => 'eval-'.$evaluationRun->id.'-'.now()->format('YmdHis'),
        ];
    }

    /**
     * Record a user review decision (accepted | rejected | deferred).
     */
    public function recordReview(
        PortfolioProfile $profile,
        User $user,
        TradingRecommendation $recommendation,
        string $decision,
        ?string $notes = null,
    ): TradingRecommendation {
        if ((int) $recommendation->profile_id !== (int) $profile->id) {
            throw ValidationException::withMessages([
                'recommendation' => ['Recommendation does not belong to this portfolio.'],
            ]);
        }

        $decision = strtolower(trim($decision));
        if (! in_array($decision, TradingRecommendation::REVIEW_DECISIONS, true)) {
            throw ValidationException::withMessages([
                'decision' => ['Decision must be accepted, rejected, or deferred.'],
            ]);
        }

        if ($recommendation->status === TradingRecommendation::STATUS_EXECUTED) {
            throw ValidationException::withMessages([
                'decision' => ['Executed recommendations cannot be reviewed.'],
            ]);
        }

        if ($recommendation->status === TradingRecommendation::STATUS_REJECTED
            && $decision !== TradingRecommendation::STATUS_REJECTED) {
            throw ValidationException::withMessages([
                'decision' => ['Rejected recommendations are final.'],
            ]);
        }

        if (! $recommendation->canBeReviewed()
            && $recommendation->status !== TradingRecommendation::STATUS_REJECTED) {
            throw ValidationException::withMessages([
                'decision' => ['Recommendation is not open for review (status: '.$recommendation->status.').'],
            ]);
        }

        DB::transaction(function () use ($recommendation, $user, $decision, $notes) {
            RecommendationReview::query()->create([
                'recommendation_id' => $recommendation->id,
                'user_id' => $user->id,
                'decision' => $decision,
                'notes' => $notes,
                'created_at' => now(),
            ]);

            $recommendation->forceFill(['status' => $decision])->save();
        });

        $this->logger->log('daily', 'RecommendationEngine', 'info', 'Recommendation reviewed', [
            'recommendation_id' => $recommendation->id,
            'decision' => $decision,
            'user_id' => $user->id,
        ]);

        return $recommendation->fresh(['security', 'evaluationResult', 'reviews']);
    }

    protected function latestClose(int $securityId): ?float
    {
        $close = StockPrice::query()
            ->where('stock_id', $securityId)
            ->orderByDesc('price_date')
            ->value('close_price');

        return $close !== null ? (float) $close : null;
    }

    protected function decideType(float $score, bool $isHeld, float $buyMin, float $watchMin, float $sellMax): string
    {
        if ($isHeld) {
            if ($score <= $sellMax) {
                return 'SELL';
            }

            return 'HOLD';
        }

        if ($score >= $buyMin) {
            return 'BUY';
        }
        if ($score >= $watchMin) {
            return 'WATCH';
        }

        return 'WATCH';
    }

    /**
     * @param  array<string,mixed>  $riskCfg
     */
    protected function riskLevel(mixed $atrPct, array $riskCfg): string
    {
        if (! is_numeric($atrPct)) {
            return 'medium';
        }
        $v = (float) $atrPct;
        if ($v >= (float) ($riskCfg['high_atr_pct'] ?? 4.0)) {
            return 'high';
        }
        if ($v >= (float) ($riskCfg['medium_atr_pct'] ?? 2.0)) {
            return 'medium';
        }

        return 'low';
    }

    public function expireStale(PortfolioProfile $profile): int
    {
        return TradingRecommendation::query()
            ->where('profile_id', $profile->id)
            ->whereIn('status', [
                TradingRecommendation::STATUS_PENDING_REVIEW,
                TradingRecommendation::STATUS_DEFERRED,
                'active',
            ])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->update(['status' => TradingRecommendation::STATUS_EXPIRED]);
    }

    /**
     * @param  list<string>|null  $statuses
     * @return list<TradingRecommendation>
     */
    public function listForProfile(PortfolioProfile $profile, ?array $statuses = null, int $limit = 100): array
    {
        $this->expireStale($profile);

        $query = TradingRecommendation::query()
            ->with(['security', 'evaluationResult', 'reviews'])
            ->where('profile_id', $profile->id);

        if ($statuses !== null && $statuses !== []) {
            $query->whereIn('status', $statuses);
        }

        return $query
            ->orderByDesc('priority')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->all();
    }

    /**
     * Open items awaiting user action (pending review + deferred).
     *
     * @return list<TradingRecommendation>
     */
    public function listOpenForReview(PortfolioProfile $profile): array
    {
        return $this->listForProfile($profile, [
            TradingRecommendation::STATUS_PENDING_REVIEW,
            TradingRecommendation::STATUS_DEFERRED,
            TradingRecommendation::STATUS_ACCEPTED,
        ]);
    }

    /** @deprecated use listOpenForReview */
    public function listActive(PortfolioProfile $profile): array
    {
        return $this->listOpenForReview($profile);
    }

    public function findForProfile(PortfolioProfile $profile, int $id): ?TradingRecommendation
    {
        return TradingRecommendation::query()
            ->with(['security', 'evaluationResult.candidate', 'reviews.user', 'orders'])
            ->where('profile_id', $profile->id)
            ->where('id', $id)
            ->first();
    }

    /**
     * @return list<TradingRecommendation>
     */
    public function history(PortfolioProfile $profile, int $limit = 50): array
    {
        return $this->listForProfile($profile, null, $limit);
    }

    /**
     * @return list<RecommendationReview>
     */
    public function reviewHistory(TradingRecommendation $recommendation): array
    {
        return RecommendationReview::query()
            ->with('user')
            ->where('recommendation_id', $recommendation->id)
            ->orderByDesc('id')
            ->get()
            ->all();
    }
}
