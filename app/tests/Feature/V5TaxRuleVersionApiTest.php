<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class V5TaxRuleVersionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admin_can_append_non_overlapping_versioned_tax_rules(): void
    {
        $investor = User::factory()->create();
        $profile = $this->defaultPortfolioFor($investor);
        $payload = [
            'version' => 'india-equity-2026.1',
            'effective_from' => '2026-04-01',
            'source_reference' => 'Validated statutory source reference',
            'rules' => [
                'long_term_holding_days' => 365,
                'short_term_rate' => 0.20,
                'long_term_rate' => 0.125,
                'long_term_exemption' => 125000,
                'fee_classifications' => ['brokerage' => 'cost_of_acquisition_or_transfer'],
            ],
        ];
        $this->actingAs($investor)->withProfileHeader($investor, $profile)
            ->postJson('/api/admin/tax-rule-versions', $payload)->assertForbidden();

        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->postJson('/api/admin/tax-rule-versions', $payload)
            ->assertCreated()
            ->assertJsonPath('data.version', 'india-equity-2026.1')
            ->assertJsonPath('data.created_by', $admin->id);

        $payload['version'] = 'india-equity-overlap';
        $payload['effective_from'] = '2027-04-01';
        $this->postJson('/api/admin/tax-rule-versions', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('effective_from');
    }

    public function test_tax_rules_have_no_mutation_or_delete_routes(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->putJson('/api/admin/tax-rule-versions/1', [])->assertNotFound();
        $this->deleteJson('/api/admin/tax-rule-versions/1')->assertNotFound();
    }
}
