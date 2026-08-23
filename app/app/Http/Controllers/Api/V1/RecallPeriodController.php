<?php

namespace App\Http\Controllers\Api\V1;

use App\Engines\Support\ApiEnvelope;
use App\Http\Controllers\Controller;
use App\Services\Lending\RecallPeriodResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Portfolio recall-period configuration (OD-07 / DEP-RECALL-FLOOR).
 * Eligibility uses committed_at + CURRENT effective period — not min_recall_at.
 */
class RecallPeriodController extends Controller
{
    public function __construct(
        protected RecallPeriodResolver $periods,
    ) {}

    public function show(): JsonResponse
    {
        $profile = \activePortfolio();
        $effective = $this->periods->effectivePeriodDays($profile);
        $overrideRaw = app(\App\Services\ProfileSettingsService::class)
            ->get($profile, RecallPeriodResolver::SETTING_KEY, '');
        $hasOverride = $overrideRaw !== null && trim((string) $overrideRaw) !== '';

        return ApiEnvelope::success([
            'platform_default_days' => RecallPeriodResolver::PLATFORM_DEFAULT_DAYS,
            'portfolio_override_days' => $hasOverride ? (int) $overrideRaw : null,
            'effective_period_days' => $effective,
            'follow_up_cooldown_days' => $this->periods->followUpCooldownDays($profile),
            'source_of_truth' => 'committed_at + current effective_period_days',
            'min_recall_at_is_authoritative' => false,
            'notes' => 'Changing the period does not reset existing loan commitment dates.',
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $profile = \activePortfolio();
        try {
            $validated = $request->validate([
                // null clears override → platform default; DEP-RECALL-FLOOR allows any non-negative int
                'portfolio_recall_period_days' => 'nullable|integer|min:0|max:3650',
                'clear_override' => 'sometimes|boolean',
            ]);
        } catch (ValidationException $e) {
            return ApiEnvelope::error('VALIDATION_ERROR', $e->getMessage(), 422);
        }

        if (! empty($validated['clear_override'])) {
            $this->periods->setPortfolioOverride($profile, null);
        } elseif (array_key_exists('portfolio_recall_period_days', $validated)) {
            $days = $validated['portfolio_recall_period_days'];
            $this->periods->setPortfolioOverride($profile, $days === null ? null : (int) $days);
        } else {
            return ApiEnvelope::error(
                'VALIDATION_ERROR',
                'Provide portfolio_recall_period_days or clear_override.',
                422
            );
        }

        return $this->show();
    }
}
