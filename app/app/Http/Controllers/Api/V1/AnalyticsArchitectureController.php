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

    public function recommendationPreview(Stock $stock): JsonResponse
    {
        $profile = \activePortfolio();

        return ApiEnvelope::success($this->recommendationPreview->forStock($profile, $stock));
    }

    public function watchlistResearch(Stock $stock): JsonResponse
    {
        $profile = \activePortfolio();

        return ApiEnvelope::success([
            'stock_analytics' => $this->stockAnalytics->forStock($stock),
            'evaluation_profile' => $this->evaluationProfiles->forStock($profile, $stock),
            'recommendation_preview' => $this->recommendationPreview->forStock($profile, $stock),
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
