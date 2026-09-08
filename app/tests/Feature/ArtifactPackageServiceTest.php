<?php

namespace Tests\Feature;

use App\Models\ReusableArtifact;
use App\Models\ReusableArtifactVersion;
use App\Models\User;
use App\Services\Artifacts\ArtifactOrigin;
use App\Services\Artifacts\ArtifactPackageService;
use App\Services\Artifacts\ArtifactType;
use App\Services\Artifacts\DefinitionHasher;
use App\Services\Artifacts\ReusableArtifactLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ArtifactPackageServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_is_exact_and_foreign_import_creates_new_draft_lineages(): void
    {
        $owner = User::factory()->create();
        $recipient = User::factory()->create();
        [$dependency, $root] = $this->publishedGraph($owner);
        $packages = app(ArtifactPackageService::class);

        $package = $packages->export($root, $owner);
        $this->assertSame(ArtifactPackageService::FORMAT, $package['package_format']);
        $this->assertCount(2, $package['artifacts']);
        $this->assertStringStartsWith('sha256:', $package['checksum']);
        $rootEntry = collect($package['artifacts'])->firstWhere('source_key', $root->artifact->artifact_uuid.'@1.0.0');
        $this->assertSame(
            $dependency->artifact->artifact_uuid.'@1.0.0',
            $rootEntry['dependencies'][0]['target_source_key'],
        );

        $drafts = $packages->import($package, $recipient);
        $this->assertCount(2, $drafts);
        foreach ($drafts as $draft) {
            $this->assertSame(ReusableArtifactVersion::STATUS_DRAFT, $draft->status);
            $this->assertSame('1.0.0', $draft->semver);
            $this->assertSame(ArtifactOrigin::IMPORTED, $draft->artifact->origin);
            $this->assertSame('foreign_import', $draft->artifact->provenance_json['kind']);
            $this->assertNotContains($draft->artifact->artifact_uuid, [
                $root->artifact->artifact_uuid,
                $dependency->artifact->artifact_uuid,
            ]);
        }
    }

    public function test_tampered_or_invalid_package_writes_nothing(): void
    {
        $owner = User::factory()->create();
        $recipient = User::factory()->create();
        [, $root] = $this->publishedGraph($owner);
        $packages = app(ArtifactPackageService::class);
        $package = $packages->export($root, $owner);
        $package['artifacts'][0]['content']['name'] = '';
        $package['artifacts'][0]['definition_hash'] = DefinitionHasher::hash($package['artifacts'][0]['content']);
        $payload = $package;
        unset($payload['checksum'], $payload['exported_at']);
        $package['checksum'] = DefinitionHasher::hash($payload);

        try {
            $packages->import($package, $recipient);
            $this->fail('Invalid package should be rejected.');
        } catch (InvalidArgumentException) {
            $this->assertSame(0, ReusableArtifact::query()->where('owner_user_id', $recipient->id)->count());
        }
    }

    public function test_checksum_tampering_is_rejected(): void
    {
        $owner = User::factory()->create();
        [, $root] = $this->publishedGraph($owner);
        $package = app(ArtifactPackageService::class)->export($root, $owner);
        $package['root_source_key'] = 'tampered';

        $this->expectException(InvalidArgumentException::class);
        app(ArtifactPackageService::class)->import($package, User::factory()->create());
    }

    /** @return array{0:ReusableArtifactVersion,1:ReusableArtifactVersion} */
    private function publishedGraph(User $owner): array
    {
        $lifecycle = app(ReusableArtifactLifecycleService::class);
        $dependency = $lifecycle->publish(
            $lifecycle->createDraft($owner, ArtifactType::SCREENER, 'dependency', 'Dependency', $this->screenerEnvelope('dependency')),
            $owner,
            [['kind' => 'uses_indicator', 'indicator_id' => 'rsi', 'indicator_version' => '1.0.0']],
        );
        $root = $lifecycle->publish(
            $lifecycle->createDraft($owner, ArtifactType::STRATEGY, 'root', 'Root', $this->strategyEnvelope('root')),
            $owner,
            [['kind' => 'uses_screener', 'artifact_version_id' => $dependency->id]],
        );

        return [$dependency, $root];
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
