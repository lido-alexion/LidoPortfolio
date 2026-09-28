<?php

namespace App\Http\Controllers\Api\V1;

use App\Engines\Support\ApiEnvelope;
use App\Http\Controllers\Controller;
use App\Models\Stock;
use App\Models\V7\MlModelVersion;
use App\Models\V7\MlTrainingRun;
use App\Services\ML\MlDriftService;
use App\Services\ML\MlFeatureRegistryService;
use App\Services\ML\MlScoringService;
use App\Services\ML\MlTrainingDatasetBuilder;
use App\Services\ML\MlArtifactRetentionService;
use App\Services\ML\MlLifecycleAutomationService;
use App\Services\ML\MlTrainingRunAdminService;
use App\Services\ML\MlTrainingRunCancellationService;
use App\Telemetry\LidoTelemetry;
use App\Telemetry\LidoTelemetryCatalog;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MlScoringController extends Controller
{
    public function __construct(
        protected MlScoringService $ml,
        protected MlDriftService $drift,
        protected MlFeatureRegistryService $featureRegistry,
        protected MlTrainingDatasetBuilder $datasets,
        protected MlTrainingRunAdminService $trainingRuns,
        protected MlArtifactRetentionService $retention,
        protected MlTrainingRunCancellationService $trainingCancellation,
    ) {}

    public function retentionPlan(Request $request): JsonResponse
    {
        $apply = $request->boolean('apply');
        if ($apply) {
            return ApiEnvelope::success([
                'applied' => $this->retention->pruneAll(dryRun: false),
            ]);
        }

        return ApiEnvelope::success($this->retention->plan());
    }

    public function adminIndex(): JsonResponse
    {
        return ApiEnvelope::success($this->ml->dashboard());
    }

    public function updateSchedule(Request $request, string $horizon): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'schedule' => ['required', 'string', 'max:64'],
        ]);

        return ApiEnvelope::success([
            'schedule' => app(MlLifecycleAutomationService::class)->updateSchedule(
                $horizon,
                (bool) $validated['enabled'],
                $validated['schedule'],
                $request->user(),
            ),
        ]);
    }

    public function featureRegistry(): JsonResponse
    {
        return ApiEnvelope::success($this->featureRegistry->catalog());
    }

    public function datasetPlan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'horizon' => ['required', 'in:1m,3m,6m'],
            'cutoff_date' => ['nullable', 'date'],
        ]);

        $cutoff = isset($validated['cutoff_date'])
            ? Carbon::parse($validated['cutoff_date'])
            : now();

        return ApiEnvelope::success([
            'feature_set' => $this->featureRegistry->featureSetForHorizon($validated['horizon']),
            'dataset_plan' => $this->datasets->plan($validated['horizon'], $cutoff),
        ]);
    }

    public function retrain(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'horizon' => ['required', 'in:1m,3m,6m'],
            'cutoff_date' => ['nullable', 'date'],
            'configuration' => ['nullable', 'array'],
        ]);

        $model = $this->ml->retrain(
            $validated['horizon'],
            isset($validated['cutoff_date']) ? Carbon::parse($validated['cutoff_date']) : null,
            $request->user(),
            is_array($validated['configuration'] ?? null) ? $validated['configuration'] : [],
        );

        return ApiEnvelope::success(['model' => $model->toArray()], [], 201);
    }

    public function queueRetrain(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'horizon' => ['required', 'in:1m,3m,6m'],
        ]);

        $this->ml->queueAdminRetrain($validated['horizon'], $request->user());

        return ApiEnvelope::success([
            'queued' => true,
            'horizon' => $validated['horizon'],
        ], [], 202);
    }

    public function trainingRuns(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'horizon' => ['nullable', 'in:1m,3m,6m'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return ApiEnvelope::success($this->trainingRuns->list(
            $validated['horizon'] ?? null,
            (int) ($validated['limit'] ?? 25),
        ));
    }

    public function trainingRun(MlTrainingRun $run): JsonResponse
    {
        return ApiEnvelope::success($this->trainingRuns->show($run));
    }

    public function cancelTrainingRun(Request $request, MlTrainingRun $run): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        return ApiEnvelope::success(
            $this->trainingCancellation->request($run, $request->user(), $validated['reason'] ?? null),
        );
    }

    public function trainingRunStream(MlTrainingRun $run): StreamedResponse
    {
        $interval = max(0, (int) config('ml_lifecycle.sse.poll_interval_seconds', 2));
        $maxPolls = max(1, (int) config('ml_lifecycle.sse.max_polls', 90));

        return response()->stream(function () use ($run, $interval, $maxPolls): void {
            for ($poll = 0; $poll < $maxPolls; $poll++) {
                $payload = $this->trainingRuns->progressPayload($run);
                echo 'event: progress'."\n";
                echo 'data: '.json_encode($payload, JSON_THROW_ON_ERROR)."\n\n";
                if (function_exists('ob_flush')) {
                    @ob_flush();
                }
                flush();
                $status = (string) ($payload['status'] ?? '');
                if (! in_array($status, ['running', 'cancelling', 'queued'], true)) {
                    break;
                }
                if ($interval > 0 && $poll < $maxPolls - 1) {
                    sleep($interval);
                }
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function promotionReview(MlModelVersion $model): JsonResponse
    {
        return ApiEnvelope::success($this->ml->promotionReview($model));
    }

    public function promote(Request $request, MlModelVersion $model): JsonResponse
    {
        return ApiEnvelope::success(['model' => $this->ml->promote($model, $request->user())->toArray()]);
    }

    public function rollback(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'horizon' => ['required', 'in:1m,3m,6m'],
            'version' => ['required', 'integer', 'min:1'],
        ]);

        return ApiEnvelope::success([
            'model' => $this->ml->rollback($validated['horizon'], (int) $validated['version'], $request->user())->toArray(),
        ]);
    }

    public function driftCheck(Request $request, MlModelVersion $model): JsonResponse
    {
        $validated = $request->validate(['window_months' => ['nullable', 'integer', 'in:3,6,12']]);
        $check = $this->drift->check($model, (int) ($validated['window_months'] ?? 3));

        return ApiEnvelope::success(['drift_check' => $check->toArray()]);
    }

    public function predict(Request $request, Stock $stock): JsonResponse
    {
        $validated = $request->validate([
            'horizon' => ['required', 'in:1m,3m,6m'],
            'as_of' => ['nullable', 'date'],
            'shadow' => ['nullable', 'boolean'],
        ]);

        $prediction = $this->ml->predict(
            $stock,
            $validated['horizon'],
            isset($validated['as_of']) ? Carbon::parse($validated['as_of']) : null,
            $request->boolean('shadow'),
        );

        return ApiEnvelope::success(['prediction' => $prediction?->toArray()]);
    }

    public function stockMlInsights(Stock $stock): JsonResponse
    {
        app(LidoTelemetry::class)->recordBusinessEvent(LidoTelemetryCatalog::BUSINESS_ML_INSIGHTS_VIEW, [
            'stock_id' => $stock->id,
        ]);

        return ApiEnvelope::success($this->ml->investorHorizonInsights($stock));
    }

    public function refreshStockMlInsights(Request $request, Stock $stock): JsonResponse
    {
        $validated = $request->validate([
            'horizons' => ['sometimes', 'array'],
            'horizons.*' => ['in:1m,3m,6m'],
        ]);

        return ApiEnvelope::success(
            $this->ml->refreshInvestorHorizonInsights($stock, $validated['horizons'] ?? []),
        );
    }
}
