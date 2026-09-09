<?php

namespace Tests\Feature;

use App\Models\ArtifactBinding;
use App\Models\Screener;
use App\Models\TradingStrategy;
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
        $projection = Screener::query()->where('profile_id', $profile->id)->where('reusable_artifact_id', $v1->artifact_id)->sole();
        $this->assertTrue($projection->is_enabled);

        $v11 = $lifecycle->publish($lifecycle->createNextDraft($v1, $owner, '1.1.0'), $owner);
        $this->assertSame($v1->id, $binding->fresh('activeRevision')->activeRevision->artifact_version_id);
        $this->assertSame(1, $binding->revisions()->count());

        $upgraded = $bindings->upgrade($binding->fresh('activeRevision.artifactVersion'), $v11, $owner, 0);
        $this->assertSame($v11->id, $upgraded->activeRevision->artifact_version_id);
        $this->assertSame('upgrade', $upgraded->activeRevision->action);
        $this->assertSame(2, $upgraded->activeRevision->revision_number);
        $this->assertSame(['schedule' => 'daily'], $upgraded->activeRevision->settings_json);
        $this->assertSame(ArtifactBinding::STATUS_ENABLED, $upgraded->status);
        $this->assertSame($v11->definition_hash, $projection->fresh()->definition_hash);
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
        $this->assertFalse(Screener::query()->where('reusable_artifact_id', $disabled->artifact_id)->sole()->is_enabled);

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

    public function test_strategy_binding_creates_and_upgrades_runnable_compatibility_projection(): void
    {
        $owner = User::factory()->create();
        $profile = $this->defaultPortfolioFor($owner);
        $lifecycle = app(ReusableArtifactLifecycleService::class);
        $bindings = app(ArtifactBindingService::class);
        $v1 = $lifecycle->publish(
            $lifecycle->createDraft($owner, ArtifactType::STRATEGY, 'bound-strategy', 'Bound Strategy', $this->strategyEnvelope(60)),
            $owner,
        );

        $binding = $bindings->bind($profile, $v1, $owner, ['allocation_pct' => 40], true);
        $projection = TradingStrategy::query()
            ->where('profile_id', $profile->id)
            ->where('reusable_artifact_id', $v1->artifact_id)
            ->with('activeVersion')
            ->sole();
        $this->assertSame(TradingStrategy::STATUS_ACTIVE, $projection->status);
        $this->assertSame(40.0, (float) $projection->allocation_pct);
        $this->assertSame(60, $projection->activeVersion->config_json['thresholds']['open_position']);

        $v11 = $lifecycle->publish(
            $lifecycle->createNextDraft($v1, $owner, '1.1.0', $this->strategyEnvelope(75)),
            $owner,
        );
        $upgraded = $bindings->upgrade($binding, $v11, $owner, 0);
        $projection = $projection->fresh('activeVersion');

        $this->assertSame($v11->definition_hash, $projection->definition_hash);
        $this->assertSame(75, $projection->activeVersion->config_json['thresholds']['open_position']);
        $this->assertSame($v11->id, $upgraded->activeRevision->artifact_version_id);
    }

    public function test_projection_failure_rolls_back_the_entire_binding(): void
    {
        $owner = User::factory()->create();
        $profile = $this->defaultPortfolioFor($owner);
        $envelope = $this->envelope('portfolio-specific-scope');
        $envelope['metadata']['universe'] = 'watchlist';
        $version = app(ReusableArtifactLifecycleService::class)->publish(
            app(ReusableArtifactLifecycleService::class)->createDraft(
                $owner,
                ArtifactType::SCREENER,
                'portfolio-specific-scope',
                'Portfolio-specific scope',
                $envelope,
            ),
            $owner,
        );

        try {
            app(ArtifactBindingService::class)->bind($profile, $version, $owner);
            $this->fail('Binding should fail when its runtime projection cannot be created safely.');
        } catch (InvalidArgumentException) {
            $this->assertSame(0, ArtifactBinding::query()->count());
            $this->assertSame(0, Screener::query()->count());
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

    /** @return array<string, mixed> */
    private function strategyEnvelope(int $threshold): array
    {
        return [
            'schema_version' => '1.0',
            'artifact_type' => 'strategy',
            'slug' => 'bound-strategy',
            'name' => 'Bound Strategy',
            'metadata' => ['scope' => 'account', 'status' => 'draft', 'origin' => 'user'],
            'definition' => [
                'scoring_model' => [[
                    'key' => 'momentum_score',
                    'enabled' => true,
                    'weight' => 100,
                    'parameters' => ['rsi_period' => 14],
                ]],
                'eligibility_sources' => [],
                'thresholds' => ['open_position' => $threshold],
            ],
        ];
    }
}
