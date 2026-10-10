<?php

namespace Tests\Feature\V8;

use App\Models\Screener;
use App\Models\ScreenerVersion;
use App\Models\User;
use App\Services\Artifacts\DefinitionHasher;
use App\Services\Screener\ScreenerVersionSnapshotRepairService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ScreenerVersionSnapshotRepairServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_restores_only_a_missing_historical_snapshot_from_an_exact_immutable_source(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $definition = ['root' => ['type' => 'group', 'op' => 'AND', 'children' => []]];
        $screener = Screener::query()->create([
            'profile_id' => $profile->id,
            'name' => 'Repair target',
            'slug' => 'repair_target',
            'artifact_version' => 3,
            'scope' => 'all_equities',
            'definition_json' => $definition,
            'is_enabled' => true,
        ]);
        $payload = ['definition' => $definition, 'scope' => 'all_equities', 'watchlist_id' => null, 'index_symbol' => null];
        $hash = DefinitionHasher::hash($payload);

        $v1 = ScreenerVersion::query()->create([
            'screener_id' => $screener->id,
            'version' => 1,
            'definition_json' => ['root' => ['type' => 'condition', 'id' => 'historical-v1']],
            'scope' => 'holdings',
            'definition_hash' => 'sha256:historical-v1',
            'change_notes' => 'Original version must remain intact',
        ]);
        $proof = ScreenerVersion::query()->create([
            'screener_id' => $screener->id,
            'version' => 3,
            'definition_json' => $definition,
            'scope' => 'all_equities',
            'definition_hash' => $hash,
            'change_notes' => 'Existing exact snapshot proof',
        ]);

        $service = app(ScreenerVersionSnapshotRepairService::class);
        $dryRun = $service->restoreFromVersion($screener, 2, 3, $hash, 'Approved recovery', true);
        $this->assertTrue($dryRun['dry_run']);
        $this->assertDatabaseMissing('portfolio_screener_versions', ['screener_id' => $screener->id, 'version' => 2]);

        $result = $service->restoreFromVersion($screener, 2, 3, $hash, 'Approved recovery');
        $this->assertTrue($result['created']);
        $this->assertSame(2, (int) $result['version']->version);
        $this->assertSame($hash, $result['version']->definition_hash);
        $this->assertSame(
            DefinitionHasher::canonicalize($definition),
            DefinitionHasher::canonicalize($result['version']->definition_json),
        );
        $this->assertSame($hash, $proof->fresh()->definition_hash);
        $this->assertSame('Original version must remain intact', $v1->fresh()->change_notes);

        $retry = $service->restoreFromVersion($screener, 2, 3, $hash, 'Approved recovery');
        $this->assertFalse($retry['created']);
        $this->assertSame(1, ScreenerVersion::query()->where('screener_id', $screener->id)->where('version', 2)->count());
    }

    public function test_restores_from_a_published_artifact_in_the_same_screener_lineage(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $definition = ['root' => ['type' => 'group', 'op' => 'AND', 'children' => []]];
        $artifact = \App\Models\ReusableArtifact::query()->create([
            'artifact_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'owner_user_id' => $user->id,
            'artifact_type' => \App\Services\Artifacts\ArtifactType::SCREENER,
            'slug' => 'artifact_proof',
            'name' => 'Artifact proof',
            'origin' => 'user',
        ]);
        $screener = Screener::query()->create([
            'profile_id' => $profile->id,
            'name' => 'Artifact proof',
            'slug' => 'artifact_proof',
            'artifact_version' => 2,
            'scope' => 'all_equities',
            'definition_json' => $definition,
            'reusable_artifact_id' => $artifact->id,
            'is_enabled' => true,
        ]);
        $payload = ['definition' => $definition, 'scope' => 'all_equities', 'watchlist_id' => null, 'index_symbol' => null];
        $hash = DefinitionHasher::hash($payload);
        \App\Models\ReusableArtifactVersion::query()->create([
            'artifact_id' => $artifact->id,
            'semver' => '1.0.0',
            'status' => 'published',
            'content_json' => [
                'artifact_type' => \App\Services\Artifacts\ArtifactType::SCREENER,
                'definition' => $definition,
                'metadata' => ['universe' => 'all_equities'],
            ],
            'documentation_json' => [],
            'definition_hash' => 'sha256:artifact-content',
            'change_summary' => 'Immutable proof',
            'lock_version' => 1,
            'created_by_user_id' => $user->id,
            'published_at' => now(),
        ]);
        $artifactVersion = \App\Models\ReusableArtifactVersion::query()->firstOrFail();

        $result = app(ScreenerVersionSnapshotRepairService::class)
            ->restoreFromArtifact($screener, 2, $artifactVersion->id, $hash, 'Approved recovery');
        $this->assertTrue($result['created']);
        $this->assertSame($hash, $result['version']->definition_hash);
    }

    public function test_refuses_a_mismatched_hash_without_creating_a_snapshot(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $definition = ['root' => ['type' => 'group', 'op' => 'AND', 'children' => []]];
        $screener = Screener::query()->create([
            'profile_id' => $profile->id,
            'name' => 'Repair target',
            'slug' => 'repair_target',
            'artifact_version' => 3,
            'scope' => 'all_equities',
            'definition_json' => $definition,
            'is_enabled' => true,
        ]);
        $payload = ['definition' => $definition, 'scope' => 'all_equities', 'watchlist_id' => null, 'index_symbol' => null];
        $hash = DefinitionHasher::hash($payload);
        ScreenerVersion::query()->create([
            'screener_id' => $screener->id,
            'version' => 3,
            'definition_json' => $definition,
            'scope' => 'all_equities',
            'definition_hash' => $hash,
        ]);

        try {
            app(ScreenerVersionSnapshotRepairService::class)
                ->restoreFromVersion($screener, 2, 3, 'sha256:wrong', 'Approved recovery');
            $this->fail('A mismatched semantic hash must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('does not match', $exception->getMessage());
        }

        $this->assertDatabaseMissing('portfolio_screener_versions', ['screener_id' => $screener->id, 'version' => 2]);
    }
}
