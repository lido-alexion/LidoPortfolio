<?php

namespace App\Services\Screener;

use App\Models\Screener;
use App\Models\ScreenerVersion;
use App\Services\Artifacts\DefinitionHasher;
use Illuminate\Support\Str;

/**
 * Snapshot Screener definition versions for the Screener Artifact Registry.
 * Does not affect Screener execution.
 */
final class ScreenerVersioningService
{
    /**
     * After create: ensure registry columns + v1 snapshot.
     */
    public function afterCreate(Screener $screener, ?string $changeNotes = null): Screener
    {
        $definition = $this->normalizedDefinition($screener);
        $hash = DefinitionHasher::hash($definition);

        if ($screener->slug === null || $screener->slug === '') {
            $screener->slug = $this->uniqueSlug($screener);
        }
        $screener->artifact_version = max(1, (int) ($screener->artifact_version ?? 1));
        $screener->definition_hash = $hash;
        $screener->artifact_status = $screener->artifact_status ?: ($screener->is_enabled ? 'active' : 'draft');
        $screener->save();

        if (! ScreenerVersion::query()->where('screener_id', $screener->id)->where('version', 1)->exists()) {
            $this->writeVersionRow($screener, 1, $definition, $hash, $changeNotes ?? 'Initial registry version');
        }

        return $screener->fresh();
    }

    /**
     * After update: bump version only when definition hash changes.
     */
    public function afterUpdate(Screener $screener, ?string $previousHash, ?string $changeNotes = null): Screener
    {
        $definition = $this->normalizedDefinition($screener);
        $hash = DefinitionHasher::hash($definition);

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
            $this->writeVersionRow($screener, 1, $definition, $hash, $changeNotes ?? 'Initial registry version');

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
                $definition,
                $hash,
                $changeNotes ?? 'Definition updated'
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
    private function normalizedDefinition(Screener $screener): array
    {
        $definition = is_array($screener->definition_json) ? $screener->definition_json : [];
        if (! isset($definition['root'])) {
            $definition = ['root' => $definition];
        }

        return $definition;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function writeVersionRow(Screener $screener, int $version, array $definition, string $hash, string $notes): void
    {
        ScreenerVersion::query()->create([
            'screener_id' => $screener->id,
            'version' => $version,
            'definition_json' => $definition,
            'metadata_json' => [
                'name' => $screener->name,
                'slug' => $screener->slug,
                'intent' => $screener->intent,
                'summary' => $screener->summary,
                'tags' => $screener->tags_json,
                'is_shared' => (bool) $screener->is_shared,
                'is_factory' => (bool) $screener->is_factory,
            ],
            'definition_hash' => $hash,
            'change_notes' => Str::limit($notes, 1000, ''),
        ]);
    }
}
