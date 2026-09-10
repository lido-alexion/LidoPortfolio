<?php

namespace Tests\Feature;

use App\Models\AnalysisPreference;
use App\Models\User;
use App\Services\CashManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class V5AccountPerformanceApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_performance_aggregates_included_wealth_and_is_not_an_average(): void
    {
        $user = User::factory()->create();
        $first = $this->defaultPortfolioFor($user);
        $second = $user->portfolios()->create(['name' => 'Second']);
        $cash = app(CashManagementService::class);
        $cash->deposit($first, 1000, 'Opening first', $user, '2025-12-31');
        $cash->deposit($second, 3000, 'Opening second', $user, '2025-12-31');
        AnalysisPreference::query()->create([
            'user_id' => $user->id, 'scope_key' => 'portfolio:'.$second->id, 'profile_id' => $second->id,
            'include_in_account_performance' => false, 'include_in_account_tax' => true,
        ]);

        $this->actingAs($user)->withProfileHeader($user, $first)
            ->getJson('/api/analysis/account-performance?from=2026-01-01&to=2026-01-03')
            ->assertOk()
            ->assertJsonPath('data.calculation_mode', 'configured')
            ->assertJsonPath('data.portfolio_ids', [$first->id])
            ->assertJsonPath('data.xirr_percent', 0)
            ->assertJsonPath('data.evidence.aggregation', 'daily_account_wealth_and_flows_not_average_of_portfolio_returns');

        $this->getJson('/api/analysis/account-performance?from=2026-01-01&to=2026-01-03&portfolio_ids[]='.$second->id)
            ->assertOk()->assertJsonPath('data.calculation_mode', 'what_if')->assertJsonPath('data.portfolio_ids', [$second->id]);
        $this->assertFalse(AnalysisPreference::query()->where('profile_id', $second->id)->firstOrFail()->include_in_account_performance);
    }

    public function test_account_performance_rejects_cross_account_what_if(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $other = User::factory()->create();
        $otherProfile = $this->defaultPortfolioFor($other);

        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->getJson('/api/analysis/account-performance?from=2026-01-01&to=2026-01-03&portfolio_ids[]='.$otherProfile->id)
            ->assertUnprocessable();
    }
}
