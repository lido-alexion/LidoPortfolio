<?php

namespace App\Services\Lending;

use App\Models\PortfolioProfile;
use App\Services\ProfileSettingsService;

/**
 * OD-07 / DEP-RECALL-FLOOR — effective recall period in calendar days.
 * Existing loans use the current setting dynamically (clock = committed_at).
 */
final class RecallPeriodResolver
{
    public const PLATFORM_DEFAULT_DAYS = 14;

    public const SETTING_KEY = 'portfolio_recall_period_days';

    public function __construct(
        protected ProfileSettingsService $profileSettings,
    ) {}

    public function effectivePeriodDays(PortfolioProfile $profile): int
    {
        $raw = $this->profileSettings->get($profile, self::SETTING_KEY, '');
        if ($raw === null || trim((string) $raw) === '') {
            return self::PLATFORM_DEFAULT_DAYS;
        }

        $days = (int) $raw;
        if ($days < 0) {
            return self::PLATFORM_DEFAULT_DAYS;
        }

        return $days;
    }

    public function setPortfolioOverride(PortfolioProfile $profile, ?int $days): void
    {
        if ($days === null) {
            $this->profileSettings->set($profile, self::SETTING_KEY, null);

            return;
        }

        $this->profileSettings->set($profile, self::SETTING_KEY, (string) max(0, $days));
    }

    /** DEP-RECALL-FOLLOWUP: floor(current_effective_period / 2). */
    public function followUpCooldownDays(PortfolioProfile $profile): int
    {
        return intdiv($this->effectivePeriodDays($profile), 2);
    }
}
