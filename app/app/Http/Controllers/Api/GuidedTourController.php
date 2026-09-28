<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GuidedTour\GuidedTourService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GuidedTourController extends Controller
{
    public function __construct(
        protected GuidedTourService $tour,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->tour->toPayload($request->user()),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'string', 'max:64'],
            'step_id' => ['nullable', 'string', 'max:64'],
            'restart' => ['nullable', 'boolean'],
        ]);

        $payload = $this->tour->applyAction(
            $request->user(),
            $validated['action'],
            $validated,
        );

        return response()->json(['data' => $payload]);
    }
}
