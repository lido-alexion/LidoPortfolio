<?php

namespace Tests\Feature\V9;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComboChartPreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_combo_chart_default_is_account_wide_and_only_visible_to_its_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->defaultPortfolioFor($user);
        $this->defaultPortfolioFor($other);

        $this->actingAs($user)->withProfileHeader($user)
            ->getJson('/api/v1/stock-details/combo-chart-preference')
            ->assertOk()->assertJsonPath('data.default_preset_id', null);
        $this->actingAs($user)->withProfileHeader($user)
            ->putJson('/api/v1/stock-details/combo-chart-preference', ['default_preset_id' => 'price-revenue'])
            ->assertOk()->assertJsonPath('data.default_preset_id', 'price-revenue');
        $this->actingAs($user)->withProfileHeader($user)
            ->getJson('/api/v1/stock-details/combo-chart-preference')
            ->assertOk()->assertJsonPath('data.default_preset_id', 'price-revenue');
        $this->actingAs($other)->withProfileHeader($other)
            ->getJson('/api/v1/stock-details/combo-chart-preference')
            ->assertOk()->assertJsonPath('data.default_preset_id', null);
    }

    public function test_combo_chart_default_rejects_non_catalogue_presets(): void
    {
        $user = User::factory()->create();
        $this->defaultPortfolioFor($user);

        $this->actingAs($user)->withProfileHeader($user)
            ->putJson('/api/v1/stock-details/combo-chart-preference', ['default_preset_id' => 'custom-price-chart'])
            ->assertUnprocessable();
    }
}
