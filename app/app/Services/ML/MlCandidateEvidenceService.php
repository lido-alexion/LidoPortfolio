<?php

namespace App\Services\ML;

use App\Models\V7\MlCandidateEvidenceArchive;
use App\Models\V7\MlModelVersion;

/** FEAT-057 evidence only; promotion and lifecycle decisions remain FEAT-056. */
class MlCandidateEvidenceService
{
    /** @return array<string,mixed> */
    public function persist(MlModelVersion $candidate): array
    {
        $existing = MlCandidateEvidenceArchive::query()
            ->where('model_version_id', $candidate->id)
            ->first();
        if ($existing !== null) {
            $audit = is_array($candidate->audit_metadata) ? $candidate->audit_metadata : [];
            $candidate->forceFill(['audit_metadata' => array_replace_recursive($audit, ['candidate_evidence' => $existing->evidence])])->save();

            return $existing->evidence;
        }

        $evidence = $this->build($candidate);
        $encoded = json_encode($evidence, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $featureSetVersion = data_get($candidate->audit_metadata, 'feature_profile.feature_set_version');
        $datasetVersion = data_get($candidate->audit_metadata, 'dataset.version')
            ?? data_get($candidate->trainingRun?->configuration, 'dataset.version');
        MlCandidateEvidenceArchive::query()->create([
            'model_version_id' => $candidate->id,
            'horizon' => $candidate->horizon,
            'model_version' => $candidate->version,
            'feature_set_version' => $featureSetVersion,
            'dataset_version' => $datasetVersion,
            'artifact_path' => $candidate->artifact_path,
            'artifact_sha256' => $candidate->artifact_sha256,
            'evidence_sha256' => hash('sha256', $encoded),
            'evidence' => $evidence,
            'archived_at' => now(),
        ]);
        $audit = is_array($candidate->audit_metadata) ? $candidate->audit_metadata : [];
        $candidate->forceFill(['audit_metadata' => array_replace_recursive($audit, ['candidate_evidence' => $evidence])])->save();

        return $evidence;
    }

    /** @return array<string,mixed> */
    public function build(MlModelVersion $candidate): array
    {
        $run = $candidate->trainingRun;
        $active = MlModelVersion::query()
            ->where('horizon', $candidate->horizon)
            ->where('status', 'active')
            ->whereKeyNot($candidate->id)
            ->latest('version')
            ->first();
        $candidateMetrics = $this->metrics($candidate->evaluation_metrics ?? []);
        $activeMetrics = $active ? $this->metrics($active->evaluation_metrics ?? []) : null;
        $activeComparable = $active ? $this->sameEvaluationWindow($candidate, $active) : false;
        $baseline = is_array($run?->baselines) ? ($run->baselines['deterministic_stox'] ?? null) : null;
        $baselineMetrics = is_array($baseline) ? $this->metrics($baseline) : null;

        return [
            'contract_version' => 'v8-candidate-evidence-1',
            'candidate_id' => $candidate->id,
            'horizon' => $candidate->horizon,
            'model_version' => $candidate->version,
            'feature_set_version' => data_get($candidate->audit_metadata, 'feature_profile.feature_set_version'),
            'dataset_version' => data_get($candidate->audit_metadata, 'dataset.version') ?? data_get($run?->configuration, 'dataset.version'),
            'validation_split_id' => data_get($candidate->audit_metadata, 'chronological_split.split_basis') ?? data_get($run?->configuration, 'dataset.split_basis'),
            'candidate_metrics' => $candidateMetrics,
            'active_model' => $active ? [
                'model_id' => $active->id,
                'model_version' => $active->version,
                'feature_set_version' => data_get($active->audit_metadata, 'feature_profile.feature_set_version'),
                'metrics' => $activeMetrics,
                'validation_split_id' => data_get($active->audit_metadata, 'chronological_split.split_basis'),
                'delta' => $activeComparable ? $this->delta($candidateMetrics, $activeMetrics) : null,
                'comparable' => $activeComparable,
                'unavailable_reason' => $activeComparable ? null : 'evaluation_window_mismatch',
            ] : [
                'model_id' => null,
                'model_version' => null,
                'metrics' => null,
                'delta' => null,
                'comparable' => false,
                'unavailable_reason' => 'no_active_model_for_horizon',
            ],
            'deterministic_baseline' => [
                'definition' => data_get($candidate->audit_metadata, 'deterministic_baseline'),
                'metrics' => $baselineMetrics,
                'delta' => $this->delta($candidateMetrics, $baselineMetrics),
                'comparable' => $baselineMetrics !== null,
                'unavailable_reason' => $baselineMetrics === null ? 'baseline_metrics_unavailable' : null,
            ],
            'calibration_metrics' => $candidateMetrics['calibration'] ?? null,
            'sample_count' => $candidateMetrics['class_distribution']['rows'] ?? null,
            'artifact' => [
                'path' => $candidate->artifact_path,
                'sha256' => $candidate->artifact_sha256,
            ],
            'created_at' => now()->toIso8601String(),
        ];
    }

    /** @param array<string,mixed> $metrics */
    private function metrics(array $metrics): array
    {
        if (is_array($metrics['test'] ?? null)) {
            $metrics = array_replace($metrics, $metrics['test']);
        }

        return array_intersect_key($metrics, array_flip(['roc_auc', 'pr_auc', 'benchmark_relative_return', 'deterministic_baseline_delta', 'hit_rate', 'calibration', 'class_distribution']));
    }

    /** @param array<string,mixed>|null $left @param array<string,mixed>|null $right */
    private function delta(?array $left, ?array $right): ?array
    {
        if ($left === null || $right === null) return null;
        $out = [];
        foreach (['roc_auc', 'pr_auc', 'benchmark_relative_return', 'hit_rate'] as $key) {
            if (is_numeric($left[$key] ?? null) && is_numeric($right[$key] ?? null)) {
                $out[$key] = round((float) $left[$key] - (float) $right[$key], 8);
            }
        }
        return $out;
    }

    private function sameEvaluationWindow(MlModelVersion $candidate, MlModelVersion $active): bool
    {
        return data_get($candidate->audit_metadata, 'chronological_split') === data_get($active->audit_metadata, 'chronological_split');
    }
}
