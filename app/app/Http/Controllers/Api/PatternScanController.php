<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PatternScanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PatternScanController extends Controller
{
    public function __construct(protected PatternScanService $scan) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'scope' => ['required', 'string', 'in:holdings,watchlist'],
        ]);

        $profile = \activePortfolio();
        $actionableOnly = $request->boolean('actionable_only', true);

        $payload = $this->scan->scan(
            $profile,
            $validated['scope'],
            $actionableOnly,
        );

        return response()->json($payload);
    }
}
