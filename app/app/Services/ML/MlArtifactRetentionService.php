<?php

namespace App\Services\ML;

use App\Models\V7\MlModelVersion;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * FEAT-056 §15 — bounded retained artifact pruning (never touches active).
 */
class MlArtifactRetentionService
{
    public function isEnabled(): bool
    {
        return (bool) config('ml_lifecycle.retention.enabled', false);
    }

    /**
     * @return array{horizons:list<array<string,mixed>>}
     */
    public function plan(): array
    {
        $horizons = [];
        foreach (MlScoringService::HORIZONS as $horizon) {
            $horizons[] = [
                'horizon' => $horizon,
                'would_prune' => $this->modelsToPrune($horizon)->map(fn (MlModelVersion $m) => [
                    'id' => $m->id,
                    'version' => $m->version,
                    'artifact_path' => $m->artifact_path,
                ])->values()->all(),
            ];
        }

        return [
            'enabled' => $this->isEnabled(),
            'max_retained_per_horizon' => max(1, (int) config('ml_lifecycle.retention.max_retained_per_horizon', 3)),
            'lifecycle_automation_enabled' => (bool) config('ml_lifecycle.enabled', false),
            'horizons' => $horizons,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function pruneAll(bool $dryRun = true): array
    {
        if (! $this->isEnabled() && ! $dryRun) {
            return [];
        }

        $out = [];
        foreach (MlScoringService::HORIZONS as $horizon) {
            $out = [...$out, ...$this->pruneHorizon($horizon, $dryRun)];
        }

        return $out;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function pruneHorizon(string $horizon, bool $dryRun = true): array
    {
        $pruned = [];
        foreach ($this->modelsToPrune($horizon) as $model) {
            $pruned[] = $this->pruneModel($model, $dryRun);
        }

        return $pruned;
    }

    /**
     * @return \Illuminate\Support\Collection<int, MlModelVersion>
     */
    protected function modelsToPrune(string $horizon)
    {
        $max = max(1, (int) config('ml_lifecycle.retention.max_retained_per_horizon', 3));
        $retained = MlModelVersion::query()
            ->where('horizon', $horizon)
            ->where('status', 'retained')
            ->orderByDesc('version')
            ->get();

        return $retained->slice($max)->values();
    }

    /**
     * @return array<string,mixed>
     */
    protected function pruneModel(MlModelVersion $model, bool $dryRun): array
    {
        $path = $model->artifact_path;
        $entry = [
            'model_id' => $model->id,
            'horizon' => $model->horizon,
            'version' => $model->version,
            'artifact_path' => $path,
            'dry_run' => $dryRun,
        ];

        if ($dryRun) {
            return $entry;
        }

        if ($path !== null && is_file($path)) {
            @unlink($path);
        }

        $audit = $model->audit_metadata ?? [];
        $audit['artifact_pruned_at'] = now()->toIso8601String();
        $audit['artifact_pruned_from'] = $path;

        $model->forceFill([
            'status' => 'pruned',
            'artifact_path' => null,
            'audit_metadata' => $audit,
        ])->save();

        Log::info('ml_lifecycle.artifact_pruned', $entry);

        return $entry;
    }
}
