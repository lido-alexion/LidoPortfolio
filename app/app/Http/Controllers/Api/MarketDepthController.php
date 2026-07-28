<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Analytics\MarketDepthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarketDepthController extends Controller
{
    public function __construct(
        protected MarketDepthService $marketDepth,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $payload = $this->marketDepth->pagePayload(
            $validated['date'] ?? null,
            MarketDepthService::SCOPE_NSE,
        );

        return response()->json($payload);
    }
}
