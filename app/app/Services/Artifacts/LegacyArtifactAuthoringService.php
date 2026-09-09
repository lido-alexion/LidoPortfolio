<?php

namespace App\Services\Artifacts;

use App\Models\PortfolioProfile;
use App\Models\ReusableArtifact;
use App\Models\ReusableArtifactVersion;
use Illuminate\Support\Str;

/**
 * One-way bridge from V3 compatibility authoring into the V5 Library lifecycle.
 * It deliberately creates no runnable legacy row: publication and Portfolio
 * binding remain explicit user actions.
 */
final class LegacyArtifactAuthoringService
{
    public function __construct(private ReusableArtifactLifecycleService $lifecycle) {}

    /** @param array<string, mixed> $envelope @param array<string, mixed> $provenance */
    public function createDraft(
        PortfolioProfile $profile,
        array $envelope,
        string $origin = ArtifactOrigin::USER,
        array $provenance = [],
    ): array {
        $owner = $profile->user()->firstOrFail();
        $type = (string) $envelope['artifact_type'];
        $name = trim((string) ($envelope['name'] ?? ''));
        $slug = $this->uniqueSlug($owner->id, $type, (string) ($envelope['slug'] ?? $name));
        $metadata = is_array($envelope['metadata'] ?? null) ? $envelope['metadata'] : [];
        $envelope['slug'] = $slug;
        $envelope['name'] = $name;
        $envelope['metadata'] = array_merge($metadata, [
            'status' => ArtifactStatus::DRAFT,
            'origin' => $origin,
        ]);
        unset($envelope['artifact_id'], $envelope['definition_hash'], $envelope['validation']);
        $envelope = ArtifactEnvelope::withFreshHash($envelope);

        $draft = $this->lifecycle->createDraft(
            $owner,
            $type,
            $slug,
            $name,
            $envelope,
            '1.0.0',
            $origin,
            array_merge(['kind' => 'legacy_authoring_cutover', 'profile_id' => $profile->id], $provenance),
        );

        return $this->format($draft);
    }

    /** @return array<string, mixed> */
    private function format(ReusableArtifactVersion $draft): array
    {
        $draft->loadMissing('artifact');

        return [
            'artifact_type' => $draft->artifact->artifact_type,
            'slug' => $draft->artifact->slug,
            'name' => $draft->artifact->name,
            'status' => $draft->status,
            'artifact_uuid' => $draft->artifact->artifact_uuid,
            'reusable_artifact_uuid' => $draft->artifact->artifact_uuid,
            'reusable_artifact_version_id' => $draft->id,
            'library_path' => '/artifact-library/'.$draft->artifact->artifact_uuid,
            'metadata' => [
                'status' => ArtifactStatus::DRAFT,
                'origin' => $draft->artifact->origin,
                'compatibility_read_only' => true,
            ],
        ];
    }

    private function uniqueSlug(int $ownerId, string $type, string $desired): string
    {
        $base = Str::slug($desired, '_') ?: 'artifact';
        $candidate = $base;
        $suffix = 2;
        while (ReusableArtifact::query()
            ->where('owner_user_id', $ownerId)
            ->where('artifact_type', $type)
            ->where('slug', $candidate)
            ->exists()) {
            $candidate = $base.'_'.$suffix++;
        }

        return $candidate;
    }
}
