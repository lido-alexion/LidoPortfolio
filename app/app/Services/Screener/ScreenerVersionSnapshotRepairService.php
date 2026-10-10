<?php

namespace App\Services\Screener;

use App\Models\ReusableArtifactVersion;
use App\Models\Screener;
use App\Models\ScreenerVersion;
use App\Services\Artifacts\ArtifactType;
use App\Services\Artifacts\DefinitionHasher;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ScreenerVersionSnapshotRepairService
{
    public function restoreFromVersion(
        Screener $screener,
        int $missingVersion,
        int $proofVersion,
        string $expectedHash,
        string $changeNotes,
        bool $dryRun = false,
    ): array {
        return $this->restore(
            $screener,
            $missingVersion,
            $expectedHash,
            $changeNotes,
            fn (Screener $locked) => $this->payloadFromVersion($locked, $proofVersion, $expectedHash),
            $dryRun,
        );
    }

    public function restoreFromArtifact(
        Screener $screener,
        int $missingVersion,
        int $proofArtifactVersionId,
        string $expectedHash,
        string $changeNotes,
        bool $dryRun = false,
    ): array {
        return $this->restore(
            $screener,
            $missingVersion,
            $expectedHash,
            $changeNotes,
            fn (Screener $locked) => $this->payloadFromArtifact($locked, $proofArtifactVersionId, $expectedHash),
            $dryRun,
        );
    }

    private function restore(
        Screener $screener,
        int $missingVersion,
        string $expectedHash,
        string $changeNotes,
        callable $proofPayload,
        bool $dryRun,
    ): array {
        return DB::transaction(function () use ($screener, $missingVersion, $expectedHash, $changeNotes, $proofPayload, $dryRun) {
            $locked = Screener::query()->lockForUpdate()->findOrFail($screener->id);
            if ($missingVersion < 1 || $missingVersion > (int) $locked->artifact_version) {
                throw new RuntimeException('Repair target must be a missing version at or below the current Screener version.');
            }
            if (trim($expectedHash) === '' || trim($changeNotes) === '') {
                throw new RuntimeException('An expected semantic hash and audit change note are required.');
            }

            $payload = $this->semanticPayload($locked);
            $actualHash = DefinitionHasher::hash($payload);
            if (! hash_equals($expectedHash, $actualHash)) {
                throw new RuntimeException('Current Screener semantic hash does not match the approved repair hash.');
            }

            $existing = ScreenerVersion::query()
                ->where('screener_id', $locked->id)
                ->where('version', $missingVersion)
                ->first();
            if ($existing) {
                if ($existing->definition_hash !== $expectedHash
                    || DefinitionHasher::hash($this->semanticPayloadFromSnapshot($existing)) !== $expectedHash) {
                    throw new RuntimeException('Target version already exists with conflicting immutable content; no changes made.');
                }

                return ['created' => false, 'version' => $existing, 'dry_run' => $dryRun];
            }

            $proof = $proofPayload($locked);
            if (! is_array($proof) || DefinitionHasher::hash($proof) !== $expectedHash) {
                throw new RuntimeException('Immutable proof does not exactly match the approved current semantic definition.');
            }

            if ($dryRun) {
                return ['created' => false, 'version' => null, 'dry_run' => true];
            }

            $version = ScreenerVersion::query()->create([
                'screener_id' => $locked->id,
                'version' => $missingVersion,
                'definition_json' => $payload['definition'],
                'scope' => $payload['scope'],
                'watchlist_id' => $payload['watchlist_id'],
                'index_symbol' => $payload['index_symbol'],
                'metadata_json' => [
                    'name' => $locked->name,
                    'slug' => $locked->slug,
                    'intent' => $locked->intent,
                    'summary' => $locked->summary,
                    'tags' => $locked->tags_json,
                    'is_shared' => (bool) $locked->is_shared,
                    'is_factory' => (bool) $locked->is_factory,
                    'reconstructed' => true,
                    'proof' => 'exact immutable source plus approved semantic hash',
                ],
                'definition_hash' => $expectedHash,
                'change_notes' => mb_substr($changeNotes, 0, 1000),
            ]);

            return ['created' => true, 'version' => $version, 'dry_run' => false];
        });
    }

    private function payloadFromVersion(Screener $screener, int $proofVersion, string $expectedHash): array
    {
        if ($proofVersion <= 0) {
            throw new RuntimeException('A later immutable Screener version is required as proof.');
        }
        $source = ScreenerVersion::query()
            ->where('screener_id', $screener->id)
            ->where('version', $proofVersion)
            ->first();
        if (! $source || $source->definition_hash !== $expectedHash) {
            throw new RuntimeException('Immutable source Screener version is missing or has a different hash.');
        }

        return $this->semanticPayloadFromSnapshot($source);
    }

    private function payloadFromArtifact(Screener $screener, int $proofArtifactVersionId, string $expectedHash): array
    {
        $source = ReusableArtifactVersion::query()->with('artifact')->find($proofArtifactVersionId);
        if (! $source
            || $source->status !== ReusableArtifactVersion::STATUS_PUBLISHED
            || ! $source->artifact
            || $source->artifact->artifact_type !== ArtifactType::SCREENER
            || (int) $source->artifact_id !== (int) $screener->reusable_artifact_id) {
            throw new RuntimeException('Proof must be a published Screener artifact version in the same lineage.');
        }

        $content = is_array($source->content_json) ? $source->content_json : [];
        $definition = $content['definition'] ?? null;
        $universe = $content['metadata']['universe'] ?? $content['metadata']['scope'] ?? null;
        $scope = match ($universe) {
            'all', 'all_active_equities', 'all_equities' => 'all_equities',
            'portfolio', 'holding', 'holdings' => 'holdings',
            'watchlist' => 'watchlist',
            'index' => 'index',
            default => null,
        };
        if (! is_array($definition) || $scope === null) {
            throw new RuntimeException('Published artifact does not specify a recognized definition and universe.');
        }

        $payload = [
            'definition' => $this->normalizeDefinition($definition),
            'scope' => $scope,
            'watchlist_id' => $scope === 'watchlist' ? ($content['metadata']['watchlist_id'] ?? null) : null,
            'index_symbol' => $scope === 'index' ? ($content['metadata']['index_symbol'] ?? null) : null,
        ];
        if (DefinitionHasher::hash($payload) !== $expectedHash) {
            throw new RuntimeException('Published artifact semantic content does not match the approved repair hash.');
        }

        return $payload;
    }

    private function semanticPayload(Screener $screener): array
    {
        return [
            'definition' => $this->normalizeDefinition(is_array($screener->definition_json) ? $screener->definition_json : []),
            'scope' => (string) ($screener->scope ?? 'holdings'),
            'watchlist_id' => $screener->watchlist_id,
            'index_symbol' => $screener->index_symbol,
        ];
    }

    private function semanticPayloadFromSnapshot(ScreenerVersion $version): array
    {
        return [
            'definition' => $this->normalizeDefinition(is_array($version->definition_json) ? $version->definition_json : []),
            'scope' => (string) ($version->scope ?? 'holdings'),
            'watchlist_id' => $version->watchlist_id,
            'index_symbol' => $version->index_symbol,
        ];
    }

    private function normalizeDefinition(array $definition): array
    {
        return isset($definition['root']) ? $definition : ['root' => $definition];
    }
}
