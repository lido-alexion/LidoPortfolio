<?php

namespace App\Engines\Pipeline;

use App\Engines\Data\DataEngine;
use App\Engines\Discovery\DiscoveryEngine;
use App\Engines\Evaluation\EvaluationEngine;
use App\Engines\Execution\ExecutionEngine;
use App\Engines\Notification\NotificationEngine;
use App\Engines\Recommendation\RecommendationEngine;
use App\Engines\Review\ReviewEngine;
use App\Models\PipelineRun;
use App\Models\PortfolioProfile;
use App\Services\PortfolioLoggerService;
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
    ) {}

    /**
     * @param  array{notify?:bool,review?:bool,skip_data_status?:bool}  $options
     * @return array{pipeline_run: PipelineRun, stages: array<string,mixed>}
     */
    public function run(PortfolioProfile $profile, array $options = []): array
    {
        $notify = $options['notify'] ?? TradingOsConfig::notificationNotifyOnGenerate();
        $doReview = $options['review'] ?? true;

        $pipeline = PipelineRun::query()->create([
            'profile_id' => $profile->id,
            'status' => 'running',
            'started_at' => now(),
            'stages_json' => [],
        ]);

        $stages = [];

        try {
            $stages['data_status'] = $this->data->datasetStatus();

            $discovery = $this->discovery->run($profile);
            $stages['discovery'] = [
                'run_id' => $discovery['run']->id,
                'candidates' => count($discovery['candidates']),
            ];

            $evaluation = $this->evaluation->run($profile, $discovery['run']);
            $stages['evaluation'] = [
                'run_id' => $evaluation['run']->id,
                'results' => count($evaluation['results']),
            ];

            $recs = $this->recommendation->generate($profile, $evaluation['run']);
            $stages['recommendation'] = [
                'count' => count($recs['recommendations']),
                'batch_id' => $recs['batch_id'],
            ];

            if ($notify && count($recs['recommendations']) > 0) {
                $notifications = $this->notification->notifyRecommendations($profile, $recs['recommendations']);
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
            ]);

            throw $e;
        }
    }
}
