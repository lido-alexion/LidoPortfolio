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

    public function active(Request $request): JsonResponse
    {
        $profile = \activePortfolio();

        return ApiEnvelope::success($this->strategies->getActiveStrategy($profile, $this->editorStrategyId($request)));
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
            'strategy_id' => 'nullable|integer|min:1',
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

        $strategyId = $this->editorStrategyId($request);
        $current = $this->strategies->getActiveStrategy($profile, $strategyId);
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
            $strategyId,
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

        $current = $this->strategies->getActiveStrategy($profile, $this->editorStrategyId($request));
        $merged = $current['config'] ?? [];
        $merged['eligibility_sources'] = $validated['eligibility_sources'];

        $payload = $this->strategies->updateActiveConfig(
            $profile,
            $merged,
            null,
            null,
            'Updated eligibility sources',
            $this->editorStrategyId($request),
        );

        return ApiEnvelope::success($payload);
    }

    public function eligibility(Request $request): JsonResponse
    {
        $data = $this->strategies->getActiveStrategy(\activePortfolio(), $this->editorStrategyId($request));

        return ApiEnvelope::success($data['eligibility_sources'] ?? []);
    }

    public function scoring(Request $request): JsonResponse
    {
        $data = $this->strategies->getActiveStrategy(\activePortfolio(), $this->editorStrategyId($request));

        return ApiEnvelope::success($data['scoring_model'] ?? $data['indicators'] ?? []);
    }

    public function exitStrategy(Request $request): JsonResponse
    {
        $data = $this->strategies->getActiveStrategy(\activePortfolio(), $this->editorStrategyId($request));

        return ApiEnvelope::success($data['exit_strategy'] ?? []);
    }

    public function catalogue(): JsonResponse
    {
        return ApiEnvelope::success(\App\Engines\Strategy\SupportedIndicators::byCategory());
    }

    public function factors(Request $request): JsonResponse
    {
        $data = $this->strategies->getActiveStrategy(\activePortfolio(), $this->editorStrategyId($request));

        return ApiEnvelope::success($data['indicators'] ?? []);
    }

    public function thresholds(Request $request): JsonResponse
    {
        $data = $this->strategies->getActiveStrategy(\activePortfolio(), $this->editorStrategyId($request));

        return ApiEnvelope::success($data['thresholds'] ?? []);
    }

    public function portfolioRules(Request $request): JsonResponse
    {
        $data = $this->strategies->getActiveStrategy(\activePortfolio(), $this->editorStrategyId($request));

        return ApiEnvelope::success($data['portfolio_rules'] ?? []);
    }

    public function capitalAllocation(Request $request): JsonResponse
    {
        $data = $this->strategies->getActiveStrategy(\activePortfolio(), $this->editorStrategyId($request));

        return ApiEnvelope::success($data['capital_allocation'] ?? []);
    }

    public function recommendationRules(Request $request): JsonResponse
    {
        $data = $this->strategies->getActiveStrategy(\activePortfolio(), $this->editorStrategyId($request));

        return ApiEnvelope::success($data['recommendation_behaviour'] ?? []);
    }

    /**
     * UI editor selection — not an exclusive-active domain constraint.
     */
    protected function editorStrategyId(Request $request): ?int
    {
        $raw = $request->query('strategy_id', $request->input('strategy_id'));
        if ($raw === null || $raw === '') {
            return null;
        }

        $id = (int) $raw;

        return $id > 0 ? $id : null;
    }
}
