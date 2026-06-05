<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\HoldingPresentationService;
use App\Services\HoldingsCalculationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HoldingController extends Controller
{
    public function __construct(
        protected HoldingsCalculationService $holdings,
        protected HoldingPresentationService $presentation,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->holdings->recalculateForUser($request->user());

        $holdings = $request->user()
            ->holdings()
            ->with('stock.metrics')
            ->where('quantity', '>', 0)
            ->get();

        $data = $holdings->map(
            fn ($holding) => $this->presentation->enrichHolding($request->user(), $holding),
        )->values();

        return response()->json(['data' => $data]);
    }
}
