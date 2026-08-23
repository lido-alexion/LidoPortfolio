<?php

namespace App\Http\Controllers\Api\V1;

use App\Engines\Support\ApiEnvelope;
use App\Http\Controllers\Controller;
use App\Models\TradingRecommendation;
use App\Services\Lending\CapitalResolutionService;
use App\Services\Lending\CapitalResolutionStatusService;
use App\Models\TradingStrategy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CapitalResolutionController extends Controller
{
    public function __construct(
        protected CapitalResolutionStatusService $status,
        protected CapitalResolutionService $resolution,
    ) {}

    public function forRecommendation(int $recommendation): JsonResponse
    {
        $profile = \activePortfolio();
        $rec = TradingRecommendation::query()
            ->forProfile($profile)
            ->where('id', $recommendation)
            ->first();
        if ($rec === null) {
            return ApiEnvelope::error('NOT_FOUND', 'Recommendation not found.', 404);
        }

        return ApiEnvelope::success($this->status->forRecommendation($profile, $rec));
    }

    /**
     * Run capital resolution for a strategy funding need and optionally attach snapshot to a recommendation.
     */
    public function resolve(Request $request): JsonResponse
    {
        $profile = \activePortfolio();
        try {
            $validated = $request->validate([
                'strategy_id' => 'required|integer|min:1',
                'required_amount' => 'required|numeric|min:0',
                'recommendation_id' => 'nullable|integer|min:1',
                'bridge_lender_strategy_id' => 'nullable|integer|min:1',
            ]);
        } catch (ValidationException $e) {
            return ApiEnvelope::error('VALIDATION_ERROR', $e->getMessage(), 422);
        }

        $strategy = TradingStrategy::query()
            ->where('profile_id', $profile->id)
            ->where('id', (int) $validated['strategy_id'])
            ->first();
        if ($strategy === null) {
            return ApiEnvelope::error('STRATEGY_NOT_FOUND', 'Unknown strategy for this portfolio.', 422);
        }

        $bridgeLender = null;
        if (! empty($validated['bridge_lender_strategy_id'])) {
            $bridgeLender = TradingStrategy::query()
                ->where('profile_id', $profile->id)
                ->where('id', (int) $validated['bridge_lender_strategy_id'])
                ->first();
            if ($bridgeLender === null) {
                return ApiEnvelope::error('STRATEGY_NOT_FOUND', 'Unknown bridge lender strategy.', 422);
            }
        }

        $result = $this->resolution->resolveForStrategy(
            $profile,
            $strategy,
            (float) $validated['required_amount'],
            ['bridge_lender' => $bridgeLender]
        );

        if (! empty($validated['recommendation_id'])) {
            $rec = TradingRecommendation::query()
                ->forProfile($profile)
                ->where('id', (int) $validated['recommendation_id'])
                ->first();
            if ($rec === null) {
                return ApiEnvelope::error('NOT_FOUND', 'Recommendation not found.', 404);
            }
            $this->status->attachSnapshot($rec, $result);
            $ui = $this->status->forRecommendation($profile, $rec->fresh());

            return ApiEnvelope::success([
                'resolution' => $result,
                'recommendation_capital_resolution' => $ui,
            ]);
        }

        return ApiEnvelope::success([
            'resolution' => $result,
            'actual_execution_amount' => $result['actual_available'],
            'close_at_actual' => true,
        ]);
    }
}
