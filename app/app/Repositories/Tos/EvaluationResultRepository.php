<?php

namespace App\Repositories\Tos;

use App\Models\EvaluationResult;
use App\Models\EvaluationRun;
use App\Models\PortfolioProfile;

/**
 * V4-FEAT-032 — Evaluation run/result list and lookup queries.
 * Scoring, factor rules, and run orchestration stay in EvaluationEngine.
 */
class EvaluationResultRepository
{
    /**
     * @return list<EvaluationRun>
     */
    public function listRuns(PortfolioProfile $profile, int $limit = 20): array
    {
        return EvaluationRun::query()
            ->where('profile_id', $profile->id)
            ->withCount('results')
            ->orderByDesc('id')
            ->limit(max(1, min($limit, 50)))
            ->get()
            ->all();
    }

    public function latestCompletedId(PortfolioProfile $profile): ?int
    {
        $id = EvaluationRun::query()
            ->where('profile_id', $profile->id)
            ->where('status', 'completed')
            ->orderByDesc('id')
            ->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * @return list<EvaluationResult>
     */
    public function listResults(?int $evaluationRunId = null, ?PortfolioProfile $profile = null): array
    {
        $query = EvaluationResult::query()->with(['candidate.security', 'evaluationRun']);

        if ($evaluationRunId) {
            $query->where('evaluation_run_id', $evaluationRunId);
            if ($profile) {
                $query->whereHas('evaluationRun', fn ($run) => $run->where('profile_id', $profile->id));
            }
        } elseif ($profile) {
            $latest = $this->latestCompletedId($profile);
            if (! $latest) {
                return [];
            }
            $query->where('evaluation_run_id', $latest);
        }

        return $query->orderBy('rank')->get()->all();
    }
}
