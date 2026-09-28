<?php

namespace Tests\Feature\V8;

use App\Models\TradingStrategy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StrategyReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_investor_strategy_reports_setup_required_until_eligibility_configured(): void
    {
        $user = User::factory()->create();
        $this->defaultPortfolioFor($user);
        $this->actingAs($user);

        $created = $this->postJson('/api/v1/strategies', [
            'name' => 'Needs setup',
        ])->assertCreated()->json('data');

        $this->assertTrue($created['setup_required']);
        $this->assertSame('setup_required', $created['readiness']['status']);
        $this->assertNotEmpty($created['readiness']['requirements']);
    }

    public function test_enable_fails_with_actionable_requirements_for_incomplete_strategy(): void
    {
        $user = User::factory()->create();
        $this->defaultPortfolioFor($user);
        $this->actingAs($user);

        $created = $this->postJson('/api/v1/strategies', ['name' => 'Incomplete'])->assertCreated()->json('data');
        $strategyId = (int) $created['id'];

        $this->postJson('/api/v1/strategy-registry/'.$strategyId.'/activate')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['readiness']);
    }

    public function test_factory_strategy_is_ready_and_can_enable_second_strategy_after_save(): void
    {
        $user = User::factory()->create();
        $this->defaultPortfolioFor($user);
        $this->actingAs($user);

        $factory = $this->getJson('/api/v1/strategy')->assertOk()->json('data');
        $this->assertFalse($factory['setup_required']);

        $draft = $this->postJson('/api/v1/strategies', ['name' => 'Second'])->assertCreated()->json('data');
        $this->putJson('/api/v1/strategy?strategy_id='.$draft['id'], [
            'config' => $factory['config'],
            'change_notes' => 'Clone factory config',
        ])->assertOk();

        $this->postJson('/api/v1/strategy-registry/'.$draft['id'].'/activate')->assertOk();
        $this->assertSame(
            TradingStrategy::STATUS_ACTIVE,
            TradingStrategy::query()->findOrFail($draft['id'])->status
        );
    }
}
