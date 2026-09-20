<?php

namespace App\Services\ML;

use App\Models\V7\MlDriftCheck;
use App\Models\V7\MlModelVersion;
use Carbon\Carbon;

class MlDriftService
{
    public function __construct(
        private readonly MlPythonAdapter $adapter,
        private readonly MlTrainingDatasetBuilder $datasets,
    ) {}

    public function check(MlModelVersion $model, int $windowMonths): MlDriftCheck
    {
        if (! in_array($windowMonths, (array) config('ml.drift.windows_months', [3, 6, 12]), true)) {
            throw new \InvalidArgumentException('Unsupported ML drift window.');
        }
        $from = now()->subMonths($windowMonths);
        $now = now();
        $predictions = $model->predictions()->with('stock')->where('shadow', false)->where('as_of', '>=', $from)->get(['id', 'stock_id', 'horizon', 'score', 'confidence', 'as_of']);
        $matured = [];
        foreach ($predictions as $prediction) {
            $outcome = $this->datasets->realizedOutcome($prediction->stock, $prediction->as_of, $prediction->horizon, $now);
            if ($outcome !== null) {
                $matured[] = [
                    'score' => (float) $prediction->score,
                    'confidence' => (float) $prediction->confidence,
                    ...$outcome,
                ];
            }
        }
        $ageDays = $model->promoted_at?->diffInDays($now) ?? $model->training_cutoff_date?->diffInDays($now);
        $result = $this->adapter->run('drift', [
            'predictions' => $matured,
            'minimum_predictions' => (int) config('ml.drift.minimum_matured_predictions', 30),
            'model_age_days' => $ageDays,
            'age_warning_days' => (int) config('ml.drift.model_age_warning_days', 365),
            'baseline_metrics' => $model->evaluation_metrics['test'] ?? $model->evaluation_metrics ?? [],
        ]);

        return MlDriftCheck::query()->create([
            'model_version_id' => $model->id,
            'window_months' => $windowMonths,
            'status' => $result['status'],
            'metrics' => $result['metrics'] ?? [],
            'warnings' => $result['warnings'] ?? [],
            'checked_at' => Carbon::now(),
        ]);
    }
}
