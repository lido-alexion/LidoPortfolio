<?php

namespace Tests\Feature;

use App\Models\ArtifactBinding;
use App\Models\ArtifactBundleDeployment;
use App\Models\ReusableArtifactVersion;
use App\Models\User;
use App\Services\Artifacts\ArtifactBindingService;
use App\Services\Artifacts\ArtifactBundleDeploymentService;
use App\Services\Artifacts\ArtifactType;
use App\Services\Artifacts\ReusableArtifactLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ArtifactBundleDeploymentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_bundle_deploys_exact_members_atomically_without_binding_the_bundle(): void
    {
        $owner = User::factory()->create();
        $profile = $this->defaultPortfolioFor($owner);
        $first = $this->publishedScreener($owner, 'first');
        $second = $this->publishedScreener($owner, 'second');
        $bundle = $this->publishedBundle($owner, 'starter', [$first, $second]);
        $service = app(ArtifactBundleDeploymentService::class);

        $plan = $service->plan($bundle, $profile, $owner, [
            $first->id => ['schedule' => 'daily'],
        ]);
        $this->assertSame(['bind', 'bind'], $plan->items->pluck('action')->all());

        $deployed = $service->deploy($plan, $owner);

        $this->assertSame(ArtifactBundleDeployment::STATUS_COMPLETED, $deployed->status);
        $this->assertNotNull($deployed->started_at);
        $this->assertNotNull($deployed->completed_at);
        $this->assertSame(2, ArtifactBinding::query()->where('profile_id', $profile->id)->count());
        $this->assertFalse(ArtifactBinding::query()
            ->where('profile_id', $profile->id)
            ->where('artifact_id', $bundle->artifact_id)
            ->exists());
        $this->assertNotNull($deployed->items->first()->resulting_revision_id);
        $this->assertSame(
            ['schedule' => 'daily'],
            ArtifactBinding::query()
                ->where('profile_id', $profile->id)
                ->where('artifact_id', $first->artifact_id)
                ->firstOrFail()
                ->activeRevision
                ->settings_json,
        );
    }

    public function test_bundle_upgrade_preserves_settings_and_does_not_follow_later_publications(): void
    {
        $owner = User::factory()->create();
        $profile = $this->defaultPortfolioFor($owner);
        $lifecycle = app(ReusableArtifactLifecycleService::class);
        $v1 = $this->publishedScreener($owner, 'quality');
        $binding = app(ArtifactBindingService::class)->bind($profile, $v1, $owner, ['threshold' => 70], true);
        $v11 = $lifecycle->publish($lifecycle->createNextDraft($v1, $owner, '1.1.0'), $owner);
        $bundle = $this->publishedBundle($owner, 'upgrade', [$v11]);
        $service = app(ArtifactBundleDeploymentService::class);

        $plan = $service->plan($bundle, $profile, $owner);
        $this->assertSame('upgrade', $plan->items->first()->action);
        $service->deploy($plan, $owner);

        $binding->refresh()->load('activeRevision');
        $this->assertSame($v11->id, $binding->activeRevision->artifact_version_id);
        $this->assertSame(['threshold' => 70], $binding->activeRevision->settings_json);

        $v12 = $lifecycle->publish($lifecycle->createNextDraft($v11, $owner, '1.2.0'), $owner);
        $this->assertSame($v11->id, $binding->fresh('activeRevision')->activeRevision->artifact_version_id);
        $this->assertNotSame($v12->id, $binding->fresh('activeRevision')->activeRevision->artifact_version_id);
    }

    public function test_stale_bundle_plan_rolls_back_every_member_and_keeps_failure_evidence(): void
    {
        $owner = User::factory()->create();
        $profile = $this->defaultPortfolioFor($owner);
        $first = $this->publishedScreener($owner, 'first');
        $second = $this->publishedScreener($owner, 'second');
        $bundle = $this->publishedBundle($owner, 'stale', [$first, $second]);
        $service = app(ArtifactBundleDeploymentService::class);
        $plan = $service->plan($bundle, $profile, $owner);

        app(ArtifactBindingService::class)->bind($profile, $second, $owner);

        try {
            $service->deploy($plan, $owner);
            $this->fail('A stale Bundle plan should fail.');
        } catch (InvalidArgumentException) {
            $this->assertSame(
                ArtifactBundleDeployment::STATUS_FAILED,
                $plan->fresh()->status,
            );
            $this->assertSame('bundle_deployment_failed', $plan->fresh()->error_code);
            $this->assertNotNull($plan->fresh()->started_at);
            $this->assertFalse(ArtifactBinding::query()
                ->where('profile_id', $profile->id)
                ->where('artifact_id', $first->artifact_id)
                ->exists());
            $this->assertTrue(ArtifactBinding::query()
                ->where('profile_id', $profile->id)
                ->where('artifact_id', $second->artifact_id)
                ->exists());
        }
    }

    public function test_nested_bundles_are_rejected_at_publication(): void
    {
        $owner = User::factory()->create();
        $inner = $this->publishedBundle($owner, 'inner', [$this->publishedScreener($owner, 'member')]);
        $lifecycle = app(ReusableArtifactLifecycleService::class);
        $outer = $lifecycle->createDraft($owner, ArtifactType::BUNDLE, 'outer', 'Outer', $this->bundleEnvelope('outer'));

        $this->expectException(InvalidArgumentException::class);
        $lifecycle->publish($outer, $owner, [[
            'kind' => 'bundle_member',
            'artifact_version_id' => $inner->id,
        ]]);
    }

    private function publishedScreener(User $owner, string $slug): ReusableArtifactVersion
    {
        $lifecycle = app(ReusableArtifactLifecycleService::class);

        return $lifecycle->publish(
            $lifecycle->createDraft($owner, ArtifactType::SCREENER, $slug, ucfirst($slug), $this->screenerEnvelope($slug)),
            $owner,
        );
    }

    /** @param list<ReusableArtifactVersion> $members */
    private function publishedBundle(User $owner, string $slug, array $members): ReusableArtifactVersion
    {
        $lifecycle = app(ReusableArtifactLifecycleService::class);
        $draft = $lifecycle->createDraft($owner, ArtifactType::BUNDLE, $slug, ucfirst($slug), $this->bundleEnvelope($slug));

        return $lifecycle->publish($draft, $owner, array_map(
            fn (ReusableArtifactVersion $member): array => [
                'kind' => 'bundle_member',
                'artifact_version_id' => $member->id,
            ],
            $members,
        ));
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
    private function bundleEnvelope(string $slug): array
    {
        return [
            'schema_version' => '1.0',
            'artifact_type' => 'bundle',
            'slug' => $slug,
            'name' => ucfirst($slug),
            'metadata' => ['scope' => 'account', 'status' => 'draft', 'origin' => 'user'],
            'definition' => ['deployment_defaults' => []],
        ];
    }
}
