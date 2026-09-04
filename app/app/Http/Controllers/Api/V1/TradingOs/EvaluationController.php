<?php

namespace App\Http\Controllers\Api\V1\TradingOs;

use App\Engines\Evaluation\EvaluationEngine;
use App\Engines\Evaluation\EvaluationParameterResolver;
use App\Engines\Support\ApiEnvelope;
use App\Http\Controllers\Controller;
use App\Services\StrategyConfigurationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EvaluationController extends Controller
{
    public function __construct(
        protected EvaluationEngine $evaluation,
        protected StrategyConfigurationService $strategies,
        protected EvaluationParameterResolver $parameterResolver,
    ) {}

    public function evaluationRunsStore(): JsonResponse
    {
        $profile = \activePortfolio();

        try {
            $version = $this->strategies->ensureActive($profile);
            $configJson = is_array($version->config_json) ? $version->config_json : [];
            $resolved = $this->parameterResolver->resolve($configJson);
            $result = $this->evaluation->run($profile, null, $resolved);
        } catch (\RuntimeException $e) {
            return ApiEnvelope::error('EVALUATION_PRECONDITION', $e->getMessage(), 422);
        }

        $run = $result['run'];

        return ApiEnvelope::success([
            'run' => TradingOsPresenter::evaluationRun($run),
            'results' => array_map(fn ($r) => TradingOsPresenter::evaluation($r), $result['results']),
        ], [], 201);
    }

    public function evaluations(Request $request): JsonResponse
    {
        $profile = \activePortfolio();
        $runId = $request->query('evaluation_run_id') ? (int) $request->query('evaluation_run_id') : null;
        $items = $this->evaluation->listResults($runId, $profile);

        return ApiEnvelope::success(array_map(fn ($r) => TradingOsPresenter::evaluation($r), $items));
    }

    public function evaluationRunsIndex(Request $request): JsonResponse
    {
        $profile = \activePortfolio();
        $limit = max(1, min((int) $request->query('limit', 20), 50));
        $items = $this->evaluation->listRuns($profile, $limit);

        return ApiEnvelope::success(array_map(fn ($run) => TradingOsPresenter::evaluationRun($run), $items));
    }
}
