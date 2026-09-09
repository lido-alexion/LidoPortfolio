<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnalysisPreference;
use App\Models\Benchmark;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AnalysisPreferenceController extends Controller
{
    public function benchmarks(): JsonResponse
    {
        return response()->json([
            'data' => Benchmark::query()
                ->where('is_active', true)
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get(['id', 'stable_key', 'name', 'symbol', 'return_type', 'currency', 'provider', 'provenance', 'is_default']),
        ]);
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->present($request)]);
    }

    public function update(Request $request): JsonResponse
    {
        $activeBenchmarkIds = Benchmark::query()->where('is_active', true)->pluck('id')->all();
        $validated = $request->validate([
            'primary_benchmark_id' => ['nullable', 'integer', Rule::in($activeBenchmarkIds)],
            'comparison_benchmark_ids' => ['sometimes', 'array', 'max:5'],
            'comparison_benchmark_ids.*' => ['integer', 'distinct', Rule::in($activeBenchmarkIds)],
            'include_in_account_performance' => ['sometimes', 'boolean'],
            'include_in_account_tax' => ['sometimes', 'boolean'],
            'risk_free_rate' => ['nullable', 'numeric', 'between:-0.25,1'],
            'annualization_days' => ['nullable', 'integer', 'between:1,366'],
        ]);

        if (isset($validated['comparison_benchmark_ids'])) {
            $validated['comparison_benchmark_ids'] = array_values(array_filter(
                $validated['comparison_benchmark_ids'],
                fn (int $id): bool => $id !== ($validated['primary_benchmark_id'] ?? null),
            ));
        }

        $profile = \activePortfolio();
        AnalysisPreference::query()->updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'scope_key' => 'portfolio:'.$profile->id,
            ],
            [
                'profile_id' => $profile->id,
                ...$validated,
            ],
        );

        return response()->json(['data' => $this->present($request)]);
    }

    /** @return array<string, mixed> */
    private function present(Request $request): array
    {
        $profile = \activePortfolio();
        $preference = AnalysisPreference::query()
            ->where('user_id', $request->user()->id)
            ->where('scope_key', 'portfolio:'.$profile->id)
            ->first();
        $defaultBenchmark = Benchmark::query()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();

        return [
            'scope' => 'portfolio',
            'profile_id' => $profile->id,
            'primary_benchmark_id' => $preference?->primary_benchmark_id ?? $defaultBenchmark?->id,
            'comparison_benchmark_ids' => $preference?->comparison_benchmark_ids ?? [],
            'include_in_account_performance' => $preference?->include_in_account_performance ?? true,
            'include_in_account_tax' => $preference?->include_in_account_tax ?? true,
            'risk_free_rate' => $preference?->risk_free_rate === null ? null : (float) $preference->risk_free_rate,
            'annualization_days' => $preference?->annualization_days,
            'defaults' => [
                'benchmark_stable_key' => $defaultBenchmark?->stable_key,
                'annualization_days' => 252,
            ],
        ];
    }
}
