<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Stock;
use App\Services\PortfolioCalculationService;
use App\Services\RelativeStrengthService;
use App\Services\XirrService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    public function __construct(
        protected PortfolioCalculationService $portfolio,
        protected XirrService $xirr,
        protected RelativeStrengthService $relativeStrength,
    ) {}

    public function portfolio(Request $request): JsonResponse
    {
        return response()->json($this->portfolio->calculateForUser($request->user()));
    }

    public function stock(Request $request, Stock $stock): JsonResponse
    {
        $holding = $request->user()->holdings()->where('stock_id', $stock->id)->first();
        $summary = $this->portfolio->calculateForUser($request->user());
        $stockItem = collect($summary['holdings'])->firstWhere('stock_id', $stock->id);

        return response()->json([
            'stock' => $stock->load('metrics'),
            'holding' => $holding,
            'analytics' => $stockItem,
            'xirr' => $this->xirr->calculateStockXirr($request->user(), $stock->id),
            'relative_strength' => $this->relativeStrength->calculateForStock($stock),
        ]);
    }
}
