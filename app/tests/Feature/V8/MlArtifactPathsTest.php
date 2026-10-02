<?php

namespace Tests\Feature\V8;

use App\Models\Stock;
use App\Models\V7\MlCandidateEvidenceArchive;
use App\Models\V7\MlModelVersion;
use App\Models\V7\MlTrainingRun;
use App\Services\ML\MlArtifactPaths;
use App\Services\ML\MlArtifactRepairService;
use App\Services\ML\MlCandidateArchiveIntegrityService;
use App\Services\ML\MlPythonAdapter;
use App\Services\ML\MlScoringService;
use App\Services\ML\MlTrainingDatasetBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class MlArtifactPathsTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/stox-artifacts-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->root.'/releases/A');
        File::ensureDirectoryExists($this->root.'/releases/B');
        config(['ml.model_directory' => $this->root.'/shared/ml/models']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_new_write_survives_release_pruning_and_current_switch(): void
    {
        symlink($this->root.'/releases/A', $this->root.'/current');
        config(['ml.model_directory' => $this->root.'/releases/A/../shared/ml/models']);
        $service = app(MlScoringService::class);
        $method = new \ReflectionMethod($service, 'artifactPath');
        $path = $method->invoke($service, '1m', 1);
        $this->assertSame($this->root.'/shared/ml/models/model-1m-v1.joblib', $path);
        File::put($path, 'artifact');
        unlink($this->root.'/current');
        symlink($this->root.'/releases/B', $this->root.'/current');
        File::deleteDirectory($this->root.'/releases/A');
        $this->assertSame('artifact', File::get(app(MlArtifactPaths::class)->resolve($path)));
        $this->assertSame($path, $method->invoke($service, '1m', 1));
    }

    public function test_default_detects_release_layout_and_local_default_is_portable(): void
    {
        config(['ml.model_directory' => null]);
        $original = base_path();
        try {
            $this->app->setBasePath($this->root.'/releases/A');
            $this->assertSame($this->root.'/shared/ml/models', app(MlArtifactPaths::class)->directory());
        } finally {
            $this->app->setBasePath($original);
        }
        $this->assertSame(storage_path('app/ml-models'), app(MlArtifactPaths::class)->directory());
    }

    public function test_repair_dry_run_copy_and_idempotence_preserve_state(): void
    {
        $legacy = $this->legacy('model.joblib');
        File::ensureDirectoryExists(dirname($legacy));
        File::put($legacy, 'artifact');
        $model = $this->model($legacy, 'candidate');
        $before = $model->fresh()->getAttributes();
        $destination = $this->root.'/shared/ml/models/model.joblib';
        $this->artisan('portfolio:ml-artifacts-repair', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame($before, $model->fresh()->getAttributes());
        $this->assertFileDoesNotExist($destination);
        $this->artisan('portfolio:ml-artifacts-repair')->assertSuccessful();
        $this->assertFileExists($legacy);
        $this->assertSame('artifact', File::get($destination));
        $before['artifact_path'] = $destination;
        $this->assertSame($before, $model->fresh()->getAttributes());
        $this->artisan('portfolio:ml-artifacts-repair')->assertSuccessful();
        $this->assertSame($before, $model->fresh()->getAttributes());
    }

    public function test_source_mismatch_never_mutates_row_or_creates_destination(): void
    {
        $legacy = $this->legacy('bad.joblib');
        File::ensureDirectoryExists(dirname($legacy));
        File::put($legacy, 'corrupt');
        $model = $this->model($legacy, 'active');
        $before = $model->fresh()->getAttributes();
        $this->artisan('portfolio:ml-artifacts-repair')->assertFailed();
        $this->assertSame($before, $model->fresh()->getAttributes());
        $this->assertFileDoesNotExist($this->root.'/shared/ml/models/bad.joblib');
    }

    public function test_destination_conflict_never_overwrites_or_mutates_row(): void
    {
        $legacy = $this->legacy('conflict.joblib');
        File::ensureDirectoryExists(dirname($legacy));
        File::put($legacy, 'artifact');
        $destination = app(MlArtifactPaths::class)->directory(true).'/conflict.joblib';
        File::put($destination, 'different');
        $model = $this->model($legacy, 'retained');
        $this->artisan('portfolio:ml-artifacts-repair')->assertFailed();
        $this->assertSame($legacy, $model->fresh()->artifact_path);
        $this->assertSame('different', File::get($destination));
    }

    public function test_pruned_legacy_paths_support_review_scoring_archive_and_retained_rollback(): void
    {
        $directory = app(MlArtifactPaths::class)->directory(true);
        File::put($directory.'/active.joblib', 'artifact');
        File::put($directory.'/retained.joblib', 'artifact');
        $active = $this->model($this->legacy('active.joblib'), 'active');
        $retained = $this->model($this->legacy('retained.joblib'), 'retained', 2);
        File::deleteDirectory($this->root.'/releases/A');
        $this->mock(MlTrainingDatasetBuilder::class)->shouldReceive('featuresFor')->andReturn(['benchmark_symbol' => 'NIFTY50']);
        $this->mock(MlPythonAdapter::class)->shouldReceive('run')->withArgs(function ($operation, $payload) use ($directory) {
            return $operation === 'predict' && $payload['artifact_path'] === $directory.'/active.joblib';
        })->once()->andReturn(['score' => 60, 'confidence' => 0.6]);
        $scoring = app(MlScoringService::class);
        $this->assertTrue($scoring->promotionReview($retained)['artifact_ready']);
        $stock = Stock::query()->create(['symbol' => 'GH19', 'exchange' => 'NSE', 'name' => 'GH19']);
        $this->assertSame($active->id, $scoring->predict($stock, '1m')->model_version_id);
        $archive = new MlCandidateEvidenceArchive([
            'artifact_path' => $active->artifact_path, 'artifact_sha256' => $active->artifact_sha256,
            'evidence' => [], 'evidence_sha256' => hash('sha256', '[]'),
        ]);
        $this->assertTrue(app(MlCandidateArchiveIntegrityService::class)->verify($archive)['valid']);
        foreach ([$active, $retained] as $model) {
            app(MlArtifactRepairService::class)->repair($model->id, false);
            $this->assertSame($model->status, $model->fresh()->status);
        }
        $this->assertSame('active', $scoring->rollback('1m', 2, null)->status);
        $this->assertSame('retained', $active->fresh()->status);
    }

    public function test_repair_finds_legacy_source_after_release_is_pruned(): void
    {
        $legacy = $this->legacy('orphan.joblib');
        $source = app(MlArtifactPaths::class)->normalize($legacy);
        File::ensureDirectoryExists(dirname($source));
        File::put($source, 'artifact');
        $model = $this->model($legacy, 'retained');
        File::deleteDirectory($this->root.'/releases/A');
        $this->artisan('portfolio:ml-artifacts-repair')->assertSuccessful();
        $this->assertSame($this->root.'/shared/ml/models/orphan.joblib', $model->fresh()->artifact_path);
        $this->assertFileExists($source);
        $this->assertSame('artifact', File::get($model->fresh()->artifact_path));
    }

    public function test_storage_symlink_is_canonicalized_before_child_directory_exists(): void
    {
        File::ensureDirectoryExists($this->root.'/shared/storage');
        symlink($this->root.'/shared/storage', $this->root.'/releases/A/storage');
        config(['ml.model_directory' => $this->root.'/releases/A/storage/models']);
        $this->assertSame($this->root.'/shared/storage/models', app(MlArtifactPaths::class)->directory(true));
    }

    public function test_repair_rejects_destination_symlink_without_mutation(): void
    {
        $source = $this->root.'/releases/A/model.joblib';
        File::put($source, 'artifact');
        $destination = app(MlArtifactPaths::class)->directory(true).'/model.joblib';
        symlink($source, $destination);
        $model = $this->model($source, 'active');
        $before = $model->fresh()->getAttributes();
        $this->artisan('portfolio:ml-artifacts-repair')->assertFailed();
        $this->assertSame($before, $model->fresh()->getAttributes());
        $this->assertTrue(is_link($destination));
    }

    public function test_release_local_storage_is_rejected(): void
    {
        config(['ml.model_directory' => $this->root.'/releases/A/models']);
        $this->expectException(\RuntimeException::class);
        app(MlArtifactPaths::class)->directory();
    }

    public function test_missing_artifact_fails_without_mutation(): void
    {
        $model = $this->model($this->legacy('missing.joblib'), 'retained');
        $this->artisan('portfolio:ml-artifacts-repair')->assertFailed();
        $this->assertSame($model->artifact_path, $model->fresh()->artifact_path);
    }

    private function legacy(string $name): string
    {
        return $this->root.'/releases/A/../shared/ml/models/'.$name;
    }

    private function model(string $path, string $status, int $version = 1): MlModelVersion
    {
        $run = MlTrainingRun::query()->create(['horizon' => '1m', 'status' => 'completed', 'cutoff_date' => '2026-09-01']);

        return MlModelVersion::query()->create([
            'training_run_id' => $run->id, 'horizon' => '1m', 'version' => $version, 'status' => $status,
            'model_family' => 'interpretable_logistic_baseline', 'training_cutoff_date' => '2026-09-01',
            'feature_set' => [], 'preprocessing' => [], 'label_definition' => [], 'benchmark_mapping' => [],
            'hyperparameters' => [], 'evaluation_metrics' => [], 'promotion_thresholds' => [],
            'artifact_path' => $path, 'artifact_sha256' => hash('sha256', 'artifact'),
        ]);
    }
}
