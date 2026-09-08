<?php

namespace App\Services\Artifacts;

use App\Models\ReusableArtifact;
use App\Models\ReusableArtifactVersion;
use App\Models\User;
use App\Services\Indicators\IndicatorRegistry;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ArtifactPackageService
{
    public const FORMAT = 'stox.reusable_artifacts.v1';

    public function __construct(
        private ArtifactLibraryAccessService $access,
        private ArtifactValidationService $validator,
        private ReusableArtifactLifecycleService $lifecycle,
        private IndicatorRegistry $indicators,
    ) {}

    /** @return array<string, mixed> */
    public function export(ReusableArtifactVersion $root, User $actor): array
    {
        $root->loadMissing('artifact', 'dependencies');
        if ($root->status !== ReusableArtifactVersion::STATUS_PUBLISHED || ! $this->access->canAccess($actor, $root)) {
            throw new InvalidArgumentException('Only an accessible published artifact version can be exported.');
        }

        $versions = $this->versionClosure($root);
        $artifacts = array_map(fn (ReusableArtifactVersion $version) => $this->serializeVersion($version), $versions);
        usort($artifacts, fn (array $left, array $right) => strcmp($left['source_key'], $right['source_key']));
        $package = [
            'schema_version' => '1.0',
            'package_format' => self::FORMAT,
            'root_source_key' => $this->sourceKey($root),
            'exported_at' => now()->toIso8601String(),
            'artifacts' => $artifacts,
        ];
        $package['checksum'] = DefinitionHasher::hash($this->checksumPayload($package));

        return $package;
    }

    /** @return list<ReusableArtifactVersion> */
    public function import(array $package, User $recipient): array
    {
        $artifacts = $this->validatePackage($package);

        return DB::transaction(function () use ($artifacts, $recipient) {
            $drafts = [];
            foreach ($artifacts as $artifact) {
                $slug = $this->uniqueSlug($recipient, (string) $artifact['slug'], (string) $artifact['artifact_type']);
                $content = $artifact['content'];
                $content['slug'] = $slug;
                $content['name'] = (string) $artifact['name'];
                $content['metadata'] = array_merge(
                    is_array($content['metadata'] ?? null) ? $content['metadata'] : [],
                    ['origin' => ArtifactOrigin::IMPORTED, 'status' => ArtifactStatus::DRAFT],
                );
                $drafts[] = $this->lifecycle->createDraft(
                    $recipient,
                    (string) $artifact['artifact_type'],
                    $slug,
                    (string) $artifact['name'],
                    $content,
                    '1.0.0',
                    ArtifactOrigin::IMPORTED,
                    [
                        'kind' => 'foreign_import',
                        'source_key' => $artifact['source_key'],
                        'source_artifact_uuid' => $artifact['source_artifact_uuid'],
                        'source_version' => $artifact['source_version'],
                        'source_definition_hash' => $artifact['definition_hash'],
                        'pending_dependency_refs' => $artifact['dependencies'],
                    ],
                );
            }

            return $drafts;
        });
    }

    /** @return list<array<string, mixed>> */
    public function validatePackage(array $package): array
    {
        if (($package['package_format'] ?? null) !== self::FORMAT || ($package['schema_version'] ?? null) !== '1.0') {
            throw new InvalidArgumentException('Unsupported reusable artifact package format or schema.');
        }
        $expected = DefinitionHasher::hash($this->checksumPayload($package));
        if (! hash_equals($expected, (string) ($package['checksum'] ?? ''))) {
            throw new InvalidArgumentException('Artifact package checksum is invalid.');
        }
        $artifacts = is_array($package['artifacts'] ?? null) ? array_values($package['artifacts']) : [];
        if ($artifacts === []) {
            throw new InvalidArgumentException('Artifact package contains no versions.');
        }

        $byKey = [];
        foreach ($artifacts as $artifact) {
            if (! is_array($artifact)) {
                throw new InvalidArgumentException('Artifact package entry must be an object.');
            }
            $key = (string) ($artifact['source_key'] ?? '');
            $type = (string) ($artifact['artifact_type'] ?? '');
            $content = is_array($artifact['content'] ?? null) ? $artifact['content'] : [];
            if ($key === '' || isset($byKey[$key]) || ! ArtifactType::isValid($type)) {
                throw new InvalidArgumentException('Artifact package contains an invalid or duplicate source identity.');
            }
            if (($content['artifact_type'] ?? null) !== $type) {
                throw new InvalidArgumentException("Artifact package content type mismatch for {$key}.");
            }
            $validation = $this->validator->validateEnvelope($content);
            if (! $validation->ok) {
                throw new InvalidArgumentException("Artifact package entry failed validation: {$key}");
            }
            if (! hash_equals((string) ($artifact['definition_hash'] ?? ''), DefinitionHasher::hash($content))) {
                throw new InvalidArgumentException("Artifact package definition hash mismatch: {$key}");
            }
            $byKey[$key] = $artifact;
        }
        if (! isset($byKey[(string) ($package['root_source_key'] ?? '')])) {
            throw new InvalidArgumentException('Artifact package root is missing.');
        }

        $edges = [];
        foreach ($byKey as $key => $artifact) {
            $edges[$key] = [];
            foreach ($artifact['dependencies'] ?? [] as $dependency) {
                if (! is_array($dependency)) {
                    throw new InvalidArgumentException("Invalid dependency in {$key}.");
                }
                $target = $dependency['target_source_key'] ?? null;
                if ($target !== null) {
                    if (! isset($byKey[$target])) {
                        throw new InvalidArgumentException("Missing packaged dependency {$target}.");
                    }
                    if ($artifact['artifact_type'] === ArtifactType::BUNDLE
                        && $byKey[$target]['artifact_type'] === ArtifactType::BUNDLE) {
                        throw new InvalidArgumentException('Nested Bundles are not supported in V5.');
                    }
                    $edges[$key][] = $target;
                } elseif (! isset($dependency['indicator_id'], $dependency['indicator_version'])) {
                    throw new InvalidArgumentException("Dependency in {$key} is not exactly versioned.");
                } elseif ($this->indicators->findVersion(
                    (string) $dependency['indicator_id'],
                    (string) $dependency['indicator_version'],
                ) === null) {
                    throw new InvalidArgumentException("Unknown Indicator dependency in {$key}.");
                }
            }
        }
        $this->assertDag($edges);

        return $artifacts;
    }

    /** @return list<ReusableArtifactVersion> */
    private function versionClosure(ReusableArtifactVersion $root): array
    {
        $versions = [];
        $walk = function (ReusableArtifactVersion $version) use (&$walk, &$versions): void {
            if (isset($versions[$version->id])) {
                return;
            }
            $version->loadMissing('artifact', 'dependencies');
            $versions[$version->id] = $version;
            foreach ($version->dependencies as $dependency) {
                if ($dependency->target_artifact_version_id !== null) {
                    $walk(ReusableArtifactVersion::query()->findOrFail($dependency->target_artifact_version_id));
                }
            }
        };
        $walk($root);

        return array_values($versions);
    }

    /** @return array<string, mixed> */
    private function serializeVersion(ReusableArtifactVersion $version): array
    {
        $version->loadMissing('artifact', 'dependencies', 'dependencies.targetVersion.artifact');

        return [
            'source_key' => $this->sourceKey($version),
            'source_artifact_uuid' => $version->artifact->artifact_uuid,
            'source_version' => $version->semver,
            'artifact_type' => $version->artifact->artifact_type,
            'slug' => $version->artifact->slug,
            'name' => $version->artifact->name,
            'origin' => $version->artifact->origin,
            'provenance' => $version->artifact->provenance_json ?? [],
            'content' => $version->content_json,
            'documentation' => $version->documentation_json ?? [],
            'change_summary' => $version->change_summary,
            'definition_hash' => $version->definition_hash,
            'dependencies' => $version->dependencies->map(function ($dependency): array {
                return [
                    'kind' => $dependency->kind,
                    'target_source_key' => $dependency->targetVersion
                        ? $this->sourceKey($dependency->targetVersion)
                        : null,
                    'indicator_id' => $dependency->indicator_id,
                    'indicator_version' => $dependency->indicator_version,
                    'required' => (bool) $dependency->required,
                ];
            })->all(),
        ];
    }

    private function sourceKey(ReusableArtifactVersion $version): string
    {
        $version->loadMissing('artifact');

        return $version->artifact->artifact_uuid.'@'.$version->semver;
    }

    /** @return array<string, mixed> */
    private function checksumPayload(array $package): array
    {
        unset($package['checksum'], $package['exported_at']);

        return $package;
    }

    /** @param array<string, list<string>> $edges */
    private function assertDag(array $edges): void
    {
        $visiting = [];
        $visited = [];
        $visit = function (string $key) use (&$visit, &$visiting, &$visited, $edges): void {
            if (isset($visiting[$key])) {
                throw new InvalidArgumentException('Artifact package dependency graph must be a strict DAG.');
            }
            if (isset($visited[$key])) {
                return;
            }
            $visiting[$key] = true;
            foreach ($edges[$key] ?? [] as $child) {
                $visit($child);
            }
            unset($visiting[$key]);
            $visited[$key] = true;
        };
        foreach (array_keys($edges) as $key) {
            $visit($key);
        }
    }

    private function uniqueSlug(User $recipient, string $slug, string $type): string
    {
        $base = trim($slug) !== '' ? trim($slug) : 'imported_artifact';
        $candidate = $base;
        $suffix = 2;
        while (ReusableArtifact::query()
            ->where('owner_user_id', $recipient->id)
            ->where('artifact_type', $type)
            ->where('slug', $candidate)
            ->exists()) {
            $candidate = $base.'_import_'.$suffix++;
        }

        return $candidate;
    }
}
