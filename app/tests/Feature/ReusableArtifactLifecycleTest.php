<?php

namespace Tests\Feature;

use App\Models\ReusableArtifactVersion;
use App\Models\User;
use App\Services\Artifacts\ArtifactOrigin;
use App\Services\Artifacts\ArtifactSharingService;
use App\Services\Artifacts\ArtifactType;
use App\Services\Artifacts\ReusableArtifactLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class ReusableArtifactLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_publish_freezes_exact_version_and_dependencies(): void
    {
        $owner = User::factory()->create();
        $service = app(ReusableArtifactLifecycleService::class);
        $screener = $service->createDraft($owner, ArtifactType::SCREENER, 'quality', 'Quality', $this->screenerEnvelope('quality'));
        $publishedScreener = $service->publish($screener, $owner, [[
            'kind' => 'uses_indicator',
            'indicator_id' => 'rsi',
            'indicator_version' => '1.0.0',
        ]]);
        $strategy = $service->createDraft($owner, ArtifactType::STRATEGY, 'quality_strategy', 'Quality Strategy', $this->strategyEnvelope('quality_strategy'));
        $publishedStrategy = $service->publish($strategy, $owner, [[
            'kind' => 'uses_screener',
            'artifact_version_id' => $publishedScreener->id,
        ]]);

        $this->assertSame(ReusableArtifactVersion::STATUS_PUBLISHED, $publishedStrategy->status);
        $this->assertSame($publishedScreener->id, $publishedStrategy->dependencies->first()->target_artifact_version_id);
        $this->assertNull($publishedStrategy->draft_slot);

        $this->expectException(LogicException::class);
        $publishedStrategy->forceFill(['change_summary' => 'mutated'])->save();
    }

    public function test_one_draft_and_optimistic_concurrency_are_enforced(): void
    {
        $owner = User::factory()->create();
        $service = app(ReusableArtifactLifecycleService::class);
        $draft = $service->createDraft($owner, ArtifactType::SCREENER, 'one', 'One', $this->screenerEnvelope('one'));

        $updated = $service->updateDraft($draft, $owner, 0, $this->screenerEnvelope('one'), [], 'first save');
        $this->assertSame(1, $updated->lock_version);

        $this->expectException(RuntimeException::class);
        $service->updateDraft($updated, $owner, 0, $this->screenerEnvelope('one'));
    }

    public function test_next_draft_and_fork_preserve_history_without_identity_theft(): void
    {
        $owner = User::factory()->create();
        $recipient = User::factory()->create();
        $service = app(ReusableArtifactLifecycleService::class);
        $published = $service->publish(
            $service->createDraft($owner, ArtifactType::SCREENER, 'source', 'Source', $this->screenerEnvelope('source')),
            $owner,
        );
        $next = $service->createNextDraft($published, $owner, '1.1.0');
        $this->assertSame(ReusableArtifactVersion::STATUS_DRAFT, $next->status);
        $this->assertSame($published->artifact_id, $next->artifact_id);
        $service->publish($next, $owner);

        $grant = app(ArtifactSharingService::class)->share($published, $owner, $recipient);
        app(ArtifactSharingService::class)->adopt($grant, $recipient);
        $fork = $service->fork($published, $recipient, 'adopted', 'Adopted');
        $this->assertSame('1.0.0', $fork->semver);
        $this->assertNotSame($published->artifact_id, $fork->artifact_id);
        $this->assertSame(ArtifactOrigin::FORK, $fork->artifact->origin);
        $this->assertSame($published->artifact->artifact_uuid, $fork->artifact->provenance_json['source_artifact_uuid']);
    }

    public function test_publish_rejects_unpinned_or_unknown_dependencies(): void
    {
        $owner = User::factory()->create();
        $service = app(ReusableArtifactLifecycleService::class);
        $draft = $service->createDraft($owner, ArtifactType::SCREENER, 'bad_dep', 'Bad dep', $this->screenerEnvelope('bad_dep'));

        $this->expectException(InvalidArgumentException::class);
        $service->publish($draft, $owner, [['indicator_id' => 'rsi']]);
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
