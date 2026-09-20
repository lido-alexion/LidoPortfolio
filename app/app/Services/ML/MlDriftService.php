<?php

namespace App\Services\ML;

use App\Models\V7\MlDriftCheck;
use App\Models\V7\MlModelVersion;
use Carbon\Carbon;

class MlDriftService
{
    public function __construct(private readonly MlPythonAdapter $adapter) {}

    public function check(MlModelVersion $model, int $windowMonths): MlDriftCheck
    {
        if (! in_array($windowMonths, (array) config('ml.drift.windows_months', [3, 6, 12]), true)) {
            throw new \InvalidArgumentException('Unsupported ML drift window.');
        }
        $from = now()->subMonths($windowMonths);
        $predictions = $model->predictions()->where('shadow', false)->where('as_of', '>=', $from)->get(['score', 'confidence', 'as_of']);
        $result = $this->adapter->run('drift', [
            'predictions' => $predictions->map(fn ($prediction): array => ['score' => (float) $prediction->score, 'confidence' => (float) $prediction->confidence])->all(),
            'minimum_predictions' => (int) config('ml.drift.minimum_predictions', 30),
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
