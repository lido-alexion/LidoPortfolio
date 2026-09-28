<?php

namespace App\Http\Controllers\Api\V1;

use App\Engines\Support\ApiEnvelope;
use App\Http\Controllers\Controller;
use App\Services\ML\IntradayHistoricalPlatformService;
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
}
