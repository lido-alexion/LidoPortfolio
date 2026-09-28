<?php

namespace Tests;

use App\Models\PortfolioProfile;
use App\Services\StrategyConfigurationService;
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
}
