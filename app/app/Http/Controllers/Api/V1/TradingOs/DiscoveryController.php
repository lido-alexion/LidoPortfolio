<?php

namespace App\Http\Controllers\Api\V1\TradingOs;

use App\Engines\Discovery\DiscoveryEngine;
use App\Engines\Support\ApiEnvelope;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DiscoveryController extends Controller
{
    public function __construct(
        protected DiscoveryEngine $discovery,
    ) {}

    public function discoveryRunsStore(): JsonResponse
    {
        $profile = \activePortfolio();
        $result = $this->discovery->run($profile);

        return ApiEnvelope::success([
            'run' => $result['run'],
            'candidates' => $result['candidates'],
        ], [], 201);
    }

    public function candidates(Request $request): JsonResponse
    {
        $profile = \activePortfolio();
        $runId = $request->query('discovery_run_id') ? (int) $request->query('discovery_run_id') : null;
        $items = $this->discovery->listCandidates(
            $runId,
            $profile,
            $request->query('source'),
            $request->query('search'),
        );

        return ApiEnvelope::success(array_map(fn ($c) => TradingOsPresenter::candidate($c), $items));
    }
}
