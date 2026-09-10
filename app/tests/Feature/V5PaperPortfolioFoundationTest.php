<?php

namespace Tests\Feature;

use App\Models\PortfolioProfile;
use App\Models\PaperSimulationEvent;
use App\Models\User;
use App\Models\Stock;
use App\Services\CashManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class V5PaperPortfolioFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_paper_type_is_chosen_only_at_creation_and_starting_cash_is_simulated(): void
    {
        $user = User::factory()->create();
        $live = $this->defaultPortfolioFor($user);

        $response = $this->actingAs($user)->withProfileHeader($user, $live)
            ->postJson('/api/portfolios', [
                'name' => 'Paper Lab',
                'portfolio_type' => 'paper',
                'starting_cash' => 250000,
                'simulation_price_method' => 'next_open',
            ])->assertCreated()
            ->assertJsonPath('data.portfolio_type', 'paper')
            ->assertJsonPath('data.execution_mode', 'manual')
            ->assertJsonPath('data.simulation_state', 'active');

        $paper = PortfolioProfile::query()->findOrFail($response->json('data.id'));
        $this->assertSame(250000.0, app(CashManagementService::class)->balance($paper));
        $this->assertSame('paper_simulation', $paper->simulation_evidence['provenance']);

        $this->withProfileHeader($user, $paper)->putJson('/api/portfolios/'.$paper->id, [
            'name' => 'Still Paper', 'portfolio_type' => 'live',
        ])->assertUnprocessable();
        $this->assertTrue($paper->fresh()->isPaper());
    }

    public function test_paper_is_structurally_excluded_from_real_account_performance_and_tax(): void
    {
        $user = User::factory()->create();
        $live = $this->defaultPortfolioFor($user);
        $paper = PortfolioProfile::query()->create([
            'user_id' => $user->id, 'name' => 'Paper', 'portfolio_type' => 'paper',
            'simulation_state' => 'active', 'simulation_price_method' => 'next_open',
        ]);
        app(CashManagementService::class)->deposit($paper, 100000, 'Paper starting simulated cash', $user, '2025-12-31');

        $this->actingAs($user)->withProfileHeader($user, $live)
            ->getJson('/api/analysis/account-performance?from=2026-01-01&to=2026-01-03')
            ->assertOk()->assertJsonPath('data.portfolio_ids', [$live->id]);
        $this->getJson('/api/tax/report?financial_year=2025-26')
            ->assertOk()->assertJsonPath('data.portfolio_ids', [$live->id]);
        $this->getJson('/api/analysis/account-performance?from=2026-01-01&to=2026-01-03&portfolio_ids[]='.$paper->id)
            ->assertUnprocessable();
        $this->getJson('/api/tax/report?financial_year=2025-26&portfolio_ids[]='.$paper->id)
            ->assertUnprocessable();
    }

    public function test_paper_cannot_enable_real_broker_authority(): void
    {
        $user = User::factory()->create();
        $paper = $this->defaultPortfolioFor($user);
        $paper->forceFill([
            'portfolio_type' => 'paper', 'simulation_state' => 'active',
            'simulation_price_method' => 'next_open',
        ])->save();

        $this->actingAs($user)->withProfileHeader($user, $paper)
            ->putJson('/api/v1/execution/mode', ['execution_mode' => 'semi_automatic'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'PAPER_EXECUTION_MODE_IMMUTABLE');
        $this->getJson('/api/v1/execution/mode')->assertOk()
            ->assertJsonPath('data.execution_mode', 'manual')
            ->assertJsonPath('data.blockers.0', 'paper_portfolio');
    }

    public function test_investor_pause_is_audited_and_resume_skips_the_deliberately_paused_period(): void
    {
        $user = User::factory()->create();
        $paper = $this->defaultPortfolioFor($user);
        $paper->forceFill([
            'portfolio_type' => 'paper', 'simulation_state' => 'active',
            'simulation_price_method' => 'next_open',
        ])->save();

        $this->actingAs($user)->withProfileHeader($user, $paper)
            ->postJson('/api/portfolios/'.$paper->id.'/simulation/pause')
            ->assertOk()
            ->assertJsonPath('data.state', 'paused')
            ->assertJsonPath('data.manual_interventions_allowed', false)
            ->assertJsonPath('data.events.0.event_type', 'investor_paused');
        $this->postJson('/api/transactions', [])->assertUnprocessable()
            ->assertJsonPath('errors.portfolio.0', 'Paper interventions are blocked while simulation is paused or catching up.');

        $this->postJson('/api/portfolios/'.$paper->id.'/simulation/resume')
            ->assertOk()
            ->assertJsonPath('data.state', 'active')
            ->assertJsonPath('data.manual_interventions_allowed', true)
            ->assertJsonPath('data.checkpoint_date', now()->toDateString())
            ->assertJsonPath('data.events.0.event_type', 'investor_resumed')
            ->assertJsonPath('data.events.0.evidence.paused_sessions_skipped', true);

        $this->assertSame(2, PaperSimulationEvent::query()->where('profile_id', $paper->id)->count());
        $this->postJson('/api/portfolios/'.$paper->id.'/simulation/resume')->assertOk();
        $this->assertSame(2, PaperSimulationEvent::query()->where('profile_id', $paper->id)->count());
    }

    public function test_live_portfolio_cannot_use_paper_simulation_controls(): void
    {
        $user = User::factory()->create();
        $live = $this->defaultPortfolioFor($user);

        $this->actingAs($user)->withProfileHeader($user, $live)
            ->postJson('/api/portfolios/'.$live->id.'/simulation/pause')
            ->assertUnprocessable();
    }

    public function test_manual_paper_trade_carries_intervention_evidence(): void
    {
        $user = User::factory()->create();
        $paper = $this->defaultPortfolioFor($user);
        $paper->forceFill([
            'portfolio_type' => 'paper', 'simulation_state' => 'active',
            'simulation_price_method' => 'next_open',
        ])->save();
        app(CashManagementService::class)->deposit($paper, 1000, 'Paper starting cash', $user, '2026-01-01');
        $stock = Stock::query()->create(['symbol' => 'MANUALP', 'exchange' => 'NSE', 'name' => 'Manual Paper']);

        $this->actingAs($user)->withProfileHeader($user, $paper)
            ->postJson('/api/transactions', [
                'stock_id' => $stock->id, 'type' => 'buy', 'quantity' => 1,
                'price' => 100, 'fees' => 1, 'transaction_date' => '2026-01-02',
            ])->assertCreated()
            ->assertJsonPath('data.simulation_origin', 'investor_intervention')
            ->assertJsonPath('data.simulation_effective_session_date', '2026-01-02T00:00:00.000000Z')
            ->assertJsonPath('data.simulation_evidence.intervention', true)
            ->assertJsonPath('data.simulation_evidence.actor_user_id', $user->id);
    }
}
