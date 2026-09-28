<?php

namespace App\Services\ML;

use App\Models\V7\MlTrainingRun;

class MlTrainingRunAdminService
{
    /**
     * @return array<string, mixed>
     */
    public function list(?string $horizon = null, int $limit = 25): array
    {
        $query = MlTrainingRun::query()->orderByDesc('id');
        if ($horizon !== null && $horizon !== '') {
            $query->where('horizon', $horizon);
        }

        $runs = $query->limit(max(1, min(100, $limit)))->get();

        return [
            'runs' => $runs->map(fn (MlTrainingRun $run) => $this->serialize($run))->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function show(MlTrainingRun $run): array
    {
        return $this->serialize($run->fresh());
    }

    /**
     * @return array<string, mixed>
     */
    public function progressPayload(MlTrainingRun $run): array
    {
        $fresh = $run->fresh();
        $progress = is_array($fresh->configuration['progress'] ?? null)
            ? $fresh->configuration['progress']
            : [];

        return [
            'run_id' => $fresh->id,
            'horizon' => $fresh->horizon,
            'status' => $fresh->status,
            'progress' => $progress,
            'failure' => $fresh->failure,
            'started_at' => $fresh->started_at?->toIso8601String(),
            'completed_at' => $fresh->completed_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function serialize(MlTrainingRun $run): array
    {
        $configuration = $run->configuration ?? [];
        $progress = is_array($configuration['progress'] ?? null) ? $configuration['progress'] : null;

        $chrono = is_array($configuration['chronological_validation_grid'] ?? null)
            ? $configuration['chronological_validation_grid']
            : null;
        $challenger = is_array($configuration['challenger_evidence'] ?? null)
            ? $configuration['challenger_evidence']
            : null;
        $returnRegressor = is_array($configuration['return_regressor_evidence'] ?? null)
            ? $configuration['return_regressor_evidence']
            : null;

        return [
            'id' => $run->id,
            'horizon' => $run->horizon,
            'status' => $run->status,
            'cutoff_date' => $run->cutoff_date?->toDateString(),
            'trigger' => $configuration['trigger'] ?? 'manual',
            'progress' => $progress,
            'failure' => $run->failure,
            'metrics' => $run->metrics,
            'requested_by' => $run->requested_by,
            'started_at' => $run->started_at?->toIso8601String(),
            'completed_at' => $run->completed_at?->toIso8601String(),
            'challenger_evidence' => $challenger,
            'return_regressor_evidence' => $returnRegressor,
            'chronological_validation_grid' => $chrono ? [
                'version' => $chrono['version'] ?? null,
                'test_window_count' => $chrono['test_window_count'] ?? null,
                'test_positive_rate_stability' => $chrono['test_positive_rate_stability'] ?? null,
                'regime_slices' => $chrono['regime_slices'] ?? null,
            ] : null,
        ];
    }
}
