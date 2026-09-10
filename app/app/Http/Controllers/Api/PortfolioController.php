<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PortfolioProfile;
use App\Services\PortfolioProfileService;
use App\Services\CashManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PortfolioController extends Controller
{
    public function __construct(
        protected PortfolioProfileService $portfolios,
        protected CashManagementService $cash,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->portfolios->listForUser($request->user()),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9 _-]+$/'],
            'portfolio_type' => ['sometimes', Rule::in(PortfolioProfile::TYPES)],
            'starting_cash' => ['required_if:portfolio_type,paper', 'nullable', 'numeric', 'gt:0'],
            'simulation_price_method' => ['required_if:portfolio_type,paper', 'nullable', Rule::in(PortfolioProfile::SIMULATION_PRICE_METHODS)],
        ], [
            'name.regex' => 'Use only letters, numbers, spaces, hyphens, and underscores.',
        ]);

        $type = $validated['portfolio_type'] ?? PortfolioProfile::TYPE_LIVE;
        $profile = DB::transaction(function () use ($request, $validated, $type) {
            $profile = PortfolioProfile::query()->create([
                'user_id' => $request->user()->id,
                'name' => $validated['name'],
                'is_default' => false,
                'portfolio_type' => $type,
                'execution_mode' => PortfolioProfile::EXECUTION_MODE_MANUAL,
                'simulation_state' => $type === PortfolioProfile::TYPE_PAPER ? PortfolioProfile::SIMULATION_ACTIVE : null,
                'simulation_price_method' => $type === PortfolioProfile::TYPE_PAPER
                    ? $validated['simulation_price_method'] : null,
                'simulation_evidence' => $type === PortfolioProfile::TYPE_PAPER ? [
                    'provenance' => 'paper_simulation',
                    'starting_cash' => (float) $validated['starting_cash'],
                    'created_at' => now()->toISOString(),
                ] : null,
            ]);
            if ($profile->isPaper()) {
                $this->cash->deposit(
                    $profile, (float) $validated['starting_cash'], 'Paper starting simulated cash', $request->user(),
                );
            }

            return $profile;
        });

        return response()->json(['data' => $profile], 201);
    }

    public function show(PortfolioProfile $portfolio): JsonResponse
    {
        return response()->json(['data' => $portfolio]);
    }

    public function update(Request $request, PortfolioProfile $portfolio): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9 _-]+$/'],
            'portfolio_type' => ['prohibited'],
        ], [
            'name.regex' => 'Use only letters, numbers, spaces, hyphens, and underscores.',
        ]);

        $portfolio->update(['name' => $validated['name']]);

        return response()->json(['data' => $portfolio->fresh()]);
    }

    public function destroy(Request $request, PortfolioProfile $portfolio): JsonResponse
    {
        $headerId = $request->header('X-Profile-Id') ?? $request->header('X-Portfolio-Id');
        $activeProfileId = ($headerId !== null && $headerId !== '') ? (int) $headerId : null;

        $this->portfolios->deleteForUser($request->user(), $portfolio, $activeProfileId);

        return response()->json(['message' => 'Portfolio deleted']);
    }

    public function setDefault(Request $request, PortfolioProfile $portfolio): JsonResponse
    {
        $profile = $this->portfolios->setDefault($request->user(), $portfolio);

        return response()->json(['data' => $profile]);
    }
}
