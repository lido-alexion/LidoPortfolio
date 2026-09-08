<?php

namespace App\Services\Artifacts;

use App\Models\PortfolioProfile;
use App\Models\ReusableArtifact;
use App\Models\ReusableArtifactVersion;
use App\Models\User;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final class ArtifactLibraryQueryService
{
    public function __construct(
        private ArtifactLibraryAccessService $access,
        private ArtifactStructuralDiffService $diffs,
    ) {}

    /** @return list<array<string, mixed>> */
    public function index(User $user, PortfolioProfile $profile, array $filters = []): array
    {
        $query = ReusableArtifact::query()->with(['versions.dependencies.targetVersion.artifact']);
        if ($type = $filters['type'] ?? null) {
            $query->where('artifact_type', $type);
        }
        if (! empty($filters['q'])) {
            $needle = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim((string) $filters['q'])).'%';
            $query->where(fn ($q) => $q->where('name', 'like', $needle)->orWhere('slug', 'like', $needle));
        }

        return $query->orderBy('name')->get()
            ->map(fn (ReusableArtifact $artifact) => $this->visibleVersions($artifact, $user))
            ->filter(fn (Collection $versions) => $versions->isNotEmpty())
            ->map(fn (Collection $versions) => $this->summary($versions->first()->artifact, $versions, $user, $profile))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    public function detail(string $uuid, User $user, PortfolioProfile $profile): array
    {
        $artifact = ReusableArtifact::query()
            ->where('artifact_uuid', $uuid)
            ->with(['versions.dependencies.targetVersion.artifact'])
            ->firstOrFail();
        $versions = $this->visibleVersions($artifact, $user);
        if ($versions->isEmpty()) {
            throw new InvalidArgumentException('Artifact is not available in this account Library.');
        }

        return [
            ...$this->summary($artifact, $versions, $user, $profile),
            'versions' => $versions->sort(fn ($a, $b) => version_compare($b->semver, $a->semver))->values()
                ->map(fn (ReusableArtifactVersion $version) => $this->version($version))->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function diff(string $uuid, string $from, string $to, User $user): array
    {
        $artifact = ReusableArtifact::query()->where('artifact_uuid', $uuid)->with('versions.artifact')->firstOrFail();
        $versions = $this->visibleVersions($artifact, $user)->keyBy('semver');
        $before = $versions->get($from);
        $after = $versions->get($to);
        if (! $before || ! $after) {
            throw new InvalidArgumentException('Both versions must be available in this account Library.');
        }

        return [
            'artifact_uuid' => $uuid,
            'from' => $from,
            'to' => $to,
            'changes' => $this->diffs->diff($before->content_json, $after->content_json),
        ];
    }

    private function visibleVersions(ReusableArtifact $artifact, User $user): Collection
    {
        return $artifact->versions->filter(fn (ReusableArtifactVersion $version) => (int) $artifact->owner_user_id === (int) $user->id || $this->access->canAccess($user, $version));
    }

    /** @return array<string, mixed> */
    private function summary(ReusableArtifact $artifact, Collection $versions, User $user, PortfolioProfile $profile): array
    {
        $permission = (int) $artifact->owner_user_id === (int) $user->id
            ? 'owner'
            : ($artifact->origin === ArtifactOrigin::FACTORY ? 'system' : 'shared');
        $binding = $artifact->bindings()->where('profile_id', $profile->id)->with('activeRevision')->first();

        return [
            'artifact_uuid' => $artifact->artifact_uuid,
            'type' => $artifact->artifact_type,
            'slug' => $artifact->slug,
            'name' => $artifact->name,
            'origin' => $artifact->origin,
            'provenance' => $artifact->provenance_json,
            'permission' => $permission,
            'archived_at' => $artifact->archived_at?->toIso8601String(),
            'latest_published_version' => $versions->where('status', ReusableArtifactVersion::STATUS_PUBLISHED)
                ->sort(fn ($a, $b) => version_compare($b->semver, $a->semver))->first()?->semver,
            'draft_version' => $versions->firstWhere('status', ReusableArtifactVersion::STATUS_DRAFT)?->semver,
            'portfolio_binding' => $binding ? [
                'binding_uuid' => $binding->binding_uuid,
                'status' => $binding->status,
                'usability_state' => $binding->usability_state,
                'usability_reasons' => $binding->usability_reasons_json ?? [],
                'active_version' => $binding->activeRevision?->artifactVersion?->semver,
                'lock_version' => $binding->lock_version,
            ] : null,
        ];
    }

    /** @return array<string, mixed> */
    private function version(ReusableArtifactVersion $version): array
    {
        return [
            'id' => $version->id,
            'semver' => $version->semver,
            'status' => $version->status,
            'definition_hash' => $version->definition_hash,
            'content' => $version->content_json,
            'documentation' => $version->documentation_json,
            'change_summary' => $version->change_summary,
            'lock_version' => $version->lock_version,
            'published_at' => $version->published_at?->toIso8601String(),
            'dependencies' => $version->dependencies->map(fn ($dependency) => [
                'kind' => $dependency->kind,
                'required' => $dependency->required,
                'artifact_uuid' => $dependency->targetVersion?->artifact?->artifact_uuid,
                'artifact_version' => $dependency->targetVersion?->semver,
                'indicator_id' => $dependency->indicator_id,
                'indicator_version' => $dependency->indicator_version,
            ])->values()->all(),
        ];
    }
}
