<?php

namespace Database\Seeders;

use App\Models\PortfolioProfile;
use App\Services\StrategyConfigurationService;
use App\Services\StrategyEligibilityService;
use Illuminate\Database\Seeder;

/**
 * SD-029 / SD-030: Seed factory Minervini screener + Momentum Strategy per profile.
 */
class FactoryMomentumStrategySeeder extends Seeder
{
    public function run(): void
    {
        $strategies = app(StrategyConfigurationService::class);
        $eligibility = app(StrategyEligibilityService::class);

        PortfolioProfile::query()->orderBy('id')->each(function (PortfolioProfile $profile) use ($strategies, $eligibility) {
            $eligibility->ensureMinerviniScreener($profile);
            $strategies->seedFactoryStrategy($profile);
        });
    }
}
