<?php

namespace App\Services;

use App\Models\KnowledgeTag;
use App\Models\PortfolioProfile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class KnowledgeBoardTagService
{
    public const DEFAULT_COLORS = [
        '#0d6efd',
        '#198754',
        '#dc3545',
        '#fd7e14',
        '#6f42c1',
        '#20c997',
        '#6c757d',
        '#d63384',
    ];

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function listForProfile(PortfolioProfile $profile): Collection
    {
        return KnowledgeTag::query()
            ->where('profile_id', $profile->id)
            ->orderBy('name')
            ->get()
            ->map(fn (KnowledgeTag $tag) => $this->formatTag($tag));
    }

    public function findForProfile(PortfolioProfile $profile, int $tagId): ?KnowledgeTag
    {
        return KnowledgeTag::query()
            ->where('profile_id', $profile->id)
            ->whereKey($tagId)
            ->first();
    }

    public function create(PortfolioProfile $profile, string $name, ?string $color = null): array
    {
        $normalized = $this->normalizeName($name);
        $this->assertNameUnique($profile, $normalized);

        $tag = KnowledgeTag::query()->create([
            'profile_id' => $profile->id,
            'name' => $normalized,
            'color' => $this->normalizeColor($color),
        ]);

        return $this->formatTag($tag);
    }

    public function update(KnowledgeTag $tag, PortfolioProfile $profile, string $name, ?string $color = null): array
    {
        $this->assertTagBelongsToProfile($tag, $profile);

        $normalized = $this->normalizeName($name);
        if (strcasecmp($normalized, $tag->name) !== 0) {
            $this->assertNameUnique($profile, $normalized, $tag->id);
        }

        $tag->name = $normalized;
        if ($color !== null) {
            $tag->color = $this->normalizeColor($color);
        }
        $tag->save();

        return $this->formatTag($tag);
    }

    public function delete(KnowledgeTag $tag, PortfolioProfile $profile): void
    {
        $this->assertTagBelongsToProfile($tag, $profile);
        $tag->delete();
    }

    public function merge(PortfolioProfile $profile, KnowledgeTag $source, KnowledgeTag $target): array
    {
        $this->assertTagBelongsToProfile($source, $profile);
        $this->assertTagBelongsToProfile($target, $profile);

        if ($source->id === $target->id) {
            throw ValidationException::withMessages([
                'source_id' => ['Cannot merge a tag into itself.'],
            ]);
        }

        DB::transaction(function () use ($source, $target) {
            $noteIds = $source->notes()->pluck('portfolio_knowledge_notes.id');
            foreach ($noteIds as $noteId) {
                $target->notes()->syncWithoutDetaching([$noteId]);
            }
            $source->delete();
        });

        return $this->formatTag($target->fresh());
    }

    public function assertTagBelongsToProfile(KnowledgeTag $tag, PortfolioProfile $profile): void
    {
        if ((int) $tag->profile_id !== (int) $profile->id) {
            abort(404);
        }
    }

    protected function assertNameUnique(PortfolioProfile $profile, string $name, ?int $ignoreId = null): void
    {
        $exists = KnowledgeTag::query()
            ->where('profile_id', $profile->id)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'name' => ['A tag with this name already exists.'],
            ]);
        }
    }

    protected function normalizeName(string $name): string
    {
        $trimmed = trim($name);
        if ($trimmed === '') {
            throw ValidationException::withMessages([
                'name' => ['Tag name is required.'],
            ]);
        }

        return mb_substr($trimmed, 0, 64);
    }

    protected function normalizeColor(?string $color): string
    {
        if ($color === null || $color === '') {
            return self::DEFAULT_COLORS[0];
        }

        $color = trim($color);
        if (! preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            return self::DEFAULT_COLORS[0];
        }

        return $color;
    }

    /**
     * @return array<string, mixed>
     */
    public function formatTag(KnowledgeTag $tag): array
    {
        return [
            'id' => $tag->id,
            'name' => $tag->name,
            'color' => $tag->color,
            'created_at' => $tag->created_at?->toIso8601String(),
            'updated_at' => $tag->updated_at?->toIso8601String(),
        ];
    }
}
