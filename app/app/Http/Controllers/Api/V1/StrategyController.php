<?php

namespace App\Http\Controllers\Api\V1;

use App\Engines\Support\ApiEnvelope;
use App\Http\Controllers\Controller;
use App\Services\StrategyConfigurationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StrategyController extends Controller
{
    public function __construct(
        protected StrategyConfigurationService $strategies,
    ) {}

    public function active(): JsonResponse
    {
        $profile = \activePortfolio();

        return ApiEnvelope::success($this->strategies->getActiveStrategy($profile));
    }

    public function summary(): JsonResponse
    {
        $profile = \activePortfolio();

        return ApiEnvelope::success($this->strategies->summaryCard($profile));
    }

    public function update(Request $request): JsonResponse
    {
        $profile = \activePortfolio();
        $validated = $request->validate([
            'name' => 'nullable|string|max:120',
            'description' => 'nullable|string|max:2000',
            'change_notes' => 'nullable|string|max:1000',
            'config' => 'required|array',
            'config.eligibility_sources' => 'nullable|array',
            'config.indicators' => 'nullable|array',
            'config.scoring_model' => 'nullable|array',
            'config.factors' => 'nullable|array',
            'config.thresholds' => 'nullable|array',
            'config.portfolio_rules' => 'nullable|array',
            'config.capital_allocation' => 'nullable|array',
            'config.cash_rules' => 'nullable|array',
            'config.exit_strategy' => 'nullable|array',
            'config.recommendation_behaviour' => 'nullable|array',
            'config.risk' => 'nullable|array',
        ]);

        $current = $this->strategies->getActiveStrategy($profile);
        $incoming = $validated['config'];
        if (! isset($incoming['indicators']) && isset($incoming['scoring_model'])) {
            $incoming['indicators'] = $incoming['scoring_model'];
        }
        if (! isset($incoming['indicators']) && isset($incoming['factors'])) {
            $incoming['indicators'] = $incoming['factors'];
        }
        $merged = array_replace_recursive($current['config'] ?? [], $incoming);
        if (isset($incoming['indicators'])) {
            $merged['indicators'] = $incoming['indicators'];
        }
        if (isset($incoming['eligibility_sources'])) {
            $merged['eligibility_sources'] = $incoming['eligibility_sources'];
        }
        if (isset($incoming['exit_strategy']['rules'])) {
            $merged['exit_strategy']['rules'] = $incoming['exit_strategy']['rules'];
        }
        if (isset($incoming['capital_allocation']['score_bands'])) {
            $merged['capital_allocation']['score_bands'] = $incoming['capital_allocation']['score_bands'];
        }

        $payload = $this->strategies->updateActiveConfig(
            $profile,
            $merged,
            $validated['name'] ?? null,
            $validated['description'] ?? null,
            $validated['change_notes'] ?? null,
        );

        return ApiEnvelope::success($payload);
    }

    public function assignScreeners(Request $request): JsonResponse
    {
        $profile = \activePortfolio();
        $validated = $request->validate([
            'eligibility_sources' => 'required|array',
            'eligibility_sources.*.screener_id' => 'required|integer',
            'eligibility_sources.*.enabled' => 'nullable|boolean',
            'eligibility_sources.*.priority' => 'nullable|integer|min:1',
            'eligibility_sources.*.display_order' => 'nullable|integer|min:0',
        ]);

        $current = $this->strategies->getActiveStrategy($profile);
        $merged = $current['config'] ?? [];
        $merged['eligibility_sources'] = $validated['eligibility_sources'];

        $payload = $this->strategies->updateActiveConfig(
            $profile,
            $merged,
            null,
            null,
            'Updated eligibility sources',
        );

        return ApiEnvelope::success($payload);
    }

    public function eligibility(): JsonResponse
    {
        $data = $this->strategies->getActiveStrategy(\activePortfolio());

        return ApiEnvelope::success($data['eligibility_sources'] ?? []);
    }

    public function scoring(): JsonResponse
    {
        $data = $this->strategies->getActiveStrategy(\activePortfolio());

        return ApiEnvelope::success($data['scoring_model'] ?? $data['indicators'] ?? []);
    }

    public function exitStrategy(): JsonResponse
    {
        $data = $this->strategies->getActiveStrategy(\activePortfolio());

        return ApiEnvelope::success($data['exit_strategy'] ?? []);
    }

    public function catalogue(): JsonResponse
    {
        return ApiEnvelope::success(\App\Engines\Strategy\SupportedIndicators::byCategory());
    }

    public function factors(): JsonResponse
    {
        $data = $this->strategies->getActiveStrategy(\activePortfolio());

        return ApiEnvelope::success($data['indicators'] ?? []);
    }

    public function thresholds(): JsonResponse
    {
        $data = $this->strategies->getActiveStrategy(\activePortfolio());

        return ApiEnvelope::success($data['thresholds'] ?? []);
    }

    public function portfolioRules(): JsonResponse
    {
        $data = $this->strategies->getActiveStrategy(\activePortfolio());

        return ApiEnvelope::success($data['portfolio_rules'] ?? []);
    }

    public function capitalAllocation(): JsonResponse
    {
        $data = $this->strategies->getActiveStrategy(\activePortfolio());

        return ApiEnvelope::success($data['capital_allocation'] ?? []);
    }

    public function recommendationRules(): JsonResponse
    {
        $data = $this->strategies->getActiveStrategy(\activePortfolio());

        return ApiEnvelope::success($data['recommendation_behaviour'] ?? []);
    }
}
