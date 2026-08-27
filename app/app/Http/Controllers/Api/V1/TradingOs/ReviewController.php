<?php

namespace App\Http\Controllers\Api\V1\TradingOs;

use App\Engines\Review\ReviewEngine;
use App\Engines\Support\ApiEnvelope;
use App\Http\Controllers\Controller;
use App\Support\TradingOsPagination;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function __construct(
        protected ReviewEngine $review,
    ) {}

    public function reviewsGenerate(Request $request): JsonResponse
    {
        $profile = \activePortfolio();
        $start = $request->query('period_start')
            ? Carbon::parse($request->query('period_start'))->startOfDay()
            : null;
        $end = $request->query('period_end')
            ? Carbon::parse($request->query('period_end'))->startOfDay()
            : null;

        $result = $this->review->generate($profile, $start, $end);

        return ApiEnvelope::success([
            'report' => $result['report'],
            'metrics' => $result['metrics'],
        ], [], 201);
    }

    public function reviewsIndex(Request $request): JsonResponse
    {
        $profile = \activePortfolio();
        $page = TradingOsPagination::resolve($request, TradingOsPagination::REVIEWS_DEFAULT);
        $paginator = $this->review->paginateReports($profile, $page['page'], $page['pageSize']);

        return ApiEnvelope::success($paginator->items(), TradingOsPagination::meta($paginator));
    }

    public function reviewsShow(int $id): JsonResponse
    {
        $profile = \activePortfolio();
        $report = $this->review->findReport($profile, $id);
        if (! $report) {
            return ApiEnvelope::error('NOT_FOUND', 'Review report not found.', 404);
        }

        return ApiEnvelope::success($report);
    }

    public function reviewDashboard(): JsonResponse
    {
        $profile = \activePortfolio();

        return ApiEnvelope::success($this->review->dashboard($profile));
    }

    public function reviewOutcomes(): JsonResponse
    {
        $profile = \activePortfolio();

        return ApiEnvelope::success($this->review->recommendationOutcomes($profile));
    }
}
