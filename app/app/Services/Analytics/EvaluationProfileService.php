<?php

namespace App\Services\Analytics;

use App\Models\EvaluationResult;
use App\Models\EvaluationRun;
use App\Models\PortfolioProfile;
use App\Models\Stock;

/**
 * SD-031 — Evaluation Profile owner (reads Evaluation Engine outputs only).
 */
class EvaluationProfileService
{
    /**
     * @return array<string, mixed>
     */
    public function forStock(PortfolioProfile $profile, Stock $stock): array
    {
        $result = EvaluationResult::query()
            ->whereHas('evaluationRun', function ($q) use ($profile) {
                $q->where('profile_id', $profile->id)->where('status', 'completed');
            })
            ->whereHas('candidate', fn ($q) => $q->where('security_id', $stock->id))
            ->orderByDesc('id')
            ->first();

        if (! $result) {
            // Fallback: latest result globally for this security on profile runs
            $runIds = EvaluationRun::query()
                ->where('profile_id', $profile->id)
                ->where('status', 'completed')
                ->orderByDesc('id')
                ->limit(5)
                ->pluck('id');

            $result = EvaluationResult::query()
                ->whereIn('evaluation_run_id', $runIds)
                ->whereHas('candidate', fn ($q) => $q->where('security_id', $stock->id))
                ->orderByDesc('id')
                ->first();
        }

        if (! $result) {
            return [
                'owner' => 'evaluation_engine',
                'stock_id' => $stock->id,
                'symbol' => $stock->symbol,
                'available' => false,
                'message' => 'No evaluation profile yet. Run Discovery → Evaluation for this stock.',
            ];
        }

        $evidence = is_array($result->evidence) ? $result->evidence : [];
        $scores = $evidence['indicator_scores']
            ?? $evidence['factor_scores']
            ?? $evidence['component_scores']
            ?? [];

        return [
            'owner' => 'evaluation_engine',
            'available' => true,
            'stock_id' => $stock->id,
            'symbol' => $stock->symbol,
            'evaluation_result_id' => $result->id,
            'evaluation_run_id' => $result->evaluation_run_id,
            'overall_evaluation_score' => round((float) $result->score, 2),
            'confidence' => $result->confidence !== null ? round((float) $result->confidence, 2) : null,
            'rank' => $result->rank,
            'momentum_score' => $this->num($scores, 'momentum_score', 'momentum'),
            'trend_score' => $this->num($scores, 'trend_score', 'trend'),
            'breakout_score' => $this->num($scores, 'breakout_score', 'pattern_bonus'),
            'volume_score' => $this->num($scores, 'volume_score', 'volume'),
            'risk_score' => $this->num($scores, 'risk_score', 'risk'),
            'sector_strength' => $this->num($scores, 'sector_strength'),
            'market_alignment' => $this->num($scores, 'market_regime'),
            'relative_strength' => $this->num($scores, 'relative_strength'),
            'passed_rules' => $result->passed_rules ?? [],
            'failed_rules' => $result->failed_rules ?? [],
            'computed_at' => optional($result->updated_at)?->toIso8601String(),
        ];
    }

    protected function num(array $scores, string $key, ?string $alias = null): ?float
    {
        if (isset($scores[$key]) && is_numeric($scores[$key])) {
            return round((float) $scores[$key], 2);
        }
        if ($alias !== null && isset($scores[$alias]) && is_numeric($scores[$alias])) {
            return round((float) $scores[$alias], 2);
        }

        return null;
    }
}
