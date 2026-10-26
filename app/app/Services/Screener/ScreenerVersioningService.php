<?php

namespace App\Services\Screener;

use App\Exceptions\DomainException;
use App\Models\Screener;
use App\Models\ScreenerVersion;
use App\Services\Artifacts\DefinitionHasher;
use App\Services\Artifacts\ScreenerRuntimeSelection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Snapshot Screener definition versions for the Screener Artifact Registry.
 * Supplies immutable semantic identity to Screener execution and historical audit.
 */
final class ScreenerVersioningService
{
    /**
     * Capture the selected immutable binding's effective semantics for a NEW run.
     * Never repair an older version, rewrite a conflicting snapshot, or persist
     * the potentially drifted mutable projection as the selected definition.
     */
    public function ensureArtifactRuntimeVersion(ScreenerRuntimeSelection $selection): ScreenerVersion
    {
        return $this->captureArtifactVersion($selection, false);
    }

    /** An explicit binding revision may adopt NEW semantics, never rewrite the old version. */
    public function captureArtifactBindingVersion(ScreenerRuntimeSelection $selection): ScreenerVersion
    {
        return $this->captureArtifactVersion($selection, true);
    }

    private function captureArtifactVersion(ScreenerRuntimeSelection $selection, bool $adoptNewSemantics): ScreenerVersion
    {
        return DB::transaction(function () use ($selection, $adoptNewSemantics) {
            $source = $selection->legacyScreener;
            $locked = Screener::query()->lockForUpdate()->findOrFail($source->id);
            if ((int) $locked->profile_id !== (int) $source->profile_id
                || (int) $locked->reusable_artifact_id !== (int) $selection->artifactVersion->artifact_id
                || (int) $locked->artifact_version !== (int) $source->artifact_version) {
                throw new DomainException('The Screener binding changed; refresh before starting a run.', 'SCREENER_RUNTIME_CHANGED');
            }
            $settings = $selection->bindingRevision->settings_json ?? [];
            $content = $selection->artifactVersion->content_json ?? [];
            $universe = (string) ($settings['scope'] ?? $content['metadata']['universe'] ?? $locked->scope);
            $scope = match ($universe) {
                'all', 'all_active_equities' => 'all_equities',
                'portfolio', 'holding' => 'holdings',
                default => $universe,
            };
            if (! in_array($scope, ScreenerCatalog::SCOPES, true)) {
                throw new DomainException('The bound Screener universe is invalid.', 'SCREENER_RUNTIME_SEMANTICS_INVALID');
            }
            $runtime = clone $locked;
            $runtime->forceFill([
                'definition_json' => $selection->definition,
                'scope' => $scope,
                'watchlist_id' => $scope === 'watchlist' ? ($settings['watchlist_id'] ?? $locked->watchlist_id) : null,
                'index_symbol' => $scope === 'index'
                    ? strtoupper(trim((string) ($settings['index_symbol'] ?? $locked->index_symbol))) : null,
            ]);
            $payload = $this->semanticPayload($runtime);
            $hash = DefinitionHasher::hash($payload);
            $number = max(1, (int) $locked->artifact_version);
            $version = ScreenerVersion::query()->where('screener_id', $locked->id)->where('version', $number)->first();
            if ($version) {
                $stored = clone $locked;
                $stored->forceFill([
                    'definition_json' => $version->definition_json, 'scope' => $version->scope,
                    'watchlist_id' => $version->watchlist_id, 'index_symbol' => $version->index_symbol,
                ]);
                $storedEvidenceComplete = in_array($version->scope, ScreenerCatalog::SCOPES, true)
                    && is_array($version->definition_json) && is_array($version->definition_json['root'] ?? null);
                if ($storedEvidenceComplete && $hash === DefinitionHasher::hash($this->semanticPayload($stored))) {
                    return $version;
                }
                if (! $adoptNewSemantics) {
                    throw new DomainException(
                        'The bound artifact and Screener semantic version disagree. Resolve the version before starting a run.',
                        'SCREENER_RUNTIME_SEMANTICS_CONFLICT',
                    );
                }
                $number = max($number, (int) ScreenerVersion::query()->where('screener_id', $locked->id)->max('version')) + 1;
            } elseif ($number < (int) ScreenerVersion::query()->where('screener_id', $locked->id)->max('version')) {
                if (! $adoptNewSemantics) {
                    throw new DomainException(
                        'A historical Screener version gap cannot be inferred from a new run.',
                        'SCREENER_RUNTIME_SEMANTICS_CONFLICT',
                    );
                }
                $number = ((int) ScreenerVersion::query()->where('screener_id', $locked->id)->max('version')) + 1;
            }
            $this->writeVersionRow($locked, $number, $payload, $hash,
                'Captured selected immutable artifact/binding semantics; historical rows preserved', [
                    'captured_for_runtime_at' => now()->toIso8601String(),
                    'reusable_artifact_version_id' => (int) $selection->artifactVersion->id,
                    'artifact_binding_revision_id' => (int) $selection->bindingRevision->id,
                ]);

            if ($number !== (int) $locked->artifact_version) {
                $locked->forceFill(['artifact_version' => $number])->save();
            }

            return ScreenerVersion::query()->where('screener_id', $locked->id)->where('version', $number)->firstOrFail();
        });
    }

    /** Resolve the current ordinary Screener semantic version. */
    public function ensureCurrentVersion(Screener $screener): ScreenerVersion
    {
        $versionNumber = max(1, (int) ($screener->artifact_version ?? 1));
        $existing = ScreenerVersion::query()
            ->where('screener_id', $screener->id)
            ->where('version', $versionNumber)
            ->first();
        if ($existing) {
            return $existing;
        }

        $this->afterUpdate($screener->fresh() ?? $screener, null, 'Backfilled semantic version snapshot');

        return ScreenerVersion::query()
            ->where('screener_id', $screener->id)
            ->where('version', max(1, (int) ($screener->fresh()?->artifact_version ?? $versionNumber)))
            ->firstOrFail();
    }

    public function afterCreate(Screener $screener, ?string $changeNotes = null): Screener
    {
        $payload = $this->semanticPayload($screener);
        $hash = DefinitionHasher::hash($payload);

        if ($screener->slug === null || $screener->slug === '') {
            $screener->slug = $this->uniqueSlug($screener);
        }
        $screener->artifact_version = max(1, (int) ($screener->artifact_version ?? 1));
        $screener->definition_hash = $hash;
        $screener->artifact_status = $screener->artifact_status ?: ($screener->is_enabled ? 'active' : 'draft');
        $screener->save();

        if (! ScreenerVersion::query()->where('screener_id', $screener->id)->where('version', 1)->exists()) {
            $this->writeVersionRow($screener, 1, $payload, $hash, $changeNotes ?? 'Initial registry version');
        }

        return $screener->fresh();
    }

    /**
     * After update: bump version only when definition hash changes.
     */
    public function afterUpdate(Screener $screener, ?string $previousHash, ?string $changeNotes = null): Screener
    {
        $payload = $this->semanticPayload($screener);
        $hash = DefinitionHasher::hash($payload);

        if ($screener->slug === null || $screener->slug === '') {
            $screener->slug = $this->uniqueSlug($screener);
        }

        $definitionChanged = $previousHash === null || $previousHash !== $hash;
        $hasAnyVersion = ScreenerVersion::query()->where('screener_id', $screener->id)->exists();

        if (! $hasAnyVersion) {
            $screener->artifact_version = 1;
            $screener->definition_hash = $hash;
            $screener->artifact_status = $screener->artifact_status ?: ($screener->is_enabled ? 'active' : 'draft');
            $screener->save();
            $this->writeVersionRow($screener, 1, $payload, $hash, $changeNotes ?? 'Initial registry version');

            return $screener->fresh();
        }

        if ($definitionChanged) {
            $next = max(1, (int) ($screener->artifact_version ?? 1) + 1);
            $screener->artifact_version = $next;
            $screener->definition_hash = $hash;
            $screener->artifact_status = $screener->artifact_status ?: ($screener->is_enabled ? 'active' : 'draft');
            $screener->save();
            $this->writeVersionRow(
                $screener,
                $next,
                $payload,
                $hash,
                $changeNotes ?? 'Semantic screener updated'
            );
        } else {
            $screener->definition_hash = $hash;
            $screener->artifact_status = $screener->artifact_status ?: ($screener->is_enabled ? 'active' : 'draft');
            $screener->save();
        }

        return $screener->fresh();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listVersions(Screener $screener): array
    {
        return ScreenerVersion::query()
            ->where('screener_id', $screener->id)
            ->orderByDesc('version')
            ->get()
            ->map(fn (ScreenerVersion $v) => [
                'version' => $v->version,
                'definition_hash' => $v->definition_hash,
                'change_notes' => $v->change_notes,
                'created_at' => optional($v->created_at)?->toIso8601String(),
                'metadata' => $v->metadata_json ?? [],
            ])
            ->all();
    }

    public function slugify(string $name, ?string $factoryKey = null, ?int $id = null): string
    {
        if ($factoryKey !== null && trim($factoryKey) !== '') {
            $slug = Str::slug(str_replace('-', '_', $factoryKey), '_');
            if ($slug !== '') {
                return Str::limit($slug, 100, '');
            }
        }
        $slug = Str::slug(str_replace('-', '_', $name), '_');
        if ($slug === '') {
            $slug = 'screener'.($id ? '_'.$id : '');
        }

        return Str::limit($slug, 100, '');
    }

    private function uniqueSlug(Screener $screener): string
    {
        $base = $this->slugify((string) $screener->name, $screener->factory_key, $screener->id);
        $slug = $base;
        $n = 2;
        while (
            Screener::query()
                ->where('profile_id', $screener->profile_id)
                ->where('slug', $slug)
                ->when($screener->id, fn ($q) => $q->where('id', '!=', $screener->id))
                ->exists()
        ) {
            $slug = Str::limit($base.'_'.$n, 100, '');
            $n++;
        }

        return $slug;
    }

    /**
     * @return array<string, mixed>
     */
    private function semanticPayload(Screener $screener): array
    {
        return [
            'definition' => $this->normalizedDefinition($screener),
            'scope' => (string) ($screener->scope ?? 'holdings'),
            'watchlist_id' => $screener->watchlist_id,
            'index_symbol' => $screener->index_symbol,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizedDefinition(Screener $screener): array
    {
        $definition = is_array($screener->definition_json) ? $screener->definition_json : [];
        if (! isset($definition['root'])) {
            $definition = ['root' => $definition];
        }

        return $definition;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeVersionRow(Screener $screener, int $version, array $payload, string $hash, string $notes, array $runtimeEvidence = []): void
    {
        $definition = $payload['definition'] ?? $payload;
        ScreenerVersion::query()->create([
            'screener_id' => $screener->id,
            'version' => $version,
            'definition_json' => $definition,
            'scope' => $payload['scope'] ?? $screener->scope,
            'watchlist_id' => $payload['watchlist_id'] ?? $screener->watchlist_id,
            'index_symbol' => $payload['index_symbol'] ?? $screener->index_symbol,
            'metadata_json' => array_merge([
                'name' => $screener->name,
                'slug' => $screener->slug,
                'intent' => $screener->intent,
                'summary' => $screener->summary,
                'tags' => $screener->tags_json,
                'is_shared' => (bool) $screener->is_shared,
                'is_factory' => (bool) $screener->is_factory,
            ], $runtimeEvidence),
            'definition_hash' => $hash,
            'change_notes' => Str::limit($notes, 1000, ''),
        ]);
    }
}
