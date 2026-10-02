<?php

namespace App\Services\ML;

use App\Exceptions\MlTrainingRetryScheduledException;
use App\Exceptions\MlTrainingRunCancelledException;
use App\Models\Stock;
use App\Models\User;
use App\Models\V7\MlDriftCheck;
use App\Models\V7\MlModelVersion;
use App\Models\V7\MlPrediction;
use App\Models\V7\MlTrainingRun;
use App\Models\V7\MlTrainingHorizonLock;
use App\Jobs\MlRetrainJob;
use App\Services\Fundamentals\FundamentalDataService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;

class MlScoringService
{
    public const HORIZONS = ['1m', '3m', '6m'];

    public function __construct(
        protected FundamentalDataService $fundamentals,
        protected MlTrainingDatasetBuilder $datasets,
        protected MlPythonAdapter $adapter,
        protected MlDeterministicBaselineAdapter $deterministicBaseline,
        protected ?MlTrainingRunProgressService $trainingProgress = null,
        protected ?MlTrainingRunCancellationService $trainingCancellation = null,
        protected ?MlLifecycleNotificationService $lifecycleNotifications = null,
        protected ?MlTrainingRunRetryService $trainingRetry = null,
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
                'latest_drift_check' => $this->latestDriftCheckForHorizon($horizon)?->toArray(),
                'retained_models' => MlModelVersion::query()
                    ->where('horizon', $horizon)
                    ->where('status', 'retained')
                    ->latest('version')
                    ->get(['id', 'version', 'status', 'promoted_at', 'artifact_path', 'artifact_sha256'])
                    ->map(fn (MlModelVersion $model): array => [
                        'id' => $model->id,
                        'version' => $model->version,
                        'status' => $model->status,
                        'promoted_at' => $model->promoted_at?->toIso8601String(),
                        'artifact_ready' => $model->artifact_path !== null
                            && $model->artifact_sha256 !== null
                            && is_file((string) app(MlArtifactPaths::class)->resolve($model->artifact_path))
                            && hash_equals((string) $model->artifact_sha256, (string) hash_file('sha256', (string) app(MlArtifactPaths::class)->resolve($model->artifact_path))),
                    ])->values()->all(),
            ])->values()->all(),
            'promotion_threshold_defaults' => $this->promotionThresholds(),
            'lifecycle' => app(MlLifecycleAutomationService::class)->adminStatus(),
        ];
    }

    public function queueAdminRetrain(string $horizon, ?User $user): MlTrainingRun
    {
        return $this->queueRetrainRun($horizon, $user, 'manual');
    }

    public function queueRetrainRun(string $horizon, ?User $user, string $trigger, ?int $driftCheckId = null, ?array $acceptance = null): MlTrainingRun
    {
        $this->assertHorizon($horizon);
        $this->assertTrigger($trigger);
        $this->assertTriggerContext($trigger, $driftCheckId);

        $configuration = ['trigger' => $trigger];
        if ($acceptance !== null) $configuration['acceptance'] = $acceptance;
        if ($driftCheckId !== null) {
            $configuration['drift_check_id'] = $driftCheckId;
        }

        // Locking a persistent row serializes manual, scheduled and drift
        // requests across workers; the frontend check is only a convenience.
        $run = DB::transaction(function () use ($horizon, $configuration, $user): MlTrainingRun {
            MlTrainingHorizonLock::query()
                ->whereKey($horizon)
                ->lockForUpdate()
                ->firstOrFail();

            if (MlTrainingRun::query()
                ->where('horizon', $horizon)
                ->whereIn('status', ['queued', 'running', 'cancelling'])
                ->exists()) {
                throw ValidationException::withMessages([
                    'horizon' => ['A training run is already in progress for this horizon.'],
                ]);
            }

            return MlTrainingRun::query()->create([
                'horizon' => $horizon,
                'status' => 'queued',
                'cutoff_date' => now()->toDateString(),
                'configuration' => $configuration,
                'requested_by' => $user?->id,
            ]);
        });
        $this->progressService()->record($run, 'queued', 0);

        MlRetrainJob::dispatch($horizon, $user?->id, $trigger, $driftCheckId, $run->id)->afterCommit();

        return $run;
    }

    public function retrain(string $horizon, ?Carbon $cutoff, ?User $user, array $overrides = [], ?int $trainingRunId = null): MlModelVersion
    {
        $this->assertHorizon($horizon);
        $cutoff ??= now();

        $lockSeconds = (int) config('ml.retrain_lock_seconds', 14400);
        if ($trainingRunId && isset(MlTrainingRun::query()->find($trainingRunId)?->configuration['acceptance'])) {
            $lockSeconds = max($lockSeconds, MlAcceptanceRuntime::TIMEOUT + 60);
        }
        return Cache::lock('stox-ml-retrain-'.$horizon, $lockSeconds)->block(15, function () use ($horizon, $cutoff, $user, $overrides, $trainingRunId): MlModelVersion {
            return $this->retrainLocked($horizon, $cutoff, $user, $overrides, $trainingRunId);
        });
    }

    private function retrainLocked(string $horizon, Carbon $cutoff, ?User $user, array $overrides, ?int $trainingRunId = null): MlModelVersion
    {
        $tempArtifactPath = null;
        $finalArtifactPath = null;
        $datasetDirectory = null;

        $config = array_replace_recursive($this->trainingConfig($horizon), $overrides);
        if (isset($overrides['trigger'])) {
            $config['trigger'] = $overrides['trigger'];
        }
        if (isset($overrides['drift_check_id'])) {
            $config['drift_check_id'] = (int) $overrides['drift_check_id'];
        }

        if ($trainingRunId !== null) {
            $run = MlTrainingRun::query()->findOrFail($trainingRunId);
            if (isset($run->configuration['acceptance']) && ! in_array($run->status, ['queued', 'cancelling', 'cancelled'], true)) {
                throw new \App\Exceptions\MlTrainingRunCancelledException($run->id);
            }
            $config = array_replace_recursive($config, is_array($run->configuration) ? $run->configuration : []);
            $this->trainingCancellation->assertContinueOrAbort($run);
            $run->forceFill([
                'status' => 'running',
                'cutoff_date' => $cutoff->toDateString(),
                'configuration' => $config,
                'requested_by' => $user?->id ?? $run->requested_by,
                'started_at' => now(),
            ])->save();
        } else {
            $run = MlTrainingRun::query()->create([
                'horizon' => $horizon,
                'status' => 'running',
                'cutoff_date' => $cutoff->toDateString(),
                'configuration' => $config,
                'requested_by' => $user?->id,
                'started_at' => now(),
            ]);
        }
        $this->progressService()->record($run, 'queued', 5);

        try {
            $this->cancellationService()->assertContinueOrAbort($run);
            $this->progressService()->record($run, 'building_dataset', 15);
            $datasetDirectory = storage_path('framework/cache/ml-datasets/run-'.$run->id);
            $dataset = $this->datasets->buildStreamed($horizon, $cutoff, $datasetDirectory);
            app(MlAcceptanceCampaignService::class)->assertTraining($run, $dataset);
            $chronoGrid = app(MlChronologicalValidationGridService::class)->summarize($dataset['paths'], $dataset['partitions']);
            $config['chronological_validation_grid'] = $chronoGrid;
            $this->cancellationService()->assertContinueOrAbort($run);
            $this->progressService()->record($run, 'dataset_ready', 35, ['row_counts' => $dataset['partitions']['row_counts'] ?? []]);
            $baselineDefinition = $this->deterministicBaseline->definition();
            $baselinePath = $datasetDirectory.'/deterministic-baseline.jsonl';
            $baselineRows = $this->deterministicBaseline->evaluateFile($dataset['paths']['test'], $baselinePath);
            if ($baselineRows !== (int) ($dataset['partitions']['row_counts']['test'] ?? 0)) {
                throw new \RuntimeException('Deterministic baseline is not aligned to the streamed test partition.');
            }
            $tempArtifactPath = $this->artifactPath($horizon, 0, 'run-'.$run->id.'.tmp');
            $tempChallengerArtifactPath = $tempArtifactPath.'.challenger.joblib';
            $this->cancellationService()->assertContinueOrAbort($run);
            $this->progressService()->record($run, 'training', 55);
            $result = $this->adapter->run('train', [
                'horizon' => $horizon,
                'cutoff_date' => $cutoff->toDateString(),
                'dataset_paths' => $dataset['paths'],
                'partitions' => $dataset['partitions'],
                'feature_definitions' => $dataset['feature_definitions'],
                'baseline_definition' => $baselineDefinition,
                'numeric_features' => $config['feature_profile']['numeric'] ?? MlTrainingDatasetBuilder::NUMERIC_FEATURES,
                'categorical_features' => $config['feature_profile']['categorical'] ?? MlTrainingDatasetBuilder::CATEGORICAL_FEATURES,
                'feature_profile' => $config['feature_profile'] ?? null,
                'seed' => $config['hyperparameters']['seed'] ?? 7047,
                'artifact_path' => $tempArtifactPath,
                'challenger_artifact_path' => $tempChallengerArtifactPath,
                'deterministic_baseline_path' => $baselinePath,
            ]);
            $metrics = $result['metrics'] ?? [];
            $baselines = $result['baselines'] ?? [];
            $challengerEvidence = is_array($result['metadata']['challenger'] ?? null)
                ? $result['metadata']['challenger']
                : null;
            if ($challengerEvidence !== null) {
                $config['challenger_evidence'] = $challengerEvidence;
            }
            $returnRegressorEvidence = is_array($result['metadata']['return_regressor'] ?? null)
                ? $result['metadata']['return_regressor']
                : null;
            if ($returnRegressorEvidence !== null) {
                $config['return_regressor_evidence'] = $returnRegressorEvidence;
            }
            $configuredFeatureSet = $config['feature_set'];
            $effectiveFeatureSet = $result['metadata']['effective_feature_set'] ?? $configuredFeatureSet;
            $excludedFeatures = $result['metadata']['excluded_features'] ?? [];
            $featureTrainingCoverage = $result['metadata']['feature_training_coverage'] ?? [];
            $featurePartitionCoverage = $result['metadata']['feature_partition_coverage'] ?? [];
            $artifactDigest = is_file($tempArtifactPath) ? (string) hash_file('sha256', $tempArtifactPath) : null;
            if (! isset($result['artifact_sha256']) || $artifactDigest === null || ! hash_equals((string) $result['artifact_sha256'], $artifactDigest)) {
                throw new \RuntimeException('ML adapter artifact integrity verification failed.');
            }
            $this->cancellationService()->assertContinueOrAbort($run);
            $eligible = $this->metricsMeetThresholds($metrics, $config['promotion_thresholds']);
            $this->progressService()->record($run, 'evaluating', 85, ['eligible' => $eligible]);

            $run->forceFill([
                'status' => $eligible ? 'completed_eligible' : 'completed_rejected',
                'metrics' => $metrics,
                'baselines' => $baselines,
                'selected_features' => $effectiveFeatureSet,
                'configuration' => array_replace_recursive($config, ['dataset' => $dataset['partitions'], 'dataset_diagnostics' => $dataset['diagnostics'], 'feature_definitions' => $dataset['feature_definitions'], 'deterministic_baseline' => $baselineDefinition, 'chronological_validation_grid' => $chronoGrid, 'feature_selection' => ['configured' => $configuredFeatureSet, 'effective' => $effectiveFeatureSet, 'excluded' => $excludedFeatures, 'training_coverage' => $featureTrainingCoverage, 'partition_coverage' => $featurePartitionCoverage]]),
                'completed_at' => now(),
            ])->save();
            $this->progressService()->record($run, 'completed', 100);

            $model = DB::transaction(function () use ($horizon, $cutoff, $config, $run, $tempArtifactPath, &$finalArtifactPath, $baselineDefinition, $result, $metrics, $baselines, $eligible, $configuredFeatureSet, $effectiveFeatureSet, $excludedFeatures, $featureTrainingCoverage, $featurePartitionCoverage, $challengerEvidence): MlModelVersion {
                $version = ((int) MlModelVersion::query()->where('horizon', $horizon)->lockForUpdate()->max('version')) + 1;
                $artifactPath = $this->artifactPath($horizon, $version);
                $finalArtifactPath = $artifactPath;
                if (! rename($tempArtifactPath, $artifactPath)) {
                    throw new \RuntimeException('Unable to atomically activate ML model artifact.');
                }

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
                'feature_set' => $effectiveFeatureSet,
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
                    'deterministic_baseline' => $baselineDefinition,
                    'configured_feature_set' => $configuredFeatureSet,
                    'effective_feature_set' => $effectiveFeatureSet,
                    'excluded_features' => $excludedFeatures,
                    'feature_training_coverage' => $featureTrainingCoverage,
                    'feature_partition_coverage' => $featurePartitionCoverage,
                    'feature_profile' => $config['feature_profile'] ?? null,
                    'adapter_metadata' => $result['metadata'] ?? [],
                    'challenger_evidence' => $challengerEvidence,
                ],
                ]);
            });
            if ($eligible && $model->status === 'candidate') {
                $this->notificationService()->notifyEligibleCandidate($model);
            }

            $challengerModel = $this->registerChallengerCandidate(
                $run,
                $horizon,
                $cutoff,
                $config,
                $result,
                $challengerEvidence,
                $tempChallengerArtifactPath,
                $metrics,
                $baselineDefinition,
                $configuredFeatureSet,
                $effectiveFeatureSet,
                $excludedFeatures,
                $featureTrainingCoverage,
            );
            if ($challengerModel !== null && $challengerModel->status === 'candidate') {
                $this->notificationService()->notifyEligibleCandidate($challengerModel);
            }

            return $model;
        } catch (MlTrainingRunCancelledException $cancelled) {
            throw $cancelled;
        } catch (\Throwable $exception) {
            try {
                $this->retryService()->scheduleIfTransient($run, $horizon, $user, $exception);
            } catch (MlTrainingRetryScheduledException $retryScheduled) {
                throw $retryScheduled;
            }
            if (isset($tempArtifactPath) && is_file($tempArtifactPath)) {
                @unlink($tempArtifactPath);
            }
            if ($finalArtifactPath !== null && is_file($finalArtifactPath) && ! MlModelVersion::query()->where('artifact_path', $finalArtifactPath)->exists()) {
                @unlink($finalArtifactPath);
            }
            $this->progressService()->record($run, 'failed', 100, [
                'error_type' => get_class($exception),
            ]);
            $run->forceFill([
                'status' => 'failed',
                'failure' => ['message' => substr($exception->getMessage(), 0, 1000), 'type' => get_class($exception)],
                'completed_at' => now(),
            ])->save();
            $this->notificationService()->notifyTrainingFailed($run->fresh());
            throw $exception;
        } finally {
            if ($datasetDirectory !== null) {
                File::deleteDirectory($datasetDirectory);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function promotionReview(MlModelVersion $model): array
    {
        $metrics = $model->evaluation_metrics ?? [];
        $thresholds = $model->promotion_thresholds ?? $this->promotionThresholds();
        $metricKeys = ['roc_auc', 'pr_auc', 'benchmark_relative_return', 'deterministic_baseline_delta'];
        $checks = [];
        foreach ($metricKeys as $key) {
            $thresholdKey = 'min_'.$key;
            $value = isset($metrics[$key]) && is_numeric($metrics[$key]) ? (float) $metrics[$key] : null;
            $min = isset($thresholds[$thresholdKey]) && is_numeric($thresholds[$thresholdKey])
                ? (float) $thresholds[$thresholdKey]
                : null;
            $checks[$key] = [
                'value' => $value,
                'min' => $min,
                'met' => $value !== null && $min !== null && $value >= $min,
            ];
        }

        $artifactReady = $model->artifact_path !== null
            && $model->artifact_sha256 !== null
            && is_file((string) app(MlArtifactPaths::class)->resolve($model->artifact_path))
            && hash_equals((string) $model->artifact_sha256, (string) hash_file('sha256', (string) app(MlArtifactPaths::class)->resolve($model->artifact_path)));

        $active = MlModelVersion::query()
            ->where('horizon', $model->horizon)
            ->where('status', 'active')
            ->orderByDesc('version')
            ->first();

        $deltaVsActive = null;
        if ($active !== null) {
            $activeMetrics = $active->evaluation_metrics ?? [];
            $deltaVsActive = [];
            foreach ($metricKeys as $key) {
                $candidate = isset($metrics[$key]) && is_numeric($metrics[$key]) ? (float) $metrics[$key] : null;
                $current = isset($activeMetrics[$key]) && is_numeric($activeMetrics[$key]) ? (float) $activeMetrics[$key] : null;
                $deltaVsActive[$key] = ($candidate !== null && $current !== null) ? $candidate - $current : null;
            }
        }

        $challengerSibling = MlModelVersion::query()
            ->where('training_run_id', $model->training_run_id)
            ->where('model_family', 'hist_gradient_boosting_challenger')
            ->first();

        return [
            'model' => [
                'id' => $model->id,
                'horizon' => $model->horizon,
                'version' => $model->version,
                'status' => $model->status,
                'model_family' => $model->model_family,
            ],
            'thresholds' => $thresholds,
            'checks' => $checks,
            'eligible' => $this->isPromotionEligible($model),
            'artifact_ready' => $artifactReady,
            'active_model' => $active?->only(['id', 'version', 'evaluation_metrics', 'promoted_at']),
            'delta_vs_active' => $deltaVsActive,
            'challenger_sibling' => $challengerSibling ? [
                'id' => $challengerSibling->id,
                'version' => $challengerSibling->version,
                'status' => $challengerSibling->status,
                'eligible' => $this->isPromotionEligible($challengerSibling),
                'evaluation_metrics' => $challengerSibling->evaluation_metrics,
            ] : null,
            'calibration' => is_array($metrics['calibration'] ?? null)
                ? $metrics['calibration']
                : (is_array($metrics['test']['calibration'] ?? null) ? $metrics['test']['calibration'] : null),
        ];
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

        $promoted = DB::transaction(function () use ($model, $user): MlModelVersion {
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

        $this->notificationService()->notifyPromoted($promoted, $user);

        return $promoted;
    }

    public function rollback(string $horizon, int $version, ?User $user): MlModelVersion
    {
        $model = MlModelVersion::query()
            ->where('horizon', $horizon)
            ->where('version', $version)
            ->where('status', 'retained')
            ->firstOrFail();

        $this->assertArtifact($model);

        $activated = DB::transaction(function () use ($model, $user): MlModelVersion {
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

        $this->notificationService()->notifyRollback($activated, $user);

        return $activated;
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
            'artifact_path' => app(MlArtifactPaths::class)->resolve($model->artifact_path),
            'artifact_sha256' => $model->artifact_sha256,
            'features' => $features,
        ]);
        $contributions = $result['contributions'] ?? [];
        $explanations = [
            'top_positive' => array_values(array_filter($contributions, fn (array $item): bool => ($item['contribution'] ?? 0) > 0)),
            'top_negative' => array_values(array_filter($contributions, fn (array $item): bool => ($item['contribution'] ?? 0) < 0)),
        ];
        if (isset($result['expected_benchmark_relative_return_pct']) && $result['expected_benchmark_relative_return_pct'] !== null) {
            $explanations['expected_benchmark_relative_return_pct'] = (float) $result['expected_benchmark_relative_return_pct'];
        }

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

    /**
     * Investor-facing bundle of latest non-shadow scores per horizon (FEAT-057).
     *
     * @return array<string,mixed>
     */
    public function investorHorizonInsights(Stock $stock): array
    {
        return [
            'disclaimer' => 'Advisory benchmark-relative success scores only; not a buy or sell instruction.',
            'stock_id' => $stock->id,
            'symbol' => $stock->symbol,
            'horizons' => collect(self::HORIZONS)->map(function (string $horizon) use ($stock): array {
                $active = MlModelVersion::query()
                    ->where('horizon', $horizon)
                    ->where('status', 'active')
                    ->latest('version')
                    ->first();

                $prediction = $this->latestPrediction($stock, $horizon);

                return [
                    'horizon' => $horizon,
                    'active_model' => $active ? [
                        'id' => $active->id,
                        'version' => $active->version,
                        'model_family' => $active->model_family,
                    ] : null,
                    'prediction' => $prediction ? $this->formatInvestorPrediction($prediction) : null,
                ];
            })->values()->all(),
        ];
    }

    /**
     * Re-score missing horizons using active production models, then return the insights bundle.
     *
     * @param  list<string>  $horizons
     * @return array<string,mixed>
     */
    public function refreshInvestorHorizonInsights(Stock $stock, array $horizons = []): array
    {
        $targets = $horizons === []
            ? self::HORIZONS
            : array_values(array_intersect(self::HORIZONS, $horizons));

        $errors = [];
        foreach ($targets as $horizon) {
            try {
                $this->predict($stock, $horizon);
            } catch (ValidationException $exception) {
                $messages = $exception->errors();
                $errors[$horizon] = is_array($messages) ? (string) (collect($messages)->flatten()->first() ?? 'Prediction unavailable.') : 'Prediction unavailable.';
            }
        }

        $payload = $this->investorHorizonInsights($stock);
        if ($errors !== []) {
            $payload['refresh_errors'] = $errors;
        }

        return $payload;
    }

    /** @return array<string,mixed> */
    private function formatInvestorPrediction(MlPrediction $prediction): array
    {
        $prediction->loadMissing('modelVersion:id,version,horizon,status,model_family');

        $explanations = $prediction->explanations ?? [];

        return [
            'as_of' => $prediction->as_of?->toDateString(),
            'score' => (float) $prediction->score,
            'confidence' => (float) $prediction->confidence,
            'expected_benchmark_relative_return_pct' => isset($explanations['expected_benchmark_relative_return_pct'])
                ? (float) $explanations['expected_benchmark_relative_return_pct']
                : null,
            'benchmark_symbol' => $prediction->benchmark_symbol,
            'explanations' => $explanations,
            'model_version' => $prediction->modelVersion ? [
                'id' => $prediction->modelVersion->id,
                'version' => $prediction->modelVersion->version,
                'horizon' => $prediction->modelVersion->horizon,
                'status' => $prediction->modelVersion->status,
                'model_family' => $prediction->modelVersion->model_family,
            ] : null,
        ];
    }

    /** @return array<string,mixed> */
    private function trainingConfig(string $horizon): array
    {
        $featureProfile = app(MlFeatureRegistryService::class)->featureSetForHorizon($horizon);

        return [
            'feature_set' => $featureProfile['feature_keys'],
            'feature_profile' => $featureProfile,
            'preprocessing' => ['missing_values' => 'median_with_missingness_flags', 'fitted_on' => 'training_partition_only'],
            'label_definition' => [
                'version' => 'v7-risk-aware-benchmark-relative-1',
                'horizon' => $horizon,
                'target' => 'benchmark_relative_success_with_drawdown_guard',
            ],
            'benchmark_mapping' => $this->datasets->benchmarkMappingDefinition(),
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

    private function assertTrigger(string $trigger): void
    {
        if (! in_array($trigger, ['scheduled', 'manual', 'drift'], true)) {
            throw ValidationException::withMessages([
                'trigger' => ['Unsupported ML training trigger.'],
            ]);
        }
    }

    private function assertTriggerContext(string $trigger, ?int $driftCheckId): void
    {
        if ($trigger === 'drift' && $driftCheckId === null) {
            throw ValidationException::withMessages([
                'drift_check_id' => ['A drift-triggered run must reference its drift check.'],
            ]);
        }
        if ($trigger !== 'drift' && $driftCheckId !== null) {
            throw ValidationException::withMessages([
                'drift_check_id' => ['Only drift-triggered runs may reference a drift check.'],
            ]);
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

    /**
     * @param  array<string,mixed>|null  $challengerEvidence
     * @param  array<string,mixed>  $logisticMetrics
     */
    private function registerChallengerCandidate(
        MlTrainingRun $run,
        string $horizon,
        Carbon $cutoff,
        array $config,
        array $result,
        ?array $challengerEvidence,
        string $tempChallengerArtifactPath,
        array $logisticMetrics,
        array $baselineDefinition,
        array $configuredFeatureSet,
        array $effectiveFeatureSet,
        array $excludedFeatures,
        array $featureTrainingCoverage,
    ): ?MlModelVersion {
        if (! config('ml.challenger_promotion.enabled', true)) {
            if (is_file($tempChallengerArtifactPath)) {
                @unlink($tempChallengerArtifactPath);
            }

            return null;
        }

        if (! is_array($challengerEvidence) || ($challengerEvidence['status'] ?? '') !== 'trained') {
            if (is_file($tempChallengerArtifactPath)) {
                @unlink($tempChallengerArtifactPath);
            }

            return null;
        }

        $delta = (float) ($challengerEvidence['roc_auc_delta_vs_logistic'] ?? -1.0);
        $minDelta = (float) config('ml.challenger_promotion.min_roc_auc_delta_vs_logistic', 0.01);
        if ($delta < $minDelta || ! is_file($tempChallengerArtifactPath)) {
            if (is_file($tempChallengerArtifactPath)) {
                @unlink($tempChallengerArtifactPath);
            }

            return null;
        }

        $digest = (string) hash_file('sha256', $tempChallengerArtifactPath);
        if (empty($result['challenger_artifact_sha256']) || ! hash_equals((string) $result['challenger_artifact_sha256'], $digest)) {
            @unlink($tempChallengerArtifactPath);

            return null;
        }

        $testMetrics = is_array($challengerEvidence['test_metrics'] ?? null) ? $challengerEvidence['test_metrics'] : [];
        $challengerMetrics = [
            'roc_auc' => $testMetrics['roc_auc'] ?? null,
            'pr_auc' => $testMetrics['pr_auc'] ?? null,
            'benchmark_relative_return' => $testMetrics['benchmark_relative_return'] ?? null,
            'deterministic_baseline_delta' => $logisticMetrics['deterministic_baseline_delta'] ?? null,
            'class_distribution' => $testMetrics['class_distribution'] ?? ($logisticMetrics['class_distribution'] ?? []),
            'challenger_roc_auc_delta_vs_logistic' => $delta,
        ];

        $eligible = $this->metricsMeetThresholds($challengerMetrics, $config['promotion_thresholds']);

        return DB::transaction(function () use (
            $run,
            $horizon,
            $cutoff,
            $config,
            $result,
            $challengerEvidence,
            $tempChallengerArtifactPath,
            $challengerMetrics,
            $eligible,
            $baselineDefinition,
            $configuredFeatureSet,
            $effectiveFeatureSet,
            $excludedFeatures,
            $featureTrainingCoverage,
            $digest,
        ): MlModelVersion {
            $version = ((int) MlModelVersion::query()->where('horizon', $horizon)->lockForUpdate()->max('version')) + 1;
            $artifactPath = $this->artifactPath($horizon, $version);
            if (! rename($tempChallengerArtifactPath, $artifactPath)) {
                throw new \RuntimeException('Unable to activate ML challenger artifact.');
            }

            return MlModelVersion::query()->create([
                'training_run_id' => $run->id,
                'horizon' => $horizon,
                'version' => $version,
                'status' => $eligible ? 'candidate' : 'rejected',
                'artifact_path' => $artifactPath,
                'artifact_sha256' => $digest,
                'artifact_format' => (string) config('ml.artifact_format', 'joblib'),
                'artifact_version' => 'v8-hgb-challenger-1',
                'model_family' => 'hist_gradient_boosting_challenger',
                'training_cutoff_date' => $cutoff->toDateString(),
                'feature_set' => $effectiveFeatureSet,
                'preprocessing' => $result['metadata']['preprocessing'] ?? $config['preprocessing'],
                'label_definition' => $config['label_definition'],
                'benchmark_mapping' => $config['benchmark_mapping'],
                'hyperparameters' => array_merge($config['hyperparameters'], ['challenger_max_iter' => 120, 'challenger_max_depth' => 4]),
                'evaluation_metrics' => $challengerMetrics,
                'promotion_thresholds' => $config['promotion_thresholds'],
                'audit_metadata' => [
                    'chronological_split' => $run->configuration['dataset'] ?? [],
                    'point_in_time_safe' => true,
                    'class_distribution' => $challengerMetrics['class_distribution'] ?? [],
                    'automatic_promotion' => false,
                    'deterministic_baseline' => $baselineDefinition,
                    'configured_feature_set' => $configuredFeatureSet,
                    'effective_feature_set' => $effectiveFeatureSet,
                    'excluded_features' => $excludedFeatures,
                    'feature_training_coverage' => $featureTrainingCoverage,
                    'feature_profile' => $config['feature_profile'] ?? null,
                    'adapter_metadata' => $result['metadata'] ?? [],
                    'challenger_evidence' => $challengerEvidence,
                    'paired_logistic_model_family' => 'interpretable_logistic_baseline',
                ],
            ]);
        });
    }

    private function artifactPath(string $horizon, int $version, ?string $suffix = null): string
    {
        $directory = app(MlArtifactPaths::class)->directory(true);
        return $suffix !== null
            ? $directory.'/model-'.$horizon.'-'.$suffix.'.joblib'
            : $directory.'/model-'.$horizon.'-v'.$version.'.joblib';
    }

    private function assertArtifact(MlModelVersion $model): void
    {
        if ($model->artifact_path === null || $model->artifact_sha256 === null || ! is_file((string) app(MlArtifactPaths::class)->resolve($model->artifact_path))) {
            throw ValidationException::withMessages(['model' => ['The model artifact is missing.']]);
        }
        if (! hash_equals($model->artifact_sha256, (string) hash_file('sha256', (string) app(MlArtifactPaths::class)->resolve($model->artifact_path)))) {
            throw ValidationException::withMessages(['model' => ['The model artifact integrity check failed.']]);
        }
    }

    private function progressService(): MlTrainingRunProgressService
    {
        return $this->trainingProgress ?? app(MlTrainingRunProgressService::class);
    }

    private function cancellationService(): MlTrainingRunCancellationService
    {
        return $this->trainingCancellation ?? app(MlTrainingRunCancellationService::class);
    }

    private function notificationService(): MlLifecycleNotificationService
    {
        return $this->lifecycleNotifications ?? app(MlLifecycleNotificationService::class);
    }

    private function retryService(): MlTrainingRunRetryService
    {
        return $this->trainingRetry ?? app(MlTrainingRunRetryService::class);
    }

    private function latestDriftCheckForHorizon(string $horizon): ?MlDriftCheck
    {
        return MlDriftCheck::query()
            ->whereIn('model_version_id', MlModelVersion::query()->where('horizon', $horizon)->select('id'))
            ->orderByDesc('checked_at')
            ->orderByDesc('id')
            ->first();
    }
}
