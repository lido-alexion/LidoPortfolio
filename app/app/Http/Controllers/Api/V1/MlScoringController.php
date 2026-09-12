<?php

namespace App\Http\Controllers\Api\V1;

use App\Engines\Support\ApiEnvelope;
use App\Http\Controllers\Controller;
use App\Models\Stock;
use App\Models\V7\MlModelVersion;
use App\Services\ML\MlScoringService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MlScoringController extends Controller
{
    public function __construct(protected MlScoringService $ml) {}

    public function adminIndex(): JsonResponse
    {
        return ApiEnvelope::success($this->ml->dashboard());
    }

    public function retrain(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'horizon' => ['required', 'in:1m,3m,6m'],
            'cutoff_date' => ['nullable', 'date'],
            'configuration' => ['nullable', 'array'],
        ]);

        $model = $this->ml->retrain(
            $validated['horizon'],
            isset($validated['cutoff_date']) ? Carbon::parse($validated['cutoff_date']) : null,
            $request->user(),
            is_array($validated['configuration'] ?? null) ? $validated['configuration'] : [],
        );

        return ApiEnvelope::success(['model' => $model->toArray()], [], 201);
    }

    public function promote(Request $request, MlModelVersion $model): JsonResponse
    {
        return ApiEnvelope::success(['model' => $this->ml->promote($model, $request->user())->toArray()]);
    }

    public function rollback(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'horizon' => ['required', 'in:1m,3m,6m'],
            'version' => ['required', 'integer', 'min:1'],
        ]);

        return ApiEnvelope::success([
            'model' => $this->ml->rollback($validated['horizon'], (int) $validated['version'], $request->user())->toArray(),
        ]);
    }

    public function predict(Request $request, Stock $stock): JsonResponse
    {
        $validated = $request->validate([
            'horizon' => ['required', 'in:1m,3m,6m'],
            'as_of' => ['nullable', 'date'],
            'shadow' => ['nullable', 'boolean'],
        ]);

        $prediction = $this->ml->predict(
            $stock,
            $validated['horizon'],
            isset($validated['as_of']) ? Carbon::parse($validated['as_of']) : null,
            $request->boolean('shadow'),
        );

        return ApiEnvelope::success(['prediction' => $prediction?->toArray()]);
    }
}
