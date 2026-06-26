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
        return response()->json($this->portfolio->calculateForProfile(\activePortfolio()));
    }

    public function stock(Request $request, Stock $stock): JsonResponse
    {
        $profile = \activePortfolio();
        $holding = $profile->holdings()->where('stock_id', $stock->id)->first();
        $summary = $this->portfolio->calculateForProfile($profile);
        $stockItem = collect($summary['holdings'])->firstWhere('stock_id', $stock->id);

        return response()->json([
            'stock' => $stock->load('metrics'),
            'holding' => $holding,
            'analytics' => $stockItem,
            'xirr' => $this->xirr->calculateStockXirr($profile, $stock->id),
            'relative_strength' => $this->relativeStrength->calculateForStock($stock),
        ]);
    }
}
