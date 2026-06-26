<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Stock;
use App\Services\DailyMarketSyncService;
use App\Services\HoldingPresentationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SyncController extends Controller
{
    public function __construct(
        protected HoldingPresentationService $presentation,
        protected DailyMarketSyncService $dailySync,
    ) {}

    public function daily(Request $request): JsonResponse
    {
        $force = $request->boolean('force');

        $result = $this->dailySync->runDailySyncIfNeeded($force);

        return response()->json($result);
    }

    public function backfill(Request $request, Stock $stock): JsonResponse
    {
        $profile = \activePortfolio();

        if (! $this->presentation->firstBuyDateForCurrentPosition($profile, $stock)) {
            abort(403, 'Stock is not held in the active portfolio.');
        }

        return response()->json($this->presentation->syncHistoricalPrices($profile, $stock));
    }
}
