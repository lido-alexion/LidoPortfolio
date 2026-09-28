<?php

namespace Tests\Feature\V8;

use App\Models\V7\MlCandidateEvidenceArchive;
use App\Models\V7\MlModelVersion;
use App\Models\V7\MlTrainingRun;
use App\Services\ML\MlCandidateArchiveIntegrityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class MlCandidateArchiveIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_archive_integrity_verifies_evidence_and_artifact(): void
    {
        $path = storage_path('framework/cache/ml-integrity-'.bin2hex(random_bytes(4)).'.joblib');
        File::put($path, 'candidate-artifact');
        $evidence = ['contract_version' => 'v8-candidate-evidence-1', 'horizon' => '3m'];
        $archive = MlCandidateEvidenceArchive::query()->create($this->attributes($evidence, $path));

        $result = app(MlCandidateArchiveIntegrityService::class)->verify($archive);

        $this->assertTrue($result['valid']);
        $this->assertSame('verified', $result['evidence_status']);
        $this->assertSame('verified', $result['artifact_status']);
        File::delete($path);
    }

    public function test_missing_or_corrupt_artifact_is_reported_without_changing_evidence(): void
    {
        $evidence = ['contract_version' => 'v8-candidate-evidence-1', 'horizon' => '1m'];
        $archive = MlCandidateEvidenceArchive::query()->create($this->attributes($evidence, '/missing/model.joblib'));

        $missing = app(MlCandidateArchiveIntegrityService::class)->verify($archive);
        $this->assertFalse($missing['valid']);
        $this->assertSame('missing', $missing['artifact_status']);
        $this->assertSame('verified', $missing['evidence_status']);

        $path = storage_path('framework/cache/ml-integrity-corrupt-'.bin2hex(random_bytes(4)).'.joblib');
        File::put($path, 'different-artifact');
        $archive->forceFill(['artifact_path' => $path])->save();
        $mismatch = app(MlCandidateArchiveIntegrityService::class)->verify($archive->fresh());
        $this->assertFalse($mismatch['valid']);
        $this->assertSame('mismatch', $mismatch['artifact_status']);
        File::delete($path);
    }

    public function test_evidence_tampering_is_reported(): void
    {
        $evidence = ['contract_version' => 'v8-candidate-evidence-1', 'horizon' => '6m'];
        $archive = MlCandidateEvidenceArchive::query()->create($this->attributes($evidence, null));
        $archive->forceFill(['evidence' => ['tampered' => true]])->save();

        $result = app(MlCandidateArchiveIntegrityService::class)->verify($archive->fresh());

        $this->assertFalse($result['valid']);
        $this->assertSame('mismatch', $result['evidence_status']);
        $this->assertSame('unavailable', $result['artifact_status']);
    }

    /** @return array<string, mixed> */
    private function attributes(array $evidence, ?string $path): array
    {
        $encoded = json_encode($evidence, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return [
            'model_version_id' => $this->modelVersionId((string) $evidence['horizon']),
            'horizon' => $evidence['horizon'],
            'model_version' => 1,
            'feature_set_version' => 'profile-'.$evidence['horizon'].'-v1',
            'dataset_version' => 'dataset-'.$evidence['horizon'].'-v1',
            'artifact_path' => $path,
            'artifact_sha256' => $path === null ? null : hash('sha256', 'candidate-artifact'),
            'evidence_sha256' => hash('sha256', $encoded),
            'evidence' => $evidence,
            'archived_at' => now(),
        ];
    }

    private function modelVersionId(string $horizon): int
    {
        $run = MlTrainingRun::query()->create([
            'horizon' => $horizon,
            'status' => 'completed',
            'cutoff_date' => '2026-09-01',
            'configuration' => [],
            'metrics' => [],
            'baselines' => [],
            'selected_features' => [],
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        return (int) MlModelVersion::query()->create([
            'training_run_id' => $run->id,
            'horizon' => $horizon,
            'version' => 1,
            'status' => 'candidate',
            'model_family' => 'interpretable_logistic_baseline',
            'training_cutoff_date' => '2026-09-01',
            'feature_set' => [],
            'preprocessing' => [],
            'label_definition' => [],
            'benchmark_mapping' => [],
            'hyperparameters' => [],
            'evaluation_metrics' => [],
            'promotion_thresholds' => [],
            'artifact_path' => null,
            'artifact_sha256' => null,
            'audit_metadata' => [],
        ])->id;
    }
}
