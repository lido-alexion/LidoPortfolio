<?php

namespace App\Services\Artifacts;

use App\Models\ArtifactLibraryAdoption;
use App\Models\ArtifactShareGrant;
use App\Models\ReusableArtifactVersion;
use App\Models\User;

final class ArtifactLibraryAccessService
{
    public function canAccess(User $user, ReusableArtifactVersion $version): bool
    {
        $version->loadMissing('artifact');
        if ((int) $version->artifact->owner_user_id === (int) $user->id) {
            return true;
        }
        if ($version->status === ReusableArtifactVersion::STATUS_PUBLISHED
            && $version->artifact->origin === ArtifactOrigin::FACTORY) {
            return true;
        }
        if (ArtifactLibraryAdoption::query()
            ->where('user_id', $user->id)
            ->where('artifact_version_id', $version->id)
            ->exists()) {
            return true;
        }

        return ArtifactShareGrant::query()
            ->where('recipient_user_id', $user->id)
            ->where('status', ArtifactShareGrant::STATUS_ACTIVE)
            ->get()
            ->contains(function (ArtifactShareGrant $grant) use ($version): bool {
                return (int) $grant->artifact_version_id === (int) $version->id
                    || in_array((int) $version->id, array_map('intval', $grant->dependency_version_ids_json ?? []), true);
            });
    }
}
