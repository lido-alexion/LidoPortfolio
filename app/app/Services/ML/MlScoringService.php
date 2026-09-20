<?php

namespace App\Services\ML;

use App\Models\Stock;
use App\Models\User;
use App\Models\V7\MlModelVersion;
use App\Models\V7\MlPrediction;
use App\Models\V7\MlTrainingRun;
use App\Services\Fundamentals\FundamentalDataService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MlScoringService
{
    public const HORIZONS = ['1m', '3m', '6m'];

    public function __construct(
        protected FundamentalDataService $fundamentals,
        protected MlTrainingDatasetBuilder $datasets,
        protected MlPythonAdapter $adapter,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function dashboard(): array
    {
        return [
            'horizons' => collect(self::HORIZONS)->map(fn (string $horizon) => [
                'horizon' => $horizon,
                'active_model' => MlModelVersion::query()->where('horizon', $horizon)->where('status', 'active')->latest('version')->first()?->toArray(),
                'latest_candidate' => MlModelVersion::query()->where('horizon', $horizon)->where('status', 'candidate')->latest('version')->first()?->toArray(),
                'candidate_count' => MlModelVersion::query()->where('horizon', $horizon)->where('status', 'candidate')->count(),
                'latest_training_run' => MlTrainingRun::query()->where('horizon', $horizon)->latest('id')->first()?->toArray(),
                'latest_prediction_at' => MlPrediction::query()->where('horizon', $horizon)->max('as_of'),
            ])->values()->all(),
            'promotion_threshold_defaults' => $this->promotionThresholds(),
        ];
    }

    public function retrain(string $horizon, ?Carbon $cutoff, ?User $user, array $overrides = []): MlModelVersion
    {
        $this->assertHorizon($horizon);
        $cutoff ??= now();

        $config = array_replace_recursive($this->trainingConfig($horizon), $overrides);
        $run = MlTrainingRun::query()->create([
                'horizon' => $horizon,
                'status' => 'running',
                'cutoff_date' => $cutoff->toDateString(),
                'configuration' => $config,
                'requested_by' => $user?->id,
                'started_at' => now(),
            ]);

        try {
            $dataset = $this->datasets->build($horizon, $cutoff);
            $version = ((int) MlModelVersion::query()->where('horizon', $horizon)->max('version')) + 1;
            $artifactPath = $this->artifactPath($horizon, $version);
            $result = $this->adapter->run('train', [
                'horizon' => $horizon,
                'cutoff_date' => $cutoff->toDateString(),
                'rows' => $dataset['rows'],
                'partitions' => $dataset['partitions'],
                'feature_definitions' => $dataset['feature_definitions'],
                'numeric_features' => MlTrainingDatasetBuilder::NUMERIC_FEATURES,
                'categorical_features' => MlTrainingDatasetBuilder::CATEGORICAL_FEATURES,
                'seed' => $config['hyperparameters']['seed'] ?? 7047,
                'artifact_path' => $artifactPath,
            ]);
            $metrics = $result['metrics'] ?? [];
            $baselines = $result['baselines'] ?? [];
            $artifactDigest = is_file($artifactPath) ? (string) hash_file('sha256', $artifactPath) : null;
            if (! isset($result['artifact_sha256']) || $artifactDigest === null || ! hash_equals((string) $result['artifact_sha256'], $artifactDigest)) {
                throw new \RuntimeException('ML adapter artifact integrity verification failed.');
            }
            $eligible = $this->metricsMeetThresholds($metrics, $config['promotion_thresholds']);

            $run->forceFill([
                'status' => 'completed',
                'metrics' => $metrics,
                'baselines' => $baselines,
                'selected_features' => $config['feature_set'],
                'configuration' => array_replace_recursive($config, ['dataset' => $dataset['partitions'], 'feature_definitions' => $dataset['feature_definitions']]),
                'completed_at' => now(),
            ])->save();

            return DB::transaction(function () use ($horizon, $cutoff, $config, $run, $version, $artifactPath, $result, $metrics, $baselines, $eligible): MlModelVersion {
                return MlModelVersion::query()->create([
                'training_run_id' => $run->id,
                'horizon' => $horizon,
                'version' => $version,
                'status' => $eligible ? 'candidate' : 'rejected',
                'artifact_path' => $artifactPath,
                'artifact_sha256' => $result['artifact_sha256'],
                'artifact_format' => (string) config('ml.artifact_format', 'joblib'),
                'artifact_version' => (string) config('ml.artifact_version', 'v7-logistic-1'),
                'model_family' => 'interpretable_logistic_baseline',
                'training_cutoff_date' => $cutoff->toDateString(),
                'feature_set' => $config['feature_set'],
                'preprocessing' => $result['metadata']['preprocessing'] ?? $config['preprocessing'],
                'label_definition' => $config['label_definition'],
                'benchmark_mapping' => $config['benchmark_mapping'],
                'hyperparameters' => $config['hyperparameters'],
                'evaluation_metrics' => $metrics,
                'promotion_thresholds' => $config['promotion_thresholds'],
                'audit_metadata' => [
                    'chronological_split' => $run->configuration['dataset'] ?? [],
                    'point_in_time_safe' => true,
                    'class_distribution' => $metrics['class_distribution'] ?? [],
                    'automatic_promotion' => false,
                    'adapter_metadata' => $result['metadata'] ?? [],
                ],
                ]);
            });
        } catch (\Throwable $exception) {
            $run->forceFill([
                'status' => 'failed',
                'failure' => ['message' => substr($exception->getMessage(), 0, 1000), 'type' => get_class($exception)],
                'completed_at' => now(),
            ])->save();
            throw $exception;
        }
    }

    public function promote(MlModelVersion $model, ?User $user): MlModelVersion
    {
        if ($model->status !== 'candidate') {
            throw ValidationException::withMessages(['model' => ['Only candidate models can be promoted.']]);
        }

        $this->assertArtifact($model);

        if (! $this->isPromotionEligible($model)) {
            throw ValidationException::withMessages(['model' => ['This model does not meet its promotion thresholds.']]);
        }

        return DB::transaction(function () use ($model, $user): MlModelVersion {
            MlModelVersion::query()
                ->where('horizon', $model->horizon)
                ->where('status', 'active')
                ->update(['status' => 'retained']);

            $model->forceFill([
                'status' => 'active',
                'promoted_at' => now(),
                'promoted_by' => $user?->id,
            ])->save();

            return $model->fresh();
        });
    }

    public function rollback(string $horizon, int $version, ?User $user): MlModelVersion
    {
        $model = MlModelVersion::query()
            ->where('horizon', $horizon)
            ->where('version', $version)
            ->where('status', 'retained')
            ->firstOrFail();

        $this->assertArtifact($model);

        return DB::transaction(function () use ($model, $user): MlModelVersion {
            MlModelVersion::query()
                ->where('horizon', $model->horizon)
                ->where('status', 'active')
                ->update(['status' => 'retained']);

            $model->forceFill([
                'status' => 'active',
                'promoted_at' => now(),
                'promoted_by' => $user?->id,
            ])->save();

            return $model->fresh();
        });
    }

    public function predict(Stock $stock, string $horizon, ?Carbon $asOf = null, bool $shadow = false): ?MlPrediction
    {
        $this->assertHorizon($horizon);
        $asOf ??= now();
        if ($asOf->isFuture()) {
            throw ValidationException::withMessages(['as_of' => ['Prediction as-of cannot be in the future.']]);
        }
        $model = MlModelVersion::query()->where('horizon', $horizon)->where('status', 'active')->latest('version')->first();
        if (! $model) {
            return null;
        }

        $this->assertArtifact($model);
        $features = $this->datasets->featuresFor($stock, $asOf);
        $result = $this->adapter->run('predict', [
            'artifact_path' => $model->artifact_path,
            'artifact_sha256' => $model->artifact_sha256,
            'features' => $features,
        ]);
        $contributions = $result['contributions'] ?? [];
        $explanations = [
            'top_positive' => array_values(array_filter($contributions, fn (array $item): bool => ($item['contribution'] ?? 0) > 0)),
            'top_negative' => array_values(array_filter($contributions, fn (array $item): bool => ($item['contribution'] ?? 0) < 0)),
        ];

        return MlPrediction::query()->updateOrCreate([
            'stock_id' => $stock->id,
            'model_version_id' => $model->id,
            'as_of' => $asOf,
            'shadow' => $shadow,
        ], [
            'horizon' => $horizon,
            'score' => $result['score'],
            'confidence' => $result['confidence'],
            'benchmark_symbol' => $features['benchmark_symbol'],
            'explanations' => $explanations,
            'feature_snapshot' => $features,
        ]);
    }

    public function latestPrediction(Stock $stock, string $horizon, ?Carbon $asOf = null): ?MlPrediction
    {
        $this->assertHorizon($horizon);
        $asOf ??= now();

        return MlPrediction::query()
            ->where('stock_id', $stock->id)
            ->where('horizon', $horizon)
            ->where('shadow', false)
            ->where('as_of', '<=', $asOf)
            ->orderByDesc('as_of')
            ->first();
    }

    /** @return array<string,mixed> */
    private function trainingConfig(string $horizon): array
    {
        return [
            'feature_set' => [...MlTrainingDatasetBuilder::NUMERIC_FEATURES, ...MlTrainingDatasetBuilder::CATEGORICAL_FEATURES],
            'preprocessing' => ['missing_values' => 'median_with_missingness_flags', 'fitted_on' => 'training_partition_only'],
            'label_definition' => [
                'version' => 'v7-risk-aware-benchmark-relative-1',
                'horizon' => $horizon,
                'target' => 'benchmark_relative_success_with_drawdown_guard',
            ],
            'benchmark_mapping' => ['version' => 'v7-default-sector-aware-1', 'default' => 'NIFTY50'],
            'hyperparameters' => ['class_weight' => 'balanced', 'seed' => 7047],
            'chronological_split' => ['train' => 0.7, 'validation' => 0.15, 'test' => 0.15],
            'promotion_thresholds' => $this->promotionThresholds(),
        ];
    }

    /** @return array<string,float> */
    private function promotionThresholds(): array
    {
        return [
            'min_roc_auc' => 0.52,
            'min_pr_auc' => 0.5,
            'min_benchmark_relative_return' => 0.0,
            'min_deterministic_baseline_delta' => 0.0,
        ];
    }

    private function assertHorizon(string $horizon): void
    {
        if (! in_array($horizon, self::HORIZONS, true)) {
            throw ValidationException::withMessages(['horizon' => ['Supported horizons are 1m, 3m and 6m.']]);
        }
    }

    private function isPromotionEligible(MlModelVersion $model): bool
    {
        $metrics = $model->evaluation_metrics ?? [];
        $thresholds = $model->promotion_thresholds ?? [];

        return $this->metricsMeetThresholds($metrics, $thresholds);
    }

    private function metricsMeetThresholds(array $metrics, array $thresholds): bool
    {
        foreach (['roc_auc', 'pr_auc', 'benchmark_relative_return', 'deterministic_baseline_delta'] as $key) {
            if (! isset($metrics[$key], $thresholds['min_'.$key]) || ! is_numeric($metrics[$key])) {
                return false;
            }
        }
        return (float) $metrics['roc_auc'] >= (float) $thresholds['min_roc_auc']
            && (float) $metrics['pr_auc'] >= (float) $thresholds['min_pr_auc']
            && (float) $metrics['benchmark_relative_return'] >= (float) $thresholds['min_benchmark_relative_return']
            && (float) $metrics['deterministic_baseline_delta'] >= (float) $thresholds['min_deterministic_baseline_delta'];
    }

    private function artifactPath(string $horizon, int $version): string
    {
        $directory = (string) config('ml.model_directory', storage_path('app/ml-models'));
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new \RuntimeException('ML model artifact directory is unavailable.');
        }
        return $directory.'/model-'.$horizon.'-v'.$version.'.joblib';
    }

    private function assertArtifact(MlModelVersion $model): void
    {
        if ($model->artifact_path === null || $model->artifact_sha256 === null || ! is_file($model->artifact_path)) {
            throw ValidationException::withMessages(['model' => ['The model artifact is missing.']]);
        }
        if (! hash_equals($model->artifact_sha256, (string) hash_file('sha256', $model->artifact_path))) {
            throw ValidationException::withMessages(['model' => ['The model artifact integrity check failed.']]);
        }
    }
}
