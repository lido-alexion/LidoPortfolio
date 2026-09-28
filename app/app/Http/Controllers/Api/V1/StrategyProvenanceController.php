<?php

namespace App\Http\Controllers\Api\V1;

use App\Engines\Support\ApiEnvelope;
use App\Http\Controllers\Controller;
use App\Services\Strategy\StrategyProvenanceMigrationReportService;
use Illuminate\Http\JsonResponse;

class StrategyProvenanceController extends Controller
{
    public function __construct(
        protected StrategyProvenanceMigrationReportService $migrationReport,
    ) {}

    public function migrationReport(): JsonResponse
    {
        return ApiEnvelope::success($this->migrationReport->report());
    }
}
