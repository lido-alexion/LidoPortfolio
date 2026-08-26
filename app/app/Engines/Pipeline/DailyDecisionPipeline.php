<?php

namespace App\Engines\Pipeline;

use App\Engines\Data\DataEngine;
use App\Engines\Discovery\DiscoveryEngine;
use App\Engines\Evaluation\EvaluationEngine;
use App\Engines\Evaluation\EvaluationParameterResolver;
use App\Engines\Execution\ExecutionEngine;
use App\Engines\Notification\NotificationEngine;
use App\Engines\Recommendation\RecommendationEngine;
use App\Engines\Review\ReviewEngine;
use App\Exceptions\DomainException;
use App\Models\PipelineRun;
use App\Models\PortfolioProfile;
use App\Models\TradingStrategy;
use App\Services\PortfolioLoggerService;
use App\Services\StrategyConfigurationService;
use App\Support\TradingOsConfig;
use Throwable;

/**
 * Daily Decision Pipeline orchestrator (architecture/05-Daily-Decision-Pipeline.md).
 * Stages are independently executable via their engines; this runs the MVP path end-to-end.
 */
class DailyDecisionPipeline
{
    public function __construct(
        protected DataEngine $data,
        protected DiscoveryEngine $discovery,
        protected EvaluationEngine $evaluation,
        protected RecommendationEngine $recommendation,
        protected NotificationEngine $notification,
        protected ExecutionEngine $execution,
        protected ReviewEngine $review,
        protected PortfolioLoggerService $logger,
        protected EvaluationParameterResolver $parameterResolver,
        protected StrategyConfigurationService $strategies,
        protected DatasetFreshnessGate $datasetFreshness,
    ) {}

    /**
     * @param  array{notify?:bool,review?:bool,trigger?:string,sync_run_id?:string|int|null}  $options
     * @return array{pipeline_run: PipelineRun, stages: array<string,mixed>}
     */
    public function run(PortfolioProfile $profile, array $options = []): array
    {
        $notify = $options['notify'] ?? TradingOsConfig::notificationNotifyOnGenerate();
        $doReview = $options['review'] ?? true;

        $pipelineAt = now();
        $pipeline = PipelineRun::query()->create([
            'profile_id' => $profile->id,
            'status' => 'running',
            'started_at' => $pipelineAt,
            'stages_json' => [],
        ]);

        $stages = [
            '_meta' => [
                'trigger' => (string) ($options['trigger'] ?? 'manual'),
                'sync_run_id' => $options['sync_run_id'] ?? null,
                'started_at' => $pipelineAt->toIso8601String(),
            ],
        ];

        try {
            $stages['data_status'] = $this->data->datasetStatus();
            $freshness = $this->datasetFreshness->evaluate($pipelineAt);
            $stages['publish_gate'] = $freshness;
            if (! ($freshness['allowed'] ?? false)) {
                throw new DomainException(
                    'Daily decision pipeline blocked: market dataset is not within the allowed freshness window.',
                    'DATASET_NOT_FRESH',
                    422,
                );
            }

            $discovery = $this->discovery->run($profile);
            $stages['discovery'] = [
                'run_id' => $discovery['run']->id,
                'candidates' => count($discovery['candidates']),
            ];

            $this->strategies->ensureActive($profile);
            $evalGroups = $this->evaluationGroupsForProfile($profile);
            $allRecs = [];
            $evalRunIds = [];
            $evalResultCount = 0;
            $lastEvalRunId = null;
            $lastBatchId = null;

            foreach ($evalGroups as $group) {
                $evaluation = $this->evaluation->run($profile, $discovery['run'], $group['config']);
                $evalRunIds[] = $evaluation['run']->id;
                $lastEvalRunId = $evaluation['run']->id;
                $evalResultCount += count($evaluation['results']);
                $recs = $this->recommendation->generate(
                    $profile,
                    $evaluation['run'],
                    $group['strategy_ids'],
                );
                $allRecs = array_merge($allRecs, $recs['recommendations']);
                $lastBatchId = $recs['batch_id'];
            }

            $stages['evaluation'] = [
                'run_id' => $lastEvalRunId,
                'run_ids' => $evalRunIds,
                'results' => $evalResultCount,
            ];

            $stages['recommendation'] = [
                'count' => count($allRecs),
                'batch_id' => $lastBatchId,
            ];

            if ($notify && count($allRecs) > 0) {
                $notifications = $this->notification->notifyRecommendations($profile, $allRecs);
                $stages['notification'] = [
                    'count' => count($notifications),
                    'delivered' => count(array_filter($notifications, fn ($n) => $n->status === 'delivered')),
                ];
            } else {
                $stages['notification'] = ['skipped' => true];
            }

            $stages['positions'] = [
                'open' => count($this->execution->listPositions($profile)),
            ];

            if ($doReview) {
                $review = $this->review->generate($profile);
                $stages['review'] = [
                    'report_id' => $review['report']->id,
                    'metrics' => count($review['metrics']),
                ];
            } else {
                $stages['review'] = ['skipped' => true];
            }

            $pipeline->forceFill([
                'status' => 'completed',
                'completed_at' => now(),
                'stages_json' => $stages,
            ])->save();

            $this->logger->log('daily', 'DailyDecisionPipeline', 'info', 'Pipeline completed', [
                'profile_id' => $profile->id,
                'pipeline_run_id' => $pipeline->id,
                'trigger' => $stages['_meta']['trigger'] ?? 'manual',
                'stages' => $stages,
            ]);

            return ['pipeline_run' => $pipeline->fresh(), 'stages' => $stages];
        } catch (Throwable $e) {
            $pipeline->forceFill([
                'status' => 'failed',
                'completed_at' => now(),
                'stages_json' => $stages,
                'error_message' => $e->getMessage(),
            ])->save();

            $this->logger->log('daily', 'DailyDecisionPipeline', 'error', 'Pipeline failed: '.$e->getMessage(), [
                'profile_id' => $profile->id,
                'pipeline_run_id' => $pipeline->id,
                'trigger' => $stages['_meta']['trigger'] ?? 'manual',
            ]);

            throw $e;
        }
    }

    /**
     * Group enabled strategies that share the same FEAT-021 evaluation parameters.
     *
     * @return list<array{config: array<string, mixed>, strategy_ids: list<int>|null}>
     */
    protected function evaluationGroupsForProfile(PortfolioProfile $profile): array
    {
        $strategies = TradingStrategy::query()
            ->where('profile_id', $profile->id)
            ->where('status', TradingStrategy::STATUS_ACTIVE)
            ->with('activeVersion')
            ->orderBy('id')
            ->get();

        $groups = [];
        foreach ($strategies as $strategy) {
            $version = $strategy->activeVersion;
            if ($version === null) {
                continue;
            }
            $configJson = is_array($version->config_json) ? $version->config_json : [];
            $resolved = $this->parameterResolver->resolve($configJson);
            $fingerprint = $this->parameterResolver->fingerprint($resolved);
            if (! isset($groups[$fingerprint])) {
                $groups[$fingerprint] = [
                    'config' => $resolved,
                    'strategy_ids' => [],
                ];
            }
            $groups[$fingerprint]['strategy_ids'][] = (int) $strategy->id;
        }

        if ($groups === []) {
            return [[
                'config' => $this->parameterResolver->globals(),
                'strategy_ids' => null,
            ]];
        }

        return array_values($groups);
    }
}
