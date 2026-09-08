<?php

namespace App\Services\Artifacts;

use App\Models\ReusableArtifact;
use App\Models\ReusableArtifactDependency;
use App\Models\ReusableArtifactVersion;
use App\Models\User;
use App\Services\Indicators\IndicatorRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class ReusableArtifactLifecycleService
{
    public function __construct(
        private ArtifactValidationService $validator,
        private IndicatorRegistry $indicators,
        private ArtifactLibraryAccessService $libraryAccess,
    ) {}

    /** @param array<string, mixed> $content @param array<string, mixed> $provenance */
    public function createDraft(
        User $owner,
        string $type,
        string $slug,
        string $name,
        array $content,
        string $semver = '1.0.0',
        string $origin = ArtifactOrigin::USER,
        array $provenance = [],
    ): ReusableArtifactVersion {
        $this->assertTypeAndVersion($type, $semver);
        $slug = trim($slug);
        if ($slug === '' || $name === '') {
            throw new InvalidArgumentException('Artifact slug and name are required.');
        }

        return DB::transaction(function () use ($owner, $type, $slug, $name, $content, $semver, $origin, $provenance) {
            $artifact = ReusableArtifact::query()->create([
                'artifact_uuid' => (string) Str::uuid(),
                'owner_user_id' => $owner->id,
                'artifact_type' => $type,
                'slug' => $slug,
                'name' => $name,
                'origin' => $origin,
                'provenance_json' => $provenance,
            ]);

            return $this->storeDraft($artifact, $owner, $semver, $content, [], null);
        });
    }

    /** @param array<string, mixed> $content @param array<string, mixed> $documentation */
    public function updateDraft(
        ReusableArtifactVersion $draft,
        User $actor,
        int $expectedLockVersion,
        array $content,
        array $documentation = [],
        ?string $changeSummary = null,
    ): ReusableArtifactVersion {
        $this->assertOwner($draft->artifact, $actor);
        if ($draft->status !== ReusableArtifactVersion::STATUS_DRAFT) {
            throw new InvalidArgumentException('Only a Draft artifact version can be edited.');
        }

        $updated = ReusableArtifactVersion::query()
            ->whereKey($draft->id)
            ->where('status', ReusableArtifactVersion::STATUS_DRAFT)
            ->where('lock_version', $expectedLockVersion)
            ->update([
                'content_json' => $content,
                'documentation_json' => $documentation,
                'definition_hash' => DefinitionHasher::hash($content),
                'change_summary' => $changeSummary,
                'lock_version' => $expectedLockVersion + 1,
                'updated_at' => now(),
            ]);
        if ($updated !== 1) {
            throw new RuntimeException('Artifact Draft changed since it was loaded. Refresh before saving.');
        }

        return $draft->fresh();
    }

    /**
     * @param  list<array<string, mixed>>  $dependencies
     */
    public function publish(
        ReusableArtifactVersion $draft,
        User $actor,
        array $dependencies = [],
        ?string $changeSummary = null,
    ): ReusableArtifactVersion {
        return DB::transaction(function () use ($draft, $actor, $dependencies, $changeSummary) {
            $draft = ReusableArtifactVersion::query()->lockForUpdate()->findOrFail($draft->id);
            $this->assertOwner($draft->artifact, $actor);
            if ($draft->status !== ReusableArtifactVersion::STATUS_DRAFT) {
                throw new InvalidArgumentException('Only a Draft artifact version can be published.');
            }
            $this->assertIntrinsicValidity($draft->artifact, $draft->content_json);
            $normalized = $this->normalizeDependencies($draft, $dependencies);
            $this->assertDag($draft->id, array_filter(array_column($normalized, 'target_artifact_version_id')));

            $draft->forceFill([
                'status' => ReusableArtifactVersion::STATUS_PUBLISHED,
                'draft_slot' => null,
                'change_summary' => $changeSummary ?? $draft->change_summary,
                'published_at' => now(),
                'lock_version' => $draft->lock_version + 1,
            ])->save();
            foreach ($normalized as $dependency) {
                $draft->dependencies()->create($dependency);
            }

            return $draft->fresh(['dependencies']);
        });
    }

    /** @param array<string, mixed> $content */
    public function createNextDraft(
        ReusableArtifactVersion $published,
        User $actor,
        string $semver,
        ?array $content = null,
    ): ReusableArtifactVersion {
        $this->assertOwner($published->artifact, $actor);
        if ($published->status !== ReusableArtifactVersion::STATUS_PUBLISHED) {
            throw new InvalidArgumentException('A new version must start from a published version.');
        }
        $this->assertTypeAndVersion($published->artifact->artifact_type, $semver);

        return $this->storeDraft(
            $published->artifact,
            $actor,
            $semver,
            $content ?? $published->content_json,
            $published->documentation_json ?? [],
            'Drafted from '.$published->semver,
        );
    }

    public function fork(ReusableArtifactVersion $source, User $recipient, string $slug, string $name): ReusableArtifactVersion
    {
        if ($source->status !== ReusableArtifactVersion::STATUS_PUBLISHED) {
            throw new InvalidArgumentException('Only an immutable published version can be forked.');
        }
        if (! $this->libraryAccess->canAccess($recipient, $source)) {
            throw new InvalidArgumentException('Artifact version is not available in the recipient Library.');
        }

        return $this->createDraft(
            $recipient,
            $source->artifact->artifact_type,
            $slug,
            $name,
            $source->content_json,
            '1.0.0',
            ArtifactOrigin::FORK,
            [
                'source_artifact_uuid' => $source->artifact->artifact_uuid,
                'source_version' => $source->semver,
                'source_owner_user_id' => $source->artifact->owner_user_id,
            ],
        );
    }

    /** @param array<string, mixed> $content @param array<string, mixed> $documentation */
    private function storeDraft(
        ReusableArtifact $artifact,
        User $actor,
        string $semver,
        array $content,
        array $documentation,
        ?string $changeSummary,
    ): ReusableArtifactVersion {
        if ($artifact->versions()->where('status', ReusableArtifactVersion::STATUS_DRAFT)->exists()) {
            throw new InvalidArgumentException('Artifact lineage already has an active Draft.');
        }

        return $artifact->versions()->create([
            'semver' => $semver,
            'status' => ReusableArtifactVersion::STATUS_DRAFT,
            'draft_slot' => 1,
            'content_json' => $content,
            'documentation_json' => $documentation,
            'definition_hash' => DefinitionHasher::hash($content),
            'change_summary' => $changeSummary,
            'lock_version' => 0,
            'created_by_user_id' => $actor->id,
        ]);
    }

    private function assertTypeAndVersion(string $type, string $semver): void
    {
        if (! ArtifactType::isValid($type)) {
            throw new InvalidArgumentException("Unsupported artifact type: {$type}");
        }
        if (preg_match('/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/', $semver) !== 1) {
            throw new InvalidArgumentException("Artifact version must be valid SemVer: {$semver}");
        }
    }

    private function assertOwner(ReusableArtifact $artifact, User $actor): void
    {
        if ((int) $artifact->owner_user_id !== (int) $actor->id) {
            throw new InvalidArgumentException('Only the artifact owner may change its lifecycle.');
        }
    }

    /** @param array<string, mixed> $content */
    private function assertIntrinsicValidity(ReusableArtifact $artifact, array $content): void
    {
        if (($content['artifact_type'] ?? null) !== $artifact->artifact_type) {
            throw new InvalidArgumentException('Artifact content type does not match its lineage.');
        }
        $result = $this->validator->validateEnvelope($content);
        if (! $result->ok) {
            throw new InvalidArgumentException('Artifact publication validation failed: '.json_encode($result->toArray()));
        }
    }

    /**
     * @param  list<array<string, mixed>>  $dependencies
     * @return list<array<string, mixed>>
     */
    private function normalizeDependencies(ReusableArtifactVersion $source, array $dependencies): array
    {
        $out = [];
        foreach ($dependencies as $dependency) {
            $targetId = isset($dependency['artifact_version_id']) ? (int) $dependency['artifact_version_id'] : null;
            $indicatorId = isset($dependency['indicator_id']) ? (string) $dependency['indicator_id'] : null;
            $indicatorVersion = isset($dependency['indicator_version']) ? (string) $dependency['indicator_version'] : null;
            if ($targetId !== null) {
                $target = ReusableArtifactVersion::query()->with('artifact')->find($targetId);
                if (! $target || $target->status !== ReusableArtifactVersion::STATUS_PUBLISHED) {
                    throw new InvalidArgumentException('Artifact dependencies must target an exact published version.');
                }
                if ((int) $target->artifact->owner_user_id !== (int) $source->artifact->owner_user_id) {
                    throw new InvalidArgumentException('Dependency version is not available in the owner Library.');
                }
                if ($source->artifact->artifact_type === ArtifactType::BUNDLE
                    && $target->artifact->artifact_type === ArtifactType::BUNDLE) {
                    throw new InvalidArgumentException('Nested Bundles are not supported in V5.');
                }
            } elseif ($indicatorId !== null && $indicatorVersion !== null) {
                if ($this->indicators->findVersion($indicatorId, $indicatorVersion) === null) {
                    throw new InvalidArgumentException("Unknown Indicator dependency: {$indicatorId}@{$indicatorVersion}");
                }
            } else {
                throw new InvalidArgumentException('Dependency must identify an exact artifact or Indicator version.');
            }
            $out[] = [
                'kind' => (string) ($dependency['kind'] ?? 'uses_artifact'),
                'target_artifact_version_id' => $targetId,
                'indicator_id' => $indicatorId,
                'indicator_version' => $indicatorVersion,
                'required' => (bool) ($dependency['required'] ?? true),
            ];
        }

        return $out;
    }

    /** @param list<int> $targetIds */
    private function assertDag(int $sourceId, array $targetIds): void
    {
        $visit = function (int $versionId, array $path) use (&$visit, $sourceId): void {
            if ($versionId === $sourceId || in_array($versionId, $path, true)) {
                throw new InvalidArgumentException('Artifact dependency graph must be a strict DAG.');
            }
            $children = ReusableArtifactDependency::query()
                ->where('source_version_id', $versionId)
                ->whereNotNull('target_artifact_version_id')
                ->pluck('target_artifact_version_id');
            foreach ($children as $child) {
                $visit((int) $child, [...$path, $versionId]);
            }
        };
        foreach ($targetIds as $targetId) {
            $visit((int) $targetId, []);
        }
    }
}
