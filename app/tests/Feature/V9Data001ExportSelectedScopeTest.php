<?php

namespace Tests\Feature;

use App\Models\ExportArtifact;
use App\Models\PortfolioSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class V9Data001ExportSelectedScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_single_dataset_rejects_a_selected_identity_from_another_account(): void
    {
        Storage::fake('local');
        [$owner, $ownerProfile] = $this->ownerAndProfile();
        [, $otherProfile] = $this->ownerAndProfile();
        $foreignSnapshot = $this->snapshot($otherProfile->id);

        $this->actingAs($owner)->withProfileHeader($owner, $ownerProfile)->postJson('/api/exports', [
            'dataset' => 'portfolio-snapshots',
            'format' => 'csv',
            'scope' => 'selected',
            'selected' => [(string) $foreignSnapshot->id],
            'fields' => ['snapshot_date', 'portfolio_value'],
        ])->assertUnprocessable()->assertJsonPath('message', 'One or more selected rows are stale or unavailable. Refresh the selection and try again.');

        $this->assertDatabaseCount('portfolio_export_artifacts', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_basket_rejects_a_selected_identity_from_another_account_before_creating_an_artifact(): void
    {
        Storage::fake('local');
        [$owner, $ownerProfile] = $this->ownerAndProfile();
        [, $otherProfile] = $this->ownerAndProfile();
        $foreignSnapshot = $this->snapshot($otherProfile->id);
        $this->actingAs($owner)->putJson('/api/exports/basket', [
            'items' => [[
                'dataset' => 'portfolio-snapshots',
                'scope' => 'selected',
                'selected' => [(string) $foreignSnapshot->id],
                'fields' => ['snapshot_date', 'portfolio_value'],
            ]],
        ])->assertOk();

        $this->actingAs($owner)->withProfileHeader($owner, $ownerProfile)->postJson('/api/exports/basket/export')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'A selected-scope basket item is stale. Edit or remove it and try again.');

        $this->assertDatabaseCount('portfolio_export_artifacts', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    private function ownerAndProfile(): array
    {
        $user = User::factory()->create();
        return [$user, $this->defaultPortfolioFor($user)];
    }

    private function snapshot(int $profileId): PortfolioSnapshot
    {
        return PortfolioSnapshot::query()->create([
            'profile_id' => $profileId,
            'snapshot_date' => '2026-10-07',
            'portfolio_value' => '12345.6700',
            'invested_value' => '10000.0000',
        ]);
    }
}
