<?php

namespace App\Http\Controllers\Api\V1;

use App\Engines\Support\ApiEnvelope;
use App\Http\Controllers\Controller;
use App\Models\Stock;
use App\Services\Analytics\EvaluationProfileService;
use App\Services\Analytics\MarketAnalyticsService;
use App\Services\Analytics\PortfolioAnalyticsService;
use App\Services\Analytics\RecommendationPreviewService;
use App\Services\Analytics\StockAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SD-031 — Analytics APIs with single-owner payloads.
 * F137 — recommendation-preview is the authoritative execution-grade contract.
 */
class AnalyticsArchitectureController extends Controller
{
    public function __construct(
        protected StockAnalyticsService $stockAnalytics,
        protected EvaluationProfileService $evaluationProfiles,
        protected PortfolioAnalyticsService $portfolioAnalytics,
        protected MarketAnalyticsService $marketAnalytics,
        protected RecommendationPreviewService $recommendationPreview,
    ) {}

    public function stock(Stock $stock): JsonResponse
    {
        return ApiEnvelope::success($this->stockAnalytics->forStock($stock));
    }

    public function evaluationProfile(Stock $stock): JsonResponse
    {
        $profile = \activePortfolio();

        return ApiEnvelope::success($this->evaluationProfiles->forStock($profile, $stock));
    }

    public function portfolio(): JsonResponse
    {
        $profile = \activePortfolio();

        return ApiEnvelope::success($this->portfolioAnalytics->forProfile($profile));
    }

    public function market(): JsonResponse
    {
        $profile = \activePortfolio();

        return ApiEnvelope::success($this->marketAnalytics->summary($profile));
    }

    public function recommendationPreview(Request $request, Stock $stock): JsonResponse
    {
        $profile = \activePortfolio();
        $strategyId = $request->query('strategy_id');
        $strategyId = is_numeric($strategyId) ? (int) $strategyId : null;

        $payload = $this->recommendationPreview->forStock($profile, $stock, $strategyId);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        return ApiEnvelope::success($payload);
    }

    public function watchlistResearch(Request $request, Stock $stock): JsonResponse
    {
        $profile = \activePortfolio();
        $strategyId = $request->query('strategy_id');
        $strategyId = is_numeric($strategyId) ? (int) $strategyId : null;

        $preview = $this->recommendationPreview->forStock($profile, $stock, $strategyId);
        if ($preview instanceof JsonResponse) {
            return $preview;
        }

        return ApiEnvelope::success([
            'stock_analytics' => $this->stockAnalytics->forStock($stock),
            'evaluation_profile' => $this->evaluationProfiles->forStock($profile, $stock),
            'recommendation_preview' => $preview,
        ]);
    }

    public function dashboardBundle(Request $request): JsonResponse
    {
        $profile = \activePortfolio();

        return ApiEnvelope::success([
            'portfolio_analytics' => $this->portfolioAnalytics->forProfile($profile),
            'market_analytics' => $this->marketAnalytics->summary($profile),
        ]);
    }
}
