<?php

namespace Tests\Feature\V8;

use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\V7\MlModelVersion;
use App\Services\ML\MlCandidateEvidenceService;
use App\Services\ML\MlScoringService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class MlBoundedTrainingCampaignTest extends TestCase
{
    use RefreshDatabase;

    private string $artifactDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $python = (string) getenv('STOXLA_ML_TEST_PYTHON');
        if ($python === '' || ! is_executable($python)) {
            $this->markTestSkipped('Set STOXLA_ML_TEST_PYTHON to a same-architecture ML runtime for the bounded campaign.');
        }

        $this->artifactDirectory = storage_path('app/ml-bounded-campaign-'.uniqid());
        config([
            'cache.default' => 'array',
            'ml.python' => $python,
            'ml.adapter_script' => base_path('scripts/ml_adapter.py'),
            'ml.model_directory' => $this->artifactDirectory,
            'ml.timeout_seconds' => 300,
            'ml_lifecycle.retry.max_attempts' => 1,
        ]);

        $benchmark = Stock::query()->create(['symbol' => 'NIFTY50', 'exchange' => 'NSE', 'name' => 'NIFTY 50', 'is_benchmark' => true, 'is_active' => true]);
        $subjects = [$benchmark];
        foreach (['CAMPAIGN1', 'CAMPAIGN2', 'CAMPAIGN3', 'CAMPAIGN4'] as $symbol) {
            $subjects[] = Stock::query()->create(['symbol' => $symbol, 'exchange' => 'NSE', 'name' => $symbol, 'is_active' => true, 'is_benchmark' => false]);
        }
        for ($day = 0; $day < 1500; $day++) {
            $date = Carbon::parse('2022-01-01')->addDays($day)->toDateString();
            foreach ($subjects as $index => $subject) {
                $close = 100 + ($day * (0.02 + ($index * 0.004))) + sin($day / (11 + $index)) * (8 + $index);
                StockPrice::query()->create(['stock_id' => $subject->id, 'price_date' => $date, 'close_price' => $close, 'adjusted_close_price' => $close, 'volume' => 100000 + (($day + $index) % 17) * 1000, 'data_source' => 'bounded_campaign_fixture']);
            }
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->artifactDirectory)) File::deleteDirectory($this->artifactDirectory);
        parent::tearDown();
    }

    public function test_real_adapter_campaign_completes_each_horizon_with_persisted_evidence(): void
    {
        foreach (['1m', '3m', '6m'] as $horizon) {
            $model = app(MlScoringService::class)->retrain($horizon, Carbon::parse('2026-01-01'), null);
            $evidence = app(MlCandidateEvidenceService::class)->persist($model);

            $this->assertInstanceOf(MlModelVersion::class, $model);
            $this->assertSame($horizon, $model->horizon);
            $this->assertFileExists($model->artifact_path);
            $this->assertNotEmpty($model->artifact_sha256);
            $this->assertSame($horizon, data_get($model->audit_metadata, 'feature_profile.horizon'));
            $this->assertSame(data_get($model->audit_metadata, 'feature_profile.feature_set_id'), data_get($model->audit_metadata, 'feature_profile.feature_set_version'));
            $this->assertSame('training_partition_only', data_get($model->trainingRun?->configuration, 'preprocessing.fitted_on'));
            $partitionCoverage = data_get($model->trainingRun?->configuration, 'feature_selection.partition_coverage');
            $this->assertIsArray($partitionCoverage);
            $this->assertSame(['train', 'validation', 'test'], array_keys($partitionCoverage));
            foreach ($partitionCoverage as $partition) {
                $this->assertGreaterThan(0, $partition['row_count']);
                $this->assertNotEmpty($partition['features']);
                foreach ($partition['features'] as $coverage) {
                    $this->assertArrayHasKey('available', $coverage);
                    $this->assertArrayHasKey('missing', $coverage);
                    $this->assertArrayHasKey('coverage_percent', $coverage);
                }
            }
            $this->assertArrayHasKey('candidate_metrics', $evidence);
            $this->assertArrayHasKey('deterministic_baseline', $evidence);
            $this->assertTrue($evidence['deterministic_baseline']['comparable']);
            $this->assertFalse($evidence['active_model']['comparable']);
            $this->assertSame($evidence, $model->fresh()->audit_metadata['candidate_evidence']);
            $archive = $model->fresh()->candidateEvidenceArchive;
            $this->assertNotNull($archive);
            $this->assertSame($model->artifact_sha256, $archive->artifact_sha256);
            $encodedArchive = json_encode($archive->evidence, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $this->assertSame(hash('sha256', $encodedArchive), $archive->evidence_sha256);
            $this->assertSame($evidence, app(MlCandidateEvidenceService::class)->persist($model->fresh()));
            $this->assertSame(1, $model->fresh()->candidateEvidenceArchive()->count());
        }
    }
}
