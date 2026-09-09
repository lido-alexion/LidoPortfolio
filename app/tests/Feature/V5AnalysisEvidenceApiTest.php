<?php

namespace Tests\Feature;

use App\Models\AnalysisEvidence;
use App\Models\User;
use App\Services\CashManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class V5AnalysisEvidenceApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_server_computes_and_preserves_immutable_performance_evidence(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        app(CashManagementService::class)->deposit($profile, 1000, 'Opening', $user, '2025-12-31');

        $response = $this->actingAs($user)->withProfileHeader($user, $profile)
            ->postJson('/api/analysis/evidence', [
                'calculation_type' => 'portfolio_performance',
                'from' => '2026-01-01',
                'to' => '2026-01-03',
                'result' => ['xirr_percent' => 999999],
            ])->assertCreated()
            ->assertJsonPath('data.calculation_type', 'portfolio_performance')
            ->assertJsonPath('data.result.xirr_percent', 0);

        $id = $response->json('data.id');
        $this->assertNotEmpty(AnalysisEvidence::query()->findOrFail($id)->inputs_digest['sha256']);
        $this->getJson('/api/analysis/evidence/'.$id)->assertOk();
        $this->putJson('/api/analysis/evidence/'.$id, [])->assertMethodNotAllowed();
        $this->deleteJson('/api/analysis/evidence/'.$id)->assertMethodNotAllowed();
    }

    public function test_evidence_is_account_isolated(): void
    {
        $owner = User::factory()->create();
        $ownerProfile = $this->defaultPortfolioFor($owner);
        $created = $this->actingAs($owner)->withProfileHeader($owner, $ownerProfile)
            ->postJson('/api/analysis/evidence', [
                'calculation_type' => 'account_tax',
                'financial_year' => '2025-26',
            ])->assertCreated();

        $other = User::factory()->create();
        $otherProfile = $this->defaultPortfolioFor($other);
        $this->actingAs($other)->withProfileHeader($other, $otherProfile)
            ->getJson('/api/analysis/evidence/'.$created->json('data.id'))
            ->assertNotFound();
    }
}
