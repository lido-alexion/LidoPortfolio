<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PortfolioProfile;
use App\Services\PortfolioProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PortfolioController extends Controller
{
    public function __construct(
        protected PortfolioProfileService $portfolios,
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
        ], [
            'name.regex' => 'Use only letters, numbers, spaces, hyphens, and underscores.',
        ]);

        $profile = PortfolioProfile::query()->create([
            'user_id' => $request->user()->id,
            'name' => $validated['name'],
            'is_default' => false,
        ]);

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
