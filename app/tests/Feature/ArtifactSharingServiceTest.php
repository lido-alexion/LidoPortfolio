<?php

namespace Tests\Feature;

use App\Models\ArtifactShareGrant;
use App\Models\User;
use App\Services\Artifacts\ArtifactBindingService;
use App\Services\Artifacts\ArtifactLibraryAccessService;
use App\Services\Artifacts\ArtifactSharingService;
use App\Services\Artifacts\ArtifactType;
use App\Services\Artifacts\ReusableArtifactLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ArtifactSharingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_share_includes_exact_dependency_access_and_adoption_survives_revocation(): void
    {
        $owner = User::factory()->create();
        $recipient = User::factory()->create();
        $lifecycle = app(ReusableArtifactLifecycleService::class);
        $sharing = app(ArtifactSharingService::class);
        $access = app(ArtifactLibraryAccessService::class);

        $dependency = $lifecycle->publish(
            $lifecycle->createDraft($owner, ArtifactType::SCREENER, 'dependency', 'Dependency', $this->screenerEnvelope('dependency')),
            $owner,
        );
        $root = $lifecycle->publish(
            $lifecycle->createDraft($owner, ArtifactType::STRATEGY, 'root', 'Root', $this->strategyEnvelope('root')),
            $owner,
            [['kind' => 'uses_screener', 'artifact_version_id' => $dependency->id]],
        );

        $grant = $sharing->share($root, $owner, $recipient);
        $this->assertSame(ArtifactShareGrant::STATUS_ACTIVE, $grant->status);
        $this->assertSame([$dependency->id], $grant->dependency_version_ids_json);
        $this->assertTrue($access->canAccess($recipient, $root));
        $this->assertTrue($access->canAccess($recipient, $dependency));

        $adoptions = $sharing->adopt($grant, $recipient);
        $this->assertCount(2, $adoptions);
        $sharing->revoke($grant, $owner);
        $this->assertTrue($access->canAccess($recipient, $root));
        $this->assertTrue($access->canAccess($recipient, $dependency));

        $profile = $this->defaultPortfolioFor($recipient);
        $binding = app(ArtifactBindingService::class)->bind($profile, $root, $recipient, [], true);
        $this->assertSame($root->id, $binding->activeRevision->artifact_version_id);
        $fork = $lifecycle->fork($root, $recipient, 'forked_root', 'Forked Root');
        $this->assertSame($root->artifact->artifact_uuid, $fork->artifact->provenance_json['source_artifact_uuid']);
    }

    public function test_revoked_unadopted_share_removes_future_access(): void
    {
        $owner = User::factory()->create();
        $recipient = User::factory()->create();
        $lifecycle = app(ReusableArtifactLifecycleService::class);
        $version = $lifecycle->publish(
            $lifecycle->createDraft($owner, ArtifactType::SCREENER, 'temporary', 'Temporary', $this->screenerEnvelope('temporary')),
            $owner,
        );
        $sharing = app(ArtifactSharingService::class);
        $sharing->revoke($sharing->share($version, $owner, $recipient), $owner);

        $this->assertFalse(app(ArtifactLibraryAccessService::class)->canAccess($recipient, $version));
        $this->expectException(InvalidArgumentException::class);
        $lifecycle->fork($version, $recipient, 'denied', 'Denied');
    }

    public function test_shared_exact_dependency_can_be_pinned_by_recipient_artifact(): void
    {
        $owner = User::factory()->create();
        $recipient = User::factory()->create();
        $lifecycle = app(ReusableArtifactLifecycleService::class);
        $dependency = $lifecycle->publish(
            $lifecycle->createDraft($owner, ArtifactType::SCREENER, 'shared_dep', 'Shared Dep', $this->screenerEnvelope('shared_dep')),
            $owner,
        );
        app(ArtifactSharingService::class)->share($dependency, $owner, $recipient);
        $draft = $lifecycle->createDraft(
            $recipient,
            ArtifactType::STRATEGY,
            'recipient_strategy',
            'Recipient Strategy',
            $this->strategyEnvelope('recipient_strategy'),
        );

        $published = $lifecycle->publish($draft, $recipient, [[
            'kind' => 'uses_screener',
            'artifact_version_id' => $dependency->id,
        ]]);

        $this->assertSame($dependency->id, $published->dependencies->sole()->target_artifact_version_id);
    }

    /** @return array<string, mixed> */
    private function screenerEnvelope(string $slug): array
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
                    'right' => ['type' => 'constant', 'value' => 50],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function strategyEnvelope(string $slug): array
    {
        return [
            'schema_version' => '1.0',
            'artifact_type' => 'strategy',
            'slug' => $slug,
            'name' => ucfirst($slug),
            'metadata' => ['scope' => 'account', 'status' => 'draft', 'origin' => 'user'],
            'definition' => [
                'scoring_model' => [[
                    'key' => 'momentum_score',
                    'enabled' => true,
                    'weight' => 100,
                    'parameters' => ['rsi_period' => 14],
                ]],
                'eligibility_sources' => [],
            ],
        ];
    }
}
