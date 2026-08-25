<?php

namespace Tests\Feature\Strategy;

use App\Engines\Strategy\FactoryMomentumStrategy;
use App\Models\User;
use App\Services\Entry\StaggeredEntryCalculator;
use App\Services\ProfileSettingsService;
use App\Services\StrategyConfigurationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * §29 Strategy-page keys persist through existing PUT /api/v1/strategy.
 */
class StrategyHorizonFirstEntryConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_first_entry_pct_default_is_fifty(): void
    {
        $this->assertEqualsWithDelta(
            50.0,
            (float) FactoryMomentumStrategy::config()['portfolio_rules']['first_entry_pct'],
            0.0001
        );

        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        app(StrategyConfigurationService::class)->ensureActive($profile);

        $data = $this->actingAs($user)
            ->withProfileHeader($user, $profile)
            ->getJson('/api/v1/strategy')
            ->assertOk()
            ->json('data');

        $this->assertEqualsWithDelta(50.0, (float) $data['portfolio_rules']['first_entry_pct'], 0.0001);
    }

    public function test_horizon_and_first_entry_persist_on_strategy_update(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $this->actingAs($user)->withProfileHeader($user, $profile);

        $settings = app(ProfileSettingsService::class);
        $slBefore = $settings->get($profile, 'default_stoploss_percent');
        $trailBefore = $settings->get($profile, 'portfolio_trailing_percent');

        $active = $this->getJson('/api/v1/strategy')->assertOk()->json('data');
        $config = $active['config'];
        $config['portfolio_rules']['horizon_calendar_days'] = 45;
        $config['portfolio_rules']['first_entry_pct'] = 40;

        $this->putJson('/api/v1/strategy', [
            'name' => $active['name'],
            'description' => $active['description'] ?? '',
            'config' => $config,
        ])->assertOk();

        $saved = $this->getJson('/api/v1/strategy')->assertOk()->json('data');
        $this->assertSame(45, (int) $saved['portfolio_rules']['horizon_calendar_days']);
        $this->assertEqualsWithDelta(40.0, (float) $saved['portfolio_rules']['first_entry_pct'], 0.0001);
        $this->assertSame(45, (int) $saved['config']['portfolio_rules']['horizon_calendar_days']);
        $this->assertEqualsWithDelta(40.0, (float) $saved['config']['portfolio_rules']['first_entry_pct'], 0.0001);

        $this->assertSame($slBefore, $settings->get($profile, 'default_stoploss_percent'));
        $this->assertSame($trailBefore, $settings->get($profile, 'portfolio_trailing_percent'));
    }

    public function test_empty_horizon_clears_and_means_no_expiry_config(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $this->actingAs($user)->withProfileHeader($user, $profile);

        $active = $this->getJson('/api/v1/strategy')->assertOk()->json('data');
        $config = $active['config'];
        $config['portfolio_rules']['horizon_calendar_days'] = 30;
        $this->putJson('/api/v1/strategy', [
            'name' => $active['name'],
            'description' => $active['description'] ?? '',
            'config' => $config,
        ])->assertOk();

        $config['portfolio_rules']['horizon_calendar_days'] = null;
        $this->putJson('/api/v1/strategy', [
            'name' => $active['name'],
            'description' => $active['description'] ?? '',
            'config' => $config,
        ])->assertOk();

        $saved = $this->getJson('/api/v1/strategy')->assertOk()->json('data');
        $horizon = $saved['portfolio_rules']['horizon_calendar_days'] ?? null;
        $this->assertTrue($horizon === null || $horizon === '' || (int) $horizon <= 0);
    }

    public function test_cleared_first_entry_falls_back_to_engine_default_fifty(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $this->actingAs($user)->withProfileHeader($user, $profile);

        $active = $this->getJson('/api/v1/strategy')->assertOk()->json('data');
        $config = $active['config'];
        $config['portfolio_rules']['first_entry_pct'] = null;

        $this->putJson('/api/v1/strategy', [
            'name' => $active['name'],
            'description' => $active['description'] ?? '',
            'config' => $config,
        ])->assertOk();

        $saved = $this->getJson('/api/v1/strategy')->assertOk()->json('data');
        $raw = $saved['portfolio_rules']['first_entry_pct'] ?? null;
        $normalized = app(StaggeredEntryCalculator::class)->normalizeFirstEntryPct(
            is_numeric($raw) ? (float) $raw : null
        );
        $this->assertSame(50.0, $normalized);
    }
}
