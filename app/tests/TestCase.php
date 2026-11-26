<?php

namespace Tests;

use App\Models\PortfolioProfile;
use App\Services\StrategyConfigurationService;
use App\Models\TradingStrategyVersion;
use App\Models\User;
use App\Services\StrategyEligibilityService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Concerns\CreatesPortfolioProfiles;

abstract class TestCase extends BaseTestCase
{
    use CreatesPortfolioProfiles;

    /**
     * Model an authenticated test request in the matching browser context.
     * Tests for context mismatch must override this header explicitly.
     */
    public function actingAs(Authenticatable $user, $driver = null): static
    {
        $context = (bool) ($user->is_admin ?? false) ? 'admin' : 'investor';
        // SelectAuthSessionContext clears cached guards when a request changes
        // contexts. Mark the test's intended context before actingAs installs
        // its in-memory principal so that setup is not mistaken for a stale
        // cross-context request.
        app()->instance('stox.auth_session_context', $context);
        parent::actingAs($user, $driver);

        return $this->withHeader('X-Stox-Auth-Context', $context);
    }

    /** Login a fixture through the real session endpoint in its stated role context. */
    protected function loginAs(User $user, string $password = 'password123')
    {
        $context = (bool) $user->is_admin ? 'admin' : 'investor';

        return $this->withHeader('X-Stox-Auth-Context', $context)->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => $password,
        ]);
    }

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
