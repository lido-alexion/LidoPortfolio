<?php

namespace App\Services\ML;

use App\Models\V7\MlDriftCheck;
use App\Models\V7\MlModelVersion;
use App\Models\V7\MlTrainingRun;
use Carbon\Carbon;

/**
 * FEAT-056 — decide whether a recent material drift check should queue early retraining.
 */
class MlDriftTriggerEvaluator
{
    public function isEnabled(): bool
    {
        return (bool) config('ml_lifecycle.drift_trigger.enabled', false);
    }

    /**
     * @return array{drift_check_id:int,warnings:list<string>}|null
     */
    public function evaluateHorizon(string $horizon, ?Carbon $now = null): ?array
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $this->assertHorizon($horizon);
        $now ??= now();

        if ($this->recentDriftTriggeredRun($horizon, $now) !== null) {
            return null;
        }

        $model = MlModelVersion::query()
            ->where('horizon', $horizon)
            ->where('status', 'active')
            ->orderByDesc('version')
            ->first();

        if ($model === null) {
            return null;
        }

        $check = $this->latestMaterialCheck($model, $now);
        if ($check === null) {
            return null;
        }

        return [
            'drift_check_id' => $check->id,
            'warnings' => array_values(array_intersect(
                (array) config('ml_lifecycle.drift_trigger.warnings', []),
                $check->warnings ?? [],
            )),
        ];
    }

    public function latestMaterialCheck(MlModelVersion $model, ?Carbon $now = null): ?MlDriftCheck
    {
        $now ??= now();
        $windowMonths = (int) config('ml_lifecycle.drift_trigger.window_months', 3);
        $maxAgeHours = (int) config('ml_lifecycle.drift_trigger.max_check_age_hours', 168);
        $requireStatus = (string) config('ml_lifecycle.drift_trigger.require_status', 'warning');
        $triggerWarnings = (array) config('ml_lifecycle.drift_trigger.warnings', []);

        $check = MlDriftCheck::query()
            ->where('model_version_id', $model->id)
            ->where('window_months', $windowMonths)
            ->where('status', $requireStatus)
            ->where('checked_at', '>=', $now->copy()->subHours($maxAgeHours))
            ->orderByDesc('checked_at')
            ->first();

        if ($check === null || $triggerWarnings === []) {
            return null;
        }

        $matched = array_intersect($triggerWarnings, $check->warnings ?? []);
        if ($matched === []) {
            return null;
        }

        return $check;
    }

    public function recentDriftTriggeredRun(string $horizon, ?Carbon $now = null): ?MlTrainingRun
    {
        $now ??= now();
        $cooldownHours = (int) config('ml_lifecycle.drift_trigger.cooldown_hours', 168);
        $since = $now->copy()->subHours($cooldownHours);

        return MlTrainingRun::query()
            ->where('horizon', $horizon)
            ->where('started_at', '>=', $since)
            ->orderByDesc('id')
            ->get()
            ->first(fn (MlTrainingRun $run): bool => ($run->configuration['trigger'] ?? null) === 'drift');
    }

    protected function assertHorizon(string $horizon): void
    {
        if (! in_array($horizon, MlScoringService::HORIZONS, true)) {
            throw new \InvalidArgumentException('Unsupported ML horizon: '.$horizon);
        }
    }
}
