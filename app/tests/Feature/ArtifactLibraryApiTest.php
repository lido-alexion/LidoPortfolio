<?php

namespace Tests\Feature;

use App\Models\ReusableArtifact;
use App\Models\ReusableArtifactVersion;
use App\Models\User;
use App\Services\Artifacts\ArtifactBindingService;
use App\Services\Artifacts\ArtifactOrigin;
use App\Services\Artifacts\ArtifactSharingService;
use App\Services\Artifacts\ArtifactType;
use App\Services\Artifacts\ReusableArtifactLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArtifactLibraryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_library_exposes_owned_shared_and_portfolio_binding_context_without_leaking_unshared_items(): void
    {
        $owner = User::factory()->create();
        $recipient = User::factory()->create();
        $stranger = User::factory()->create();
        $this->defaultPortfolioFor($owner);
        $recipientProfile = $this->defaultPortfolioFor($recipient);
        $this->defaultPortfolioFor($stranger);
        $shared = $this->published($owner, 'shared');
        $private = $this->published($owner, 'private');
        app(ArtifactSharingService::class)->share($shared, $owner, $recipient);
        app(ArtifactBindingService::class)->bind($recipientProfile, $shared, $recipient, ['schedule' => 'daily'], true);

        $this->actingAs($recipient)->getJson('/api/v1/artifact-library')
            ->assertOk()
            ->assertJsonPath('meta.count', 1)
            ->assertJsonPath('data.0.artifact_uuid', $shared->artifact->artifact_uuid)
            ->assertJsonPath('data.0.permission', 'shared')
            ->assertJsonPath('data.0.portfolio_binding.active_version', '1.0.0')
            ->assertJsonMissing(['artifact_uuid' => $private->artifact->artifact_uuid]);

        $this->actingAs($stranger)->getJson('/api/v1/artifact-library/'.$shared->artifact->artifact_uuid)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'ARTIFACT_NOT_FOUND');
    }

    public function test_detail_shows_version_documentation_exact_dependencies_and_structural_diff(): void
    {
        $owner = User::factory()->create();
        $this->defaultPortfolioFor($owner);
        $lifecycle = app(ReusableArtifactLifecycleService::class);
        $dependency = $this->published($owner, 'dependency');
        $v1 = $lifecycle->publish(
            $lifecycle->createDraft($owner, ArtifactType::SCREENER, 'quality', 'Quality', $this->envelope('quality', 50)),
            $owner,
            [['kind' => 'uses_screener', 'artifact_version_id' => $dependency->id]],
            'Initial release',
        );
        $v11 = $lifecycle->createNextDraft($v1, $owner, '1.1.0', $this->envelope('quality', 60));
        $v11 = $lifecycle->updateDraft($v11, $owner, 0, $v11->content_json, ['usage' => 'Daily quality scan'], 'Raise threshold');
        $lifecycle->publish($v11, $owner, [], 'Raise threshold');
        $uuid = $v1->artifact->artifact_uuid;

        $this->actingAs($owner)->getJson('/api/v1/artifact-library/'.$uuid)
            ->assertOk()
            ->assertJsonPath('data.permission', 'owner')
            ->assertJsonPath('data.versions.0.semver', '1.1.0')
            ->assertJsonPath('data.versions.0.documentation.usage', 'Daily quality scan')
            ->assertJsonPath('data.versions.1.dependencies.0.artifact_uuid', $dependency->artifact->artifact_uuid)
            ->assertJsonPath('data.versions.1.dependencies.0.artifact_version', '1.0.0');

        $this->actingAs($owner)->getJson('/api/v1/artifact-library/'.$uuid.'/diff?from=1.0.0&to=1.1.0')
            ->assertOk()
            ->assertJsonPath('data.changes.0.path', '/definition/root/right/value')
            ->assertJsonPath('data.changes.0.change', 'changed')
            ->assertJsonPath('data.changes.0.before', 50)
            ->assertJsonPath('data.changes.0.after', 60);
    }

    public function test_published_factory_artifacts_are_visible_as_system_library_items(): void
    {
        $factoryOwner = User::factory()->create();
        $investor = User::factory()->create();
        $this->defaultPortfolioFor($factoryOwner);
        $this->defaultPortfolioFor($investor);
        $lifecycle = app(ReusableArtifactLifecycleService::class);
        $draft = $lifecycle->createDraft(
            $factoryOwner,
            ArtifactType::SCREENER,
            'factory_quality',
            'Factory Quality',
            $this->envelope('factory_quality', 50),
            origin: ArtifactOrigin::FACTORY,
            provenance: ['permission' => 'system'],
        );
        $factory = $lifecycle->publish($draft, $factoryOwner);

        $this->actingAs($investor)->getJson('/api/v1/artifact-library')
            ->assertOk()
            ->assertJsonPath('meta.count', 1)
            ->assertJsonPath('data.0.artifact_uuid', $factory->artifact->artifact_uuid)
            ->assertJsonPath('data.0.permission', 'system');
    }

    public function test_account_local_factory_origin_is_not_mistaken_for_global_system_permission(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->defaultPortfolioFor($owner);
        $this->defaultPortfolioFor($other);
        $lifecycle = app(ReusableArtifactLifecycleService::class);
        $localFactory = $lifecycle->publish($lifecycle->createDraft(
            $owner,
            ArtifactType::SCREENER,
            'local_factory',
            'Local Factory',
            $this->envelope('local_factory', 50),
            origin: ArtifactOrigin::FACTORY,
            provenance: ['kind' => 'legacy_portfolio_factory'],
        ), $owner);

        $this->actingAs($other)->getJson('/api/v1/artifact-library/'.$localFactory->artifact->artifact_uuid)
            ->assertNotFound();
    }

    public function test_owner_can_drive_draft_publish_forward_version_and_archive_lifecycle_through_api(): void
    {
        $owner = User::factory()->create();
        $this->defaultPortfolioFor($owner);

        $created = $this->actingAs($owner)->postJson('/api/v1/artifact-library/drafts', [
            'type' => ArtifactType::SCREENER,
            'slug' => 'api_quality',
            'name' => 'API Quality',
            'content' => $this->envelope('api_quality', 50),
            'ai_assisted' => true,
        ])->assertCreated()
            ->assertJsonPath('data.origin', ArtifactOrigin::AI_ASSISTED);
        $draftId = $created->json('data.versions.0.id');

        $this->actingAs($owner)->putJson('/api/v1/artifact-library/versions/'.$draftId, [
            'expected_lock_version' => 0,
            'content' => $this->envelope('api_quality', 55),
            'documentation' => ['usage' => 'Daily'],
            'change_summary' => 'Tune threshold',
        ])->assertOk()
            ->assertJsonPath('data.versions.0.lock_version', 1)
            ->assertJsonPath('data.versions.0.documentation.usage', 'Daily');

        $this->actingAs($owner)->putJson('/api/v1/artifact-library/versions/'.$draftId, [
            'expected_lock_version' => 0,
            'content' => $this->envelope('api_quality', 60),
        ])->assertStatus(409)
            ->assertJsonPath('error.code', 'ARTIFACT_DRAFT_CONFLICT');

        $published = $this->actingAs($owner)->postJson('/api/v1/artifact-library/versions/'.$draftId.'/publish', [
            'dependencies' => [[
                'kind' => 'uses_indicator',
                'indicator_id' => 'rsi',
                'indicator_version' => '1.0.0',
            ]],
            'change_summary' => 'First release',
        ])->assertOk();
        $published->assertJsonPath('data.versions.0.status', ReusableArtifactVersion::STATUS_PUBLISHED);

        $this->actingAs($owner)->postJson('/api/v1/artifact-library/versions/'.$draftId.'/next-draft', [
            'semver' => '1.1.0',
        ])->assertCreated()
            ->assertJsonPath('data.draft_version', '1.1.0');

        $artifact = ReusableArtifact::query()->where('slug', 'api_quality')->sole();
        $this->actingAs($owner)->postJson('/api/v1/artifact-library/'.$artifact->artifact_uuid.'/archive')
            ->assertOk();
        $this->assertNotNull($artifact->fresh()->archived_at);
        $this->assertSame(2, $artifact->versions()->count());
    }

    public function test_non_owner_cannot_edit_another_accounts_draft_and_shared_version_can_be_forked(): void
    {
        $owner = User::factory()->create();
        $recipient = User::factory()->create();
        $this->defaultPortfolioFor($owner);
        $this->defaultPortfolioFor($recipient);
        $shared = $this->published($owner, 'fork_source');
        app(ArtifactSharingService::class)->share($shared, $owner, $recipient);

        $this->actingAs($recipient)->putJson('/api/v1/artifact-library/versions/'.$shared->id, [
            'expected_lock_version' => 0,
            'content' => $this->envelope('fork_source', 75),
        ])->assertNotFound();

        $this->actingAs($recipient)->postJson('/api/v1/artifact-library/versions/'.$shared->id.'/fork', [
            'slug' => 'my_fork',
            'name' => 'My Fork',
        ])->assertCreated()
            ->assertJsonPath('data.slug', 'my_fork')
            ->assertJsonPath('data.origin', ArtifactOrigin::FORK)
            ->assertJsonPath('data.provenance.source_artifact_uuid', $shared->artifact->artifact_uuid)
            ->assertJsonPath('data.versions.0.status', ReusableArtifactVersion::STATUS_DRAFT);
    }

    private function published(User $owner, string $slug)
    {
        $lifecycle = app(ReusableArtifactLifecycleService::class);

        return $lifecycle->publish(
            $lifecycle->createDraft($owner, ArtifactType::SCREENER, $slug, ucfirst($slug), $this->envelope($slug, 50)),
            $owner,
        );
    }

    /** @return array<string, mixed> */
    private function envelope(string $slug, int $threshold): array
    {
        return [
            'schema_version' => '1.0',
            'artifact_type' => 'screener',
            'slug' => $slug,
            'name' => ucfirst($slug),
            'metadata' => ['scope' => 'account', 'status' => 'draft', 'origin' => 'user'],
            'definition' => [
                'root' => [
                    'type' => 'condition',
                    'left' => ['indicator' => 'rsi', 'params' => ['period' => 14]],
                    'operator' => 'gte',
                    'right' => ['type' => 'constant', 'value' => $threshold],
                ],
            ],
        ];
    }
}
