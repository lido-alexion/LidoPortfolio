<?php

namespace Tests\Feature;

use App\Models\ArtifactBinding;
use App\Models\User;
use App\Services\Artifacts\ArtifactBindingService;
use App\Services\Artifacts\ArtifactType;
use App\Services\Artifacts\ReusableArtifactLifecycleService;
use App\Services\Indicators\IndicatorRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class ArtifactBindingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_publication_does_not_change_existing_binding_until_explicit_upgrade(): void
    {
        $owner = User::factory()->create();
        $profile = $this->defaultPortfolioFor($owner);
        $lifecycle = app(ReusableArtifactLifecycleService::class);
        $bindings = app(ArtifactBindingService::class);

        $v1 = $lifecycle->publish(
            $lifecycle->createDraft($owner, ArtifactType::SCREENER, 'quality', 'Quality', $this->envelope('quality')),
            $owner,
            [['kind' => 'uses_indicator', 'indicator_id' => 'rsi', 'indicator_version' => '1.0.0']],
        );
        $binding = $bindings->bind($profile, $v1, $owner, ['schedule' => 'daily'], true);

        $v11 = $lifecycle->publish($lifecycle->createNextDraft($v1, $owner, '1.1.0'), $owner);
        $this->assertSame($v1->id, $binding->fresh('activeRevision')->activeRevision->artifact_version_id);
        $this->assertSame(1, $binding->revisions()->count());

        $upgraded = $bindings->upgrade($binding->fresh('activeRevision.artifactVersion'), $v11, $owner, 0);
        $this->assertSame($v11->id, $upgraded->activeRevision->artifact_version_id);
        $this->assertSame('upgrade', $upgraded->activeRevision->action);
        $this->assertSame(2, $upgraded->activeRevision->revision_number);
        $this->assertSame(['schedule' => 'daily'], $upgraded->activeRevision->settings_json);
        $this->assertSame(ArtifactBinding::STATUS_ENABLED, $upgraded->status);
    }

    public function test_settings_and_enablement_changes_create_immutable_revisions(): void
    {
        [$owner, $binding] = $this->boundArtifact();
        $bindings = app(ArtifactBindingService::class);

        $settings = $bindings->updateSettings($binding, $owner, 0, ['threshold' => 75]);
        $disabled = $bindings->setEnabled($settings, $owner, 1, false);

        $this->assertSame(3, $disabled->revisions()->count());
        $this->assertSame(ArtifactBinding::STATUS_DISABLED, $disabled->status);
        $this->assertSame('disable', $disabled->activeRevision->action);
        $this->assertSame(['threshold' => 75], $disabled->activeRevision->settings_json);

        $this->expectException(LogicException::class);
        $disabled->revisions()->firstOrFail()->forceFill(['change_summary' => 'rewrite'])->save();
    }

    public function test_stale_binding_revision_is_rejected(): void
    {
        [$owner, $binding] = $this->boundArtifact();
        $service = app(ArtifactBindingService::class);
        $updated = $service->updateSettings($binding, $owner, 0, ['threshold' => 70]);

        $this->expectException(RuntimeException::class);
        $service->updateSettings($updated, $owner, 0, ['threshold' => 80]);
    }

    public function test_other_account_cannot_bind_or_upgrade_library_artifact(): void
    {
        [$owner, $binding] = $this->boundArtifact();
        $other = User::factory()->create();
        $otherProfile = $this->defaultPortfolioFor($other);
        $version = $binding->activeRevision->artifactVersion;

        $this->expectException(InvalidArgumentException::class);
        app(ArtifactBindingService::class)->bind($otherProfile, $version, $other);
    }

    public function test_initial_binding_cannot_enable_blocked_version_and_persists_derived_state_when_disabled(): void
    {
        $owner = User::factory()->create();
        $profile = $this->defaultPortfolioFor($owner);
        $lifecycle = app(ReusableArtifactLifecycleService::class);
        $version = $lifecycle->publish(
            $lifecycle->createDraft($owner, ArtifactType::SCREENER, 'dependency', 'Dependency', $this->envelope('dependency')),
            $owner,
            [['kind' => 'uses_indicator', 'indicator_id' => 'rsi', 'indicator_version' => '1.0.0']],
        );
        $this->app->instance(IndicatorRegistry::class, new IndicatorRegistry);

        try {
            app(ArtifactBindingService::class)->bind($profile, $version, $owner, [], true);
            $this->fail('Blocked version should not be enabled.');
        } catch (InvalidArgumentException) {
            $binding = app(ArtifactBindingService::class)->bind($profile, $version, $owner, [], false);
            $this->assertSame(ArtifactBinding::BLOCKED, $binding->usability_state);
            $this->assertSame(ArtifactBinding::BLOCKED, $binding->activeRevision->usability_state);
        }
    }

    /** @return array{0:User,1:ArtifactBinding} */
    private function boundArtifact(): array
    {
        $owner = User::factory()->create();
        $profile = $this->defaultPortfolioFor($owner);
        $lifecycle = app(ReusableArtifactLifecycleService::class);
        $version = $lifecycle->publish(
            $lifecycle->createDraft($owner, ArtifactType::SCREENER, 'bound', 'Bound', $this->envelope('bound')),
            $owner,
        );
        $binding = app(ArtifactBindingService::class)->bind($profile, $version, $owner, [], true);

        return [$owner, $binding];
    }

    /** @return array<string, mixed> */
    private function envelope(string $slug): array
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
}
