<?php

namespace App\Services\Entry;

use App\Models\PortfolioProfile;
use App\Services\ProfileSettingsService;

/**
 * OD-12 / §12.4 — minimum actionable BUY/INCREASE this-cycle amount.
 *
 * Platform default ₹5,000; optional portfolio override. Not OD-06 atomic block.
 */
final class MinimumActionableAmountResolver
{
    public const PLATFORM_DEFAULT = 5000.0;

    public const SETTING_KEY = 'minimum_actionable_buy_amount';

    public function __construct(
        protected ProfileSettingsService $settings,
    ) {}

    public function effectiveMinimum(PortfolioProfile $profile): float
    {
        $raw = $this->settings->get($profile, self::SETTING_KEY, null);
        if ($raw === null || $raw === '') {
            return self::PLATFORM_DEFAULT;
        }

        $value = (float) $raw;

        return $value >= 0 ? $value : self::PLATFORM_DEFAULT;
    }

    public function isActionable(float $thisCycleOpportunityAmount, PortfolioProfile $profile): bool
    {
        return $thisCycleOpportunityAmount + 0.00001 >= $this->effectiveMinimum($profile);
    }
}
