<?php

namespace App\Services\Artifacts;

use App\Models\ArtifactBinding;
use App\Models\ArtifactBundleDeployment;
use App\Models\PortfolioProfile;
use App\Models\ReusableArtifactVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final class ArtifactBundleDeploymentService
{
    public function __construct(
        private ArtifactLibraryAccessService $access,
        private ArtifactBindingService $bindings,
    ) {}

    /**
     * @param  array<int|string, array<string, mixed>>  $memberSettings  Version id => Portfolio settings
     */
    public function plan(
        ReusableArtifactVersion $bundle,
        PortfolioProfile $profile,
        User $actor,
        array $memberSettings = [],
    ): ArtifactBundleDeployment {
        $this->assertBundleAccess($bundle, $profile, $actor);
        $bundle->loadMissing('dependencies.targetVersion.artifact');
        $members = $bundle->dependencies->where('kind', 'bundle_member');
        if ($members->isEmpty()) {
            throw new InvalidArgumentException('Bundle has no deployable members.');
        }

        $seenArtifacts = [];
        $plan = [];
        foreach ($members as $dependency) {
            $version = $dependency->targetVersion;
            if (! $version || $version->artifact->artifact_type === ArtifactType::BUNDLE) {
                throw new InvalidArgumentException('Bundle members must be exact, non-Bundle published versions.');
            }
            if (isset($seenArtifacts[$version->artifact_id])) {
                throw new InvalidArgumentException('Bundle cannot contain multiple versions of one artifact lineage.');
            }
            $seenArtifacts[$version->artifact_id] = true;
            if (! $this->access->canAccess($actor, $version)) {
                throw new InvalidArgumentException('Bundle member is unavailable in this account Library.');
            }
            $binding = ArtifactBinding::query()
                ->where('profile_id', $profile->id)
                ->where('artifact_id', $version->artifact_id)
                ->with('activeRevision')
                ->first();
            $currentVersionId = $binding?->activeRevision?->artifact_version_id;
            $hasSettings = array_key_exists($version->id, $memberSettings)
                || array_key_exists((string) $version->id, $memberSettings);
            $settings = $memberSettings[$version->id] ?? $memberSettings[(string) $version->id] ?? null;
            if ($hasSettings && ! is_array($settings)) {
                throw new InvalidArgumentException('Bundle member settings must be an object.');
            }
            $currentSettings = $binding?->activeRevision?->settings_json ?? [];
            $action = match (true) {
                $binding === null => 'bind',
                $currentVersionId !== $version->id => 'upgrade',
                $hasSettings && $currentSettings !== $settings => 'settings',
                default => 'retain',
            };
            $plan[] = [
                'member_version_id' => $version->id,
                'artifact_uuid' => $version->artifact->artifact_uuid,
                'semver' => $version->semver,
                'action' => $action,
                'binding_id' => $binding?->id,
                'expected_lock_version' => $binding?->lock_version,
                'previous_revision_id' => $binding?->active_revision_id,
                'settings' => $hasSettings ? $settings : null,
            ];
        }

        return DB::transaction(function () use ($bundle, $profile, $actor, $plan) {
            $deployment = ArtifactBundleDeployment::query()->create([
                'deployment_uuid' => (string) Str::uuid(),
                'profile_id' => $profile->id,
                'bundle_version_id' => $bundle->id,
                'requested_by_user_id' => $actor->id,
                'status' => ArtifactBundleDeployment::STATUS_PLANNED,
                'plan_json' => $plan,
            ]);
            foreach ($plan as $item) {
                $deployment->items()->create([
                    'member_version_id' => $item['member_version_id'],
                    'action' => $item['action'],
                    'binding_id' => $item['binding_id'],
                    'previous_revision_id' => $item['previous_revision_id'],
                    'settings_json' => $item['settings'],
                ]);
            }

            return $deployment->fresh(['items.memberVersion.artifact']);
        });
    }

    public function deploy(ArtifactBundleDeployment $deployment, User $actor): ArtifactBundleDeployment
    {
        if ((int) $deployment->requested_by_user_id !== (int) $actor->id) {
            throw new InvalidArgumentException('Only the requesting account can execute this Bundle plan.');
        }
        if ($deployment->status !== ArtifactBundleDeployment::STATUS_PLANNED) {
            throw new InvalidArgumentException('Bundle deployment plan is no longer pending.');
        }

        try {
            DB::transaction(function () use ($deployment, $actor) {
                $locked = ArtifactBundleDeployment::query()->lockForUpdate()->findOrFail($deployment->id);
                if ($locked->status !== ArtifactBundleDeployment::STATUS_PLANNED) {
                    throw new InvalidArgumentException('Bundle deployment plan is no longer pending.');
                }
                $locked->forceFill(['started_at' => now()])->save();
                foreach ($locked->items()->orderBy('id')->get() as $item) {
                    $version = ReusableArtifactVersion::query()->with('artifact', 'dependencies')->findOrFail($item->member_version_id);
                    if ($item->action === 'bind') {
                        if (ArtifactBinding::query()
                            ->where('profile_id', $locked->profile_id)
                            ->where('artifact_id', $version->artifact_id)
                            ->exists()) {
                            throw new InvalidArgumentException('Bundle plan is stale: a member was bound after planning.');
                        }
                        $binding = $this->bindings->bind($locked->profile, $version, $actor, $item->settings_json ?? [], true);
                    } else {
                        $binding = ArtifactBinding::query()->with('activeRevision.artifactVersion')->findOrFail($item->binding_id);
                        $planned = collect($locked->plan_json)->firstWhere('member_version_id', $item->member_version_id);
                        if ((int) $binding->lock_version !== (int) ($planned['expected_lock_version'] ?? -1)
                            || (int) $binding->active_revision_id !== (int) ($planned['previous_revision_id'] ?? -1)) {
                            throw new InvalidArgumentException('Bundle plan is stale: a member binding changed after planning.');
                        }
                        if ($item->action === 'upgrade') {
                            $binding = $this->bindings->upgrade(
                                $binding,
                                $version,
                                $actor,
                                $binding->lock_version,
                                $item->settings_json ?? null,
                                'Bundle deployment '.$locked->deployment_uuid,
                            );
                        } elseif ($item->action === 'settings') {
                            $binding = $this->bindings->updateSettings(
                                $binding,
                                $actor,
                                $binding->lock_version,
                                $item->settings_json ?? [],
                                'Bundle deployment '.$locked->deployment_uuid,
                            );
                        }
                    }
                    $item->forceFill([
                        'binding_id' => $binding->id,
                        'resulting_revision_id' => $binding->active_revision_id,
                    ])->save();
                }
                $locked->forceFill([
                    'status' => ArtifactBundleDeployment::STATUS_COMPLETED,
                    'completed_at' => now(),
                ])->save();
            });
        } catch (Throwable $error) {
            $deployment->fresh()->forceFill([
                'status' => ArtifactBundleDeployment::STATUS_FAILED,
                'error_code' => 'bundle_deployment_failed',
                'started_at' => $deployment->started_at ?? now(),
                'completed_at' => now(),
            ])->save();
            throw $error;
        }

        return $deployment->fresh(['items.binding.activeRevision', 'items.memberVersion.artifact']);
    }

    private function assertBundleAccess(ReusableArtifactVersion $bundle, PortfolioProfile $profile, User $actor): void
    {
        $bundle->loadMissing('artifact');
        if ((int) $profile->user_id !== (int) $actor->id) {
            throw new InvalidArgumentException('Portfolio does not belong to this account.');
        }
        if ($bundle->status !== ReusableArtifactVersion::STATUS_PUBLISHED
            || $bundle->artifact->artifact_type !== ArtifactType::BUNDLE
            || ! $this->access->canAccess($actor, $bundle)) {
            throw new InvalidArgumentException('An accessible published Bundle version is required.');
        }
    }
}
