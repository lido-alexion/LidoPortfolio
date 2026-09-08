<?php

namespace Tests\Feature;

use App\Models\TradingStrategy;
use App\Models\TradingStrategyVersion;
use App\Models\User;
use App\Services\Artifacts\ArtifactBindingService;
use App\Services\Artifacts\ArtifactRuntimeBindingResolver;
use App\Services\Artifacts\ArtifactType;
use App\Services\Artifacts\ReusableArtifactLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArtifactRuntimeBindingResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_runtime_selection_uses_only_enabled_usable_exact_binding_revision(): void
    {
        $owner = User::factory()->create();
        $profile = $this->defaultPortfolioFor($owner);
        [$strategy, $legacyVersion] = $this->legacyStrategy($profile->id);
        $lifecycle = app(ReusableArtifactLifecycleService::class);
        $v1 = $lifecycle->publish(
            $lifecycle->createDraft($owner, ArtifactType::STRATEGY, 'runtime', 'Runtime', $this->envelope(60)),
            $owner,
        );
        $binding = app(ArtifactBindingService::class)->bind($profile, $v1, $owner, [], true);
        $strategy->forceFill(['reusable_artifact_id' => $v1->artifact_id])->save();
        $resolver = app(ArtifactRuntimeBindingResolver::class);

        $selected = $resolver->forStrategyVersion($profile, $legacyVersion);
        $this->assertSame($v1->id, $selected->artifactVersion->id);
        $this->assertSame($binding->active_revision_id, $selected->bindingRevision->id);
        $this->assertSame(60, $selected->definition['recommendation_rules']['buy_threshold']);

        $v11 = $lifecycle->publish(
            $lifecycle->createNextDraft($v1, $owner, '1.1.0', $this->envelope(70)),
            $owner,
        );
        $this->assertSame($v1->id, $resolver->forStrategyVersion($profile, $legacyVersion)->artifactVersion->id);

        $binding = app(ArtifactBindingService::class)->upgrade($binding, $v11, $owner, 0);
        $this->assertSame($v11->id, $resolver->forStrategyVersion($profile, $legacyVersion)->artifactVersion->id);
        $this->assertSame($binding->active_revision_id, $resolver->forStrategyVersion($profile, $legacyVersion)->bindingRevision->id);
    }

    public function test_runtime_selection_fails_closed_for_disabled_blocked_unmapped_or_cross_portfolio_bindings(): void
    {
        $owner = User::factory()->create();
        $profile = $this->defaultPortfolioFor($owner);
        [$strategy, $legacyVersion] = $this->legacyStrategy($profile->id);
        $resolver = app(ArtifactRuntimeBindingResolver::class);
        $this->assertNull($resolver->forStrategyVersion($profile, $legacyVersion));

        $lifecycle = app(ReusableArtifactLifecycleService::class);
        $published = $lifecycle->publish(
            $lifecycle->createDraft($owner, ArtifactType::STRATEGY, 'disabled', 'Disabled', $this->envelope(60)),
            $owner,
        );
        $binding = app(ArtifactBindingService::class)->bind($profile, $published, $owner, [], false);
        $strategy->forceFill(['reusable_artifact_id' => $published->artifact_id])->save();
        $this->assertNull($resolver->forStrategyVersion($profile, $legacyVersion));

        $binding->forceFill(['status' => 'enabled', 'usability_state' => 'blocked'])->save();
        $this->assertNull($resolver->forStrategyVersion($profile, $legacyVersion));

        $otherProfile = $this->defaultPortfolioFor(User::factory()->create());
        $this->assertNull($resolver->forStrategyVersion($otherProfile, $legacyVersion));
    }

    /** @return array{0:TradingStrategy,1:TradingStrategyVersion} */
    private function legacyStrategy(int $profileId): array
    {
        $strategy = TradingStrategy::query()->create([
            'profile_id' => $profileId,
            'name' => 'Legacy',
            'slug' => 'legacy',
            'status' => TradingStrategy::STATUS_ACTIVE,
            'allocation_pct' => 100,
        ]);
        $version = TradingStrategyVersion::query()->create([
            'strategy_id' => $strategy->id,
            'version' => 1,
            'config_json' => ['recommendation_rules' => ['buy_threshold' => 55]],
            'status' => TradingStrategyVersion::STATUS_ACTIVE,
            'activated_at' => now(),
        ]);
        $strategy->forceFill(['active_version_id' => $version->id])->save();

        return [$strategy->fresh(), $version->fresh('strategy')];
    }

    /** @return array<string, mixed> */
    private function envelope(int $threshold): array
    {
        return [
            'schema_version' => '1.0',
            'artifact_type' => 'strategy',
            'slug' => 'runtime',
            'name' => 'Runtime',
            'metadata' => ['scope' => 'account', 'status' => 'draft', 'origin' => 'user'],
            'definition' => [
                'scoring_model' => [[
                    'key' => 'momentum_score',
                    'enabled' => true,
                    'weight' => 100,
                    'parameters' => ['rsi_period' => 14],
                ]],
                'eligibility_sources' => [],
                'recommendation_rules' => ['buy_threshold' => $threshold],
            ],
        ];
    }
}
