<?php

namespace App\Http\Controllers\Api\V1;

use App\Engines\Support\ApiEnvelope;
use App\Http\Controllers\Controller;
use App\Services\ML\IntradayHistoricalPlatformService;
use App\Services\ML\IntradayBackfillCheckpointService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class IntradayPlatformAdminController extends Controller
{
    public function __construct(
        protected IntradayHistoricalPlatformService $platform,
    ) {}

    public function status(): JsonResponse
    {
        return ApiEnvelope::success($this->platform->status());
    }

    public function pause(Request $request, IntradayBackfillCheckpointService $checkpoints): JsonResponse
    {
        return ApiEnvelope::success($checkpoints->setPaused(true, $request->user()?->id));
    }

    public function resume(Request $request, IntradayBackfillCheckpointService $checkpoints): JsonResponse
    {
        return ApiEnvelope::success($checkpoints->setPaused(false, $request->user()?->id));
    }
}
