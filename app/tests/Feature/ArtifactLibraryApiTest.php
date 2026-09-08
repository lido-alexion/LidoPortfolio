<?php

namespace Tests\Feature;

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
        );
        $factory = $lifecycle->publish($draft, $factoryOwner);

        $this->actingAs($investor)->getJson('/api/v1/artifact-library')
            ->assertOk()
            ->assertJsonPath('meta.count', 1)
            ->assertJsonPath('data.0.artifact_uuid', $factory->artifact->artifact_uuid)
            ->assertJsonPath('data.0.permission', 'system');
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
