<?php

namespace App\Services\Artifacts;

use App\Models\ArtifactLibraryAdoption;
use App\Models\ArtifactShareGrant;
use App\Models\ReusableArtifactVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class ArtifactSharingService
{
    public function share(ReusableArtifactVersion $version, User $owner, User $recipient): ArtifactShareGrant
    {
        $version->loadMissing('artifact', 'dependencies');
        if ($version->status !== ReusableArtifactVersion::STATUS_PUBLISHED) {
            throw new InvalidArgumentException('Only immutable published artifact versions can be shared.');
        }
        if ((int) $version->artifact->owner_user_id !== (int) $owner->id) {
            throw new InvalidArgumentException('Only the artifact owner can share this version.');
        }
        if ((int) $owner->id === (int) $recipient->id) {
            throw new InvalidArgumentException('An artifact owner already has Library access.');
        }

        $dependencyIds = $this->dependencyClosure($version);

        $grant = ArtifactShareGrant::query()->firstOrNew([
            'artifact_version_id' => $version->id,
            'recipient_user_id' => $recipient->id,
        ]);
        if (! $grant->exists) {
            $grant->grant_uuid = (string) Str::uuid();
        }
        $grant->forceFill([
            'owner_user_id' => $owner->id,
            'dependency_version_ids_json' => $dependencyIds,
            'status' => ArtifactShareGrant::STATUS_ACTIVE,
            'granted_at' => now(),
            'revoked_at' => null,
        ])->save();

        return $grant;
    }

    public function revoke(ArtifactShareGrant $grant, User $owner): ArtifactShareGrant
    {
        if ((int) $grant->owner_user_id !== (int) $owner->id) {
            throw new InvalidArgumentException('Only the sharing owner can revoke this grant.');
        }
        if ($grant->status === ArtifactShareGrant::STATUS_REVOKED) {
            return $grant;
        }
        $grant->forceFill(['status' => ArtifactShareGrant::STATUS_REVOKED, 'revoked_at' => now()])->save();

        return $grant->fresh();
    }

    /** @return list<ArtifactLibraryAdoption> */
    public function adopt(ArtifactShareGrant $grant, User $recipient): array
    {
        if ((int) $grant->recipient_user_id !== (int) $recipient->id
            || $grant->status !== ArtifactShareGrant::STATUS_ACTIVE) {
            throw new InvalidArgumentException('An active share grant for this recipient is required.');
        }
        $versionIds = array_values(array_unique([
            (int) $grant->artifact_version_id,
            ...array_map('intval', $grant->dependency_version_ids_json ?? []),
        ]));

        return DB::transaction(function () use ($versionIds, $grant, $recipient) {
            $adoptions = [];
            foreach ($versionIds as $versionId) {
                $version = ReusableArtifactVersion::query()->with('artifact')->findOrFail($versionId);
                $adoptions[] = ArtifactLibraryAdoption::query()->firstOrCreate(
                    ['user_id' => $recipient->id, 'artifact_version_id' => $versionId],
                    [
                        'share_grant_id' => $grant->id,
                        'provenance_json' => [
                            'kind' => 'shared_adoption',
                            'source_artifact_uuid' => $version->artifact->artifact_uuid,
                            'source_version' => $version->semver,
                            'source_owner_user_id' => $version->artifact->owner_user_id,
                            'share_grant_uuid' => $grant->grant_uuid,
                        ],
                        'adopted_at' => now(),
                    ],
                );
            }

            return $adoptions;
        });
    }

    /** @return list<int> */
    private function dependencyClosure(ReusableArtifactVersion $root): array
    {
        $seen = [];
        $walk = function (ReusableArtifactVersion $version) use (&$walk, &$seen): void {
            $version->loadMissing('dependencies');
            foreach ($version->dependencies as $dependency) {
                if ($dependency->target_artifact_version_id === null) {
                    continue;
                }
                $id = (int) $dependency->target_artifact_version_id;
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $walk(ReusableArtifactVersion::query()->findOrFail($id));
            }
        };
        $walk($root);

        return array_keys($seen);
    }
}
