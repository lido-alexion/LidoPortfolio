<?php

namespace Tests;

use App\Models\PortfolioProfile;
use App\Services\StrategyConfigurationService;
use App\Models\TradingStrategyVersion;
use App\Services\StrategyEligibilityService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Concerns\CreatesPortfolioProfiles;

abstract class TestCase extends BaseTestCase
{
    use CreatesPortfolioProfiles;

    /** Return the frozen executable Strategy contract for activation fixtures. */
    protected function executableStrategyConfig(PortfolioProfile $profile): array
    {
        return app(StrategyConfigurationService::class)
            ->seedFactoryStrategy($profile)
            ->activeVersion
            ->config_json;
    }

    /** Create a test version and pin its declared Screener dependencies at creation time. */
    protected function createTestStrategyVersion(array $attributes): TradingStrategyVersion
    {
        $version = TradingStrategyVersion::query()->create($attributes);
        $config = is_array($version->config_json) ? $version->config_json : [];
        $sources = is_array($config['eligibility_sources'] ?? null) ? $config['eligibility_sources'] : [];
        app(StrategyEligibilityService::class)->syncStrategyScreeners($version, $sources);

        return $version;
    }
}