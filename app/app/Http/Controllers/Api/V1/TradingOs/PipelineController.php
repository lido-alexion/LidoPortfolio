<?php

namespace App\Http\Controllers\Api\V1\TradingOs;

use App\Engines\Pipeline\DailyDecisionPipeline;
use App\Engines\Support\ApiEnvelope;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PipelineController extends Controller
{
    public function __construct(
        protected DailyDecisionPipeline $pipeline,
    ) {}

    public function pipelineRun(Request $request): JsonResponse
    {
        $profile = \activePortfolio();
        $result = $this->pipeline->run($profile, [
            'notify' => $request->boolean('notify', true),
            'review' => $request->boolean('review', true),
        ]);

        return ApiEnvelope::success([
            'pipeline_run' => $result['pipeline_run'],
            'stages' => $result['stages'],
        ], [], 201);
    }
}
