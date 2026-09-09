<?php

namespace App\Services\Artifacts;

use App\Models\ArtifactBinding;
use App\Models\ArtifactBindingRevision;
use App\Models\PortfolioProfile;
use App\Models\ReusableArtifactVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class ArtifactBindingService
{
    public function __construct(
        private ArtifactUsabilityEvaluator $usabilityEvaluator,
        private ArtifactLibraryAccessService $libraryAccess,
        private ArtifactLegacyProjectionService $legacyProjection,
    ) {}

    /** @param array<string, mixed> $settings */
    public function bind(
        PortfolioProfile $profile,
        ReusableArtifactVersion $version,
        User $actor,
        array $settings = [],
        bool $enable = false,
    ): ArtifactBinding {
        $this->assertPortfolioOwner($profile, $actor);
        $this->assertAvailablePublishedVersion($version, $actor);
        [$usability, $reasons] = $this->usability($version);
        if ($enable && $usability === ArtifactBinding::BLOCKED) {
            throw new InvalidArgumentException('A blocked artifact version cannot be enabled for this Portfolio.');
        }
        if (ArtifactBinding::query()
            ->where('profile_id', $profile->id)
            ->where('artifact_id', $version->artifact_id)
            ->exists()) {
            throw new InvalidArgumentException('This artifact already has a binding in the Portfolio.');
        }

        return DB::transaction(function () use ($profile, $version, $actor, $settings, $enable, $usability, $reasons) {
            $binding = ArtifactBinding::query()->create([
                'binding_uuid' => (string) Str::uuid(),
                'profile_id' => $profile->id,
                'artifact_id' => $version->artifact_id,
                'status' => $enable ? ArtifactBinding::STATUS_ENABLED : ArtifactBinding::STATUS_DISABLED,
                'usability_state' => $usability,
                'usability_reasons_json' => $reasons,
                'lock_version' => 0,
            ]);
            $revision = $this->createRevision($binding, $version, $actor, $settings, 'bind', 'Initial Portfolio binding');
            $binding->forceFill(['active_revision_id' => $revision->id])->save();
            $this->legacyProjection->sync($binding, $version);

            return $binding->fresh(['activeRevision.artifactVersion', 'revisions']);
        });
    }

    /** @param array<string, mixed>|null $settings */
    public function upgrade(
        ArtifactBinding $binding,
        ReusableArtifactVersion $target,
        User $actor,
        int $expectedLockVersion,
        ?array $settings = null,
        ?string $changeSummary = null,
    ): ArtifactBinding {
        $this->assertBindingOwner($binding, $actor);
        $this->assertAvailablePublishedVersion($target, $actor);
        if ((int) $target->artifact_id !== (int) $binding->artifact_id) {
            throw new InvalidArgumentException('Binding upgrades must target the same artifact lineage.');
        }

        return $this->revise(
            $binding,
            $target,
            $actor,
            $expectedLockVersion,
            $settings ?? ($binding->activeRevision?->settings_json ?? []),
            $binding->status,
            'upgrade',
            $changeSummary ?? 'Explicit upgrade to '.$target->semver,
        );
    }

    /** @param array<string, mixed> $settings */
    public function updateSettings(
        ArtifactBinding $binding,
        User $actor,
        int $expectedLockVersion,
        array $settings,
        ?string $changeSummary = null,
    ): ArtifactBinding {
        $this->assertBindingOwner($binding, $actor);
        $version = $binding->activeRevision?->artifactVersion;
        if (! $version) {
            throw new InvalidArgumentException('Binding has no active artifact revision.');
        }

        return $this->revise(
            $binding,
            $version,
            $actor,
            $expectedLockVersion,
            $settings,
            $binding->status,
            'settings_update',
            $changeSummary ?? 'Deployment settings updated',
        );
    }

    public function setEnabled(
        ArtifactBinding $binding,
        User $actor,
        int $expectedLockVersion,
        bool $enabled,
    ): ArtifactBinding {
        $this->assertBindingOwner($binding, $actor);
        $version = $binding->activeRevision?->artifactVersion;
        if (! $version) {
            throw new InvalidArgumentException('Binding has no active artifact revision.');
        }

        return $this->revise(
            $binding,
            $version,
            $actor,
            $expectedLockVersion,
            $binding->activeRevision->settings_json ?? [],
            $enabled ? ArtifactBinding::STATUS_ENABLED : ArtifactBinding::STATUS_DISABLED,
            $enabled ? 'enable' : 'disable',
            $enabled ? 'Binding enabled' : 'Binding disabled',
        );
    }

    /** @param array<string, mixed> $settings */
    private function revise(
        ArtifactBinding $binding,
        ReusableArtifactVersion $version,
        User $actor,
        int $expectedLockVersion,
        array $settings,
        string $status,
        string $action,
        string $changeSummary,
    ): ArtifactBinding {
        return DB::transaction(function () use ($binding, $version, $actor, $expectedLockVersion, $settings, $status, $action, $changeSummary) {
            $locked = ArtifactBinding::query()->lockForUpdate()->findOrFail($binding->id);
            if ($locked->lock_version !== $expectedLockVersion) {
                throw new RuntimeException('Artifact binding changed since it was loaded. Refresh before saving.');
            }
            $locked->load('activeRevision.artifactVersion');
            [$usability, $reasons] = $this->usability($version);
            if ($status === ArtifactBinding::STATUS_ENABLED && $usability === ArtifactBinding::BLOCKED) {
                throw new InvalidArgumentException('A blocked artifact version cannot be enabled for this Portfolio.');
            }
            $locked->forceFill([
                'status' => $status,
                'usability_state' => $usability,
                'usability_reasons_json' => $reasons,
                'lock_version' => $expectedLockVersion + 1,
            ])->save();
            $revision = $this->createRevision($locked, $version, $actor, $settings, $action, $changeSummary);
            $locked->forceFill(['active_revision_id' => $revision->id])->save();
            $locked->unsetRelation('activeRevision');
            $this->legacyProjection->sync($locked, $version);

            return $locked->fresh(['activeRevision.artifactVersion', 'revisions']);
        });
    }

    /** @param array<string, mixed> $settings */
    private function createRevision(
        ArtifactBinding $binding,
        ReusableArtifactVersion $version,
        User $actor,
        array $settings,
        string $action,
        string $changeSummary,
    ): ArtifactBindingRevision {
        [$usability, $reasons] = $this->usability($version);

        return $binding->revisions()->create([
            'revision_number' => ((int) $binding->revisions()->max('revision_number')) + 1,
            'artifact_version_id' => $version->id,
            'settings_json' => $settings,
            'binding_status' => $binding->status,
            'usability_state' => $usability,
            'usability_reasons_json' => $reasons,
            'action' => $action,
            'change_summary' => $changeSummary,
            'activated_by_user_id' => $actor->id,
            'activated_at' => now(),
        ]);
    }

    /** @return array{0:string,1:list<string>} */
    private function usability(ReusableArtifactVersion $version): array
    {
        return $this->usabilityEvaluator->evaluate($version);
    }

    private function assertAvailablePublishedVersion(ReusableArtifactVersion $version, User $actor): void
    {
        $version->loadMissing('artifact', 'dependencies');
        if ($version->status !== ReusableArtifactVersion::STATUS_PUBLISHED) {
            throw new InvalidArgumentException('Portfolio bindings require an immutable published artifact version.');
        }
        if (! $this->libraryAccess->canAccess($actor, $version)) {
            throw new InvalidArgumentException('Artifact version is not available in this account Library.');
        }
    }

    private function assertPortfolioOwner(PortfolioProfile $profile, User $actor): void
    {
        if ((int) $profile->user_id !== (int) $actor->id) {
            throw new InvalidArgumentException('Portfolio does not belong to this account.');
        }
    }

    private function assertBindingOwner(ArtifactBinding $binding, User $actor): void
    {
        $binding->loadMissing('profile', 'artifact', 'activeRevision.artifactVersion');
        $this->assertPortfolioOwner($binding->profile, $actor);
        $version = $binding->activeRevision?->artifactVersion;
        if (! $version || ! $this->libraryAccess->canAccess($actor, $version)) {
            throw new InvalidArgumentException('Artifact binding is not available in this account Library.');
        }
    }
}
