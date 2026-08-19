<?php

namespace App\Http\Controllers\Api\V1;

use App\Engines\Support\ApiEnvelope;
use App\Http\Controllers\Controller;
use App\Services\Strategy\PortfolioCapitalAccountingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CapitalAccountingController extends Controller
{
    public function __construct(
        protected PortfolioCapitalAccountingService $capital,
    ) {}

    public function show(): JsonResponse
    {
        $profile = \activePortfolio();

        return ApiEnvelope::success($this->capital->snapshot($profile));
    }

    public function updateAllocations(Request $request): JsonResponse
    {
        $profile = \activePortfolio();
        $validated = $request->validate([
            'allocations' => 'required|array|min:1',
            'allocations.*.strategy_id' => 'required|integer|min:1',
            'allocations.*.allocation_pct' => 'required|numeric|min:0|max:100',
        ]);

        $this->capital->updateEnabledAllocations($profile, $validated['allocations']);

        return ApiEnvelope::success($this->capital->snapshot($profile));
    }

    public function updateReservePct(Request $request): JsonResponse
    {
        $profile = \activePortfolio();
        $validated = $request->validate([
            'portfolio_cash_reserve_pct' => 'required|numeric|min:0|max:100',
        ]);

        $this->capital->updatePortfolioCashReservePct($profile, (float) $validated['portfolio_cash_reserve_pct']);

        return ApiEnvelope::success($this->capital->snapshot($profile));
    }
}
