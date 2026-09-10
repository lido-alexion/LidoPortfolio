<?php

namespace Tests\Feature;

use App\Models\Benchmark;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class V5AnalysisPreferenceApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_benchmark_and_independent_inclusion_preferences_are_portfolio_scoped(): void
    {
        $user = User::factory()->create();
        $first = $this->defaultPortfolioFor($user);
        $second = $user->portfolios()->create(['name' => 'Second']);
        $default = Benchmark::query()->where('stable_key', 'nifty-50-tri')->firstOrFail();

        $this->actingAs($user)->withProfileHeader($user, $first)
            ->getJson('/api/analysis/preferences')
            ->assertOk()
            ->assertJsonPath('data.primary_benchmark_id', $default->id)
            ->assertJsonPath('data.include_in_account_performance', true)
            ->assertJsonPath('data.include_in_account_tax', true);

        $this->actingAs($user)->withProfileHeader($user, $first)
            ->putJson('/api/analysis/preferences', [
                'primary_benchmark_id' => $default->id,
                'include_in_account_performance' => false,
                'include_in_account_tax' => true,
                'risk_free_rate' => 0.06,
                'annualization_days' => 252,
            ])
            ->assertOk()
            ->assertJsonPath('data.include_in_account_performance', false)
            ->assertJsonPath('data.include_in_account_tax', true);

        $this->actingAs($user)->withProfileHeader($user, $second)
            ->getJson('/api/analysis/preferences')
            ->assertOk()
            ->assertJsonPath('data.include_in_account_performance', true);
    }

    public function test_inactive_or_unknown_benchmark_is_rejected(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);

        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->putJson('/api/analysis/preferences', ['primary_benchmark_id' => 999999])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('primary_benchmark_id');
    }

    public function test_account_risk_override_is_independent_from_portfolio_inclusion(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);

        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->putJson('/api/analysis/preferences', [
                'scope' => 'account', 'risk_free_rate' => 0.05, 'annualization_days' => 250,
                'include_in_account_performance' => false,
            ])->assertOk()
            ->assertJsonPath('data.scope', 'account')
            ->assertJsonPath('data.risk_free_rate', 0.05)
            ->assertJsonPath('data.annualization_days', 250)
            ->assertJsonPath('data.include_in_account_performance', null);

        $this->getJson('/api/analysis/preferences')
            ->assertOk()->assertJsonPath('data.include_in_account_performance', true);
    }
}
