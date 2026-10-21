<?php

namespace Tests\Feature;

use App\Models\ArtifactBinding;
use App\Models\ArtifactBundleDeployment;
use App\Models\ArtifactLibraryAdoption;
use App\Models\ReusableArtifactVersion;
use App\Models\User;
use App\Services\Artifacts\ArtifactType;
use App\Services\Artifacts\ReusableArtifactLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArtifactActionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_binding_actions_are_portfolio_scoped_explicit_and_revisioned(): void
    {
        $owner = User::factory()->create();
        $this->defaultPortfolioFor($owner);
        $lifecycle = app(ReusableArtifactLifecycleService::class);
        $v1 = $this->publishedScreener($owner, 'quality');

        $bound = $this->actingAs($owner)->postJson('/api/v1/artifact-library/versions/'.$v1->id.'/bind', [
            'settings' => ['threshold' => 70],
            'enabled' => true,
        ])->assertCreated()
            ->assertJsonPath('data.active_version', '1.0.0')
            ->assertJsonPath('data.revision_number', 1);
        $bindingUuid = $bound->json('data.binding_uuid');
        $this->actingAs($owner)->postJson('/api/v1/artifact-library/versions/'.$v1->id.'/bind')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'ARTIFACT_BIND_FAILED');

        $this->actingAs($owner)->putJson('/api/v1/artifact-bindings/'.$bindingUuid.'/settings', [
            'expected_lock_version' => 0,
            'settings' => ['threshold' => 75],
        ])->assertOk()
            ->assertJsonPath('data.lock_version', 1)
            ->assertJsonPath('data.revision_number', 2);

        $v11 = $lifecycle->publish($lifecycle->createNextDraft($v1, $owner, '1.1.0'), $owner);
        $this->actingAs($owner)->putJson('/api/v1/artifact-bindings/'.$bindingUuid.'/upgrade', [
            'artifact_version_id' => $v11->id,
            'expected_lock_version' => 1,
        ])->assertOk()
            ->assertJsonPath('data.active_version', '1.1.0')
            ->assertJsonPath('data.settings.threshold', 75)
            ->assertJsonPath('data.revision_number', 3);

        $this->actingAs($owner)->putJson('/api/v1/artifact-bindings/'.$bindingUuid.'/enabled', [
            'expected_lock_version' => 2,
            'enabled' => false,
        ])->assertOk()
            ->assertJsonPath('data.status', ArtifactBinding::STATUS_DISABLED)
            ->assertJsonPath('data.revision_number', 4);
    }

    public function test_share_adopt_revoke_and_validated_package_round_trip_are_exposed(): void
    {
        $owner = User::factory()->create();
        $recipient = User::factory()->create();
        $this->defaultPortfolioFor($owner);
        $this->defaultPortfolioFor($recipient);
        $version = $this->publishedScreener($owner, 'portable');

        $shared = $this->actingAs($owner)->postJson('/api/v1/artifact-library/versions/'.$version->id.'/share', [
            'recipient_email' => $recipient->email,
        ])->assertCreated();
        $grantUuid = $shared->json('data.grant_uuid');
        $this->actingAs($recipient)->postJson('/api/v1/artifact-share-grants/'.$grantUuid.'/adopt')
            ->assertOk()
            ->assertJsonPath('data.adopted_versions', 1);
        $this->actingAs($owner)->postJson('/api/v1/artifact-share-grants/'.$grantUuid.'/revoke')
            ->assertOk()
            ->assertJsonPath('data.status', 'revoked');
        $this->assertSame(1, ArtifactLibraryAdoption::query()->where('user_id', $recipient->id)->count());

        $package = $this->actingAs($owner)
            ->postJson('/api/v1/artifact-library/versions/'.$version->id.'/export')
            ->assertOk()
            ->json('data');
        $this->actingAs($recipient)->postJson('/api/v1/artifact-library/import', ['package' => $package])
            ->assertCreated()
            ->assertJsonPath('meta.count', 1)
            ->assertJsonPath('data.0.status', ReusableArtifactVersion::STATUS_DRAFT);
    }

    public function test_bundle_plan_and_deploy_actions_bind_all_members_transactionally(): void
    {
        $owner = User::factory()->create();
        $profile = $this->defaultPortfolioFor($owner);
        $first = $this->publishedScreener($owner, 'first');
        $second = $this->publishedScreener($owner, 'second');
        $lifecycle = app(ReusableArtifactLifecycleService::class);
        $bundle = $lifecycle->publish(
            $lifecycle->createDraft($owner, ArtifactType::BUNDLE, 'starter', 'Starter', $this->bundleEnvelope()),
            $owner,
            [
                ['kind' => 'bundle_member', 'artifact_version_id' => $first->id],
                ['kind' => 'bundle_member', 'artifact_version_id' => $second->id],
            ],
        );

        $planned = $this->actingAs($owner)->postJson('/api/v1/artifact-library/versions/'.$bundle->id.'/bundle-plan', [
            'member_settings' => [(string) $first->id => ['schedule' => 'daily']],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'planned');

        $uuid = $planned->json('data.deployment_uuid');
        $pending = ArtifactBundleDeployment::query()->where('deployment_uuid', $uuid)->firstOrFail();
        $before = $pending->getAttributes();
        $beforeItems = $pending->items()->orderBy('id')->get()
            ->map(fn ($item) => $item->getAttributes())->all();
        $sibling = $this->createPortfolioProfile($owner, 'Sibling', false);
        $this->actingAs($owner)->withProfileHeader($owner, $sibling)
            ->getJson('/api/v1/artifact-library/bundle-deployments/'.$uuid)->assertNotFound();
        $this->actingAs($owner)->withProfileHeader($owner, $sibling)
            ->postJson('/api/v1/artifact-library/bundle-deployments/'.$uuid.'/deploy')
            ->assertNotFound();
        $this->assertSame($before, $pending->fresh()->getAttributes());
        $this->assertSame($beforeItems, $pending->items()->orderBy('id')->get()
            ->map(fn ($item) => $item->getAttributes())->all());
        $this->assertSame(0, ArtifactBinding::query()->whereIn('profile_id', [$profile->id, $sibling->id])->count());

        $this->withProfileHeader($owner, $profile);
        $this->actingAs($owner)->postJson('/api/v1/artifact-library/bundle-deployments/'.$planned->json('data.deployment_uuid').'/deploy')
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
        $this->assertSame(2, ArtifactBinding::query()->where('profile_id', $profile->id)->count());

        $uuid = $planned->json('data.deployment_uuid');
        $evidence = $this->actingAs($owner)->getJson('/api/v1/artifact-library/bundle-deployments/'.$uuid)
            ->assertOk()
            ->assertJsonPath('data.deployment_uuid', $uuid)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonMissingPath('data.plan')
            ->assertJsonMissingPath('data.items.0.settings');
        $this->assertIsInt($evidence->json('data.items.0.resulting_revision_id'));
        $this->assertSame(['artifact_uuid', 'version', 'action', 'resulting_revision_id'], array_keys($evidence->json('data.items.0')));

        $other = User::factory()->create();
        $this->defaultPortfolioFor($other);
        $this->actingAs($other)->withProfileHeader($other)
            ->getJson('/api/v1/artifact-library/bundle-deployments/'.$uuid)->assertNotFound();
    }

    private function publishedScreener(User $owner, string $slug): ReusableArtifactVersion
    {
        $lifecycle = app(ReusableArtifactLifecycleService::class);

        return $lifecycle->publish(
            $lifecycle->createDraft($owner, ArtifactType::SCREENER, $slug, ucfirst($slug), $this->screenerEnvelope($slug)),
            $owner,
        );
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
    private function bundleEnvelope(): array
    {
        return [
            'schema_version' => '1.0',
            'artifact_type' => 'bundle',
            'slug' => 'starter',
            'name' => 'Starter',
            'metadata' => ['scope' => 'account', 'status' => 'draft', 'origin' => 'user'],
            'definition' => ['deployment_defaults' => []],
        ];
    }
}
