<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Holding;
use App\Services\HoldingPresentationService;
use App\Services\HoldingsCalculationService;
use App\Services\Ownership\HoldingAdoptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HoldingController extends Controller
{
    public function __construct(
        protected HoldingsCalculationService $holdings,
        protected HoldingPresentationService $presentation,
        protected HoldingAdoptionService $adoption,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $profile = \activePortfolio();
        $this->holdings->recalculateForProfile($profile);

        $holdings = $profile
            ->holdings()
            ->with('stock.metrics')
            ->where('quantity', '>', 0)
            ->get();

        $data = $holdings->map(
            fn ($holding) => $this->presentation->enrichHolding($profile, $holding),
        )->values();

        return response()->json(['data' => $data]);
    }

    public function adopt(Request $request, int $holding): JsonResponse
    {
        $profile = \activePortfolio();
        $payload = $request->validate([
            'strategy_id' => ['required', 'integer', 'min:1'],
        ]);

        $model = Holding::query()
            ->where('profile_id', $profile->id)
            ->where('id', $holding)
            ->first();
        if ($model === null) {
            return response()->json(['message' => 'Holding not found.'], 404);
        }

        $result = $this->adoption->adopt(
            $profile,
            $model,
            (int) $payload['strategy_id'],
            $request->user(),
        );

        return response()->json([
            'data' => $this->presentation->enrichHolding($profile, $result['holding']),
            'adoption' => [
                'id' => $result['adoption']->id,
                'idempotent' => $result['idempotent'],
                'to_strategy_id' => $result['adoption']->to_strategy_id,
                'to_owner_key' => $result['adoption']->to_owner_key,
                'target_amount' => $result['adoption']->target_amount,
            ],
        ]);
    }
}
