<?php

namespace App\Http\Controllers\Api\V1;

use App\Engines\Support\ApiEnvelope;
use App\Http\Controllers\Controller;
use App\Services\Analytics\MarketAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarketAnalysisController extends Controller
{
    public function __construct(
        protected MarketAnalyticsService $market,
    ) {}

    public function latest(Request $request): JsonResponse
    {
        $force = $request->boolean('refresh');

        return ApiEnvelope::success($this->market->latest($force));
    }

    public function sentiment(): JsonResponse
    {
        return ApiEnvelope::success($this->market->sentiment());
    }

    public function phase(): JsonResponse
    {
        return ApiEnvelope::success($this->market->phase());
    }

    public function history(Request $request): JsonResponse
    {
        $days = max(7, min(365, (int) $request->query('days', 90)));

        return ApiEnvelope::success([
            'days' => $days,
            'items' => $this->market->history($days),
        ]);
    }

    public function timeline(Request $request): JsonResponse
    {
        return $this->history($request);
    }

    public function explainability(): JsonResponse
    {
        return ApiEnvelope::success($this->market->explainability());
    }
}
