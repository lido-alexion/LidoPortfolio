<?php

namespace App\Services;

use App\Models\KnowledgeNote;
use App\Models\KnowledgeTag;
use App\Models\PortfolioProfile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class KnowledgeBoardNoteService
{
    public function __construct(
        protected KnowledgeBoardTagService $tags,
    ) {}

    /**
     * @param  array{
     *     q?: string,
     *     archived?: bool,
     *     tag_ids?: list<int>,
     *     tag_match?: string,
     *     sort?: string
     * }  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function listForProfile(PortfolioProfile $profile, array $filters = []): Collection
    {
        $query = KnowledgeNote::query()
            ->with('tags')
            ->where('profile_id', $profile->id);

        $archived = filter_var($filters['archived'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $query->where('is_archived', $archived);

        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(function ($builder) use ($like) {
                $builder->where('title', 'like', $like)
                    ->orWhere('content_html', 'like', $like)
                    ->orWhereHas('tags', fn ($tagQuery) => $tagQuery->where('name', 'like', $like));
            });
        }

        $tagIds = array_values(array_filter(array_map('intval', $filters['tag_ids'] ?? [])));
        $tagMatch = $filters['tag_match'] ?? 'any';

        if ($tagIds !== []) {
            if ($tagMatch === 'all') {
                foreach ($tagIds as $tagId) {
                    $query->whereHas('tags', fn ($tagQuery) => $tagQuery->where('portfolio_knowledge_tags.id', $tagId));
                }
            } elseif ($tagMatch === 'exclude') {
                $query->whereDoesntHave('tags', fn ($tagQuery) => $tagQuery->whereIn('portfolio_knowledge_tags.id', $tagIds));
            } else {
                $query->whereHas('tags', fn ($tagQuery) => $tagQuery->whereIn('portfolio_knowledge_tags.id', $tagIds));
            }
        }

        $sort = $filters['sort'] ?? 'updated_at';
        if ($sort === 'pinned_first') {
            $query->orderByDesc('is_pinned')->orderByDesc('updated_at');
        } elseif ($sort === 'title') {
            $query->orderBy('title');
        } elseif ($sort === 'created_at') {
            $query->orderByDesc('created_at');
        } else {
            $query->orderByDesc('updated_at');
        }

        return $query->get()->map(fn (KnowledgeNote $note) => $this->formatNote($note));
    }

    public function findForProfile(PortfolioProfile $profile, int $noteId): ?KnowledgeNote
    {
        return KnowledgeNote::query()
            ->with('tags')
            ->where('profile_id', $profile->id)
            ->whereKey($noteId)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(PortfolioProfile $profile, array $data): array
    {
        $note = KnowledgeNote::query()->create([
            'profile_id' => $profile->id,
            'title' => $this->resolveTitle($data),
            'content_html' => $this->normalizeHtml($data['content_html'] ?? null),
            'content_json' => $data['content_json'] ?? null,
            'is_pinned' => (bool) ($data['is_pinned'] ?? false),
            'is_favorite' => (bool) ($data['is_favorite'] ?? false),
            'is_archived' => (bool) ($data['is_archived'] ?? false),
        ]);

        $this->syncTags($profile, $note, $data['tag_ids'] ?? []);

        return $this->formatNote($note->fresh('tags'));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(KnowledgeNote $note, PortfolioProfile $profile, array $data): array
    {
        $this->assertNoteBelongsToProfile($note, $profile);

        if (array_key_exists('title', $data)) {
            $note->title = $this->resolveTitle($data);
        } elseif (array_key_exists('content_html', $data)) {
            $note->title = $this->resolveTitle([
                'title' => '',
                'content_html' => $data['content_html'],
            ]);
        }
        if (array_key_exists('content_html', $data)) {
            $note->content_html = $this->normalizeHtml($data['content_html']);
        }
        if (array_key_exists('content_json', $data)) {
            $note->content_json = $data['content_json'];
        }
        foreach (['is_pinned', 'is_favorite', 'is_archived'] as $flag) {
            if (array_key_exists($flag, $data)) {
                $note->{$flag} = (bool) $data[$flag];
            }
        }

        $note->save();

        if (array_key_exists('tag_ids', $data)) {
            $this->syncTags($profile, $note, $data['tag_ids'] ?? []);
        }

        return $this->formatNote($note->fresh('tags'));
    }

    public function delete(KnowledgeNote $note, PortfolioProfile $profile): void
    {
        $this->assertNoteBelongsToProfile($note, $profile);
        $note->delete();
    }

    public function duplicate(KnowledgeNote $note, PortfolioProfile $profile): array
    {
        $this->assertNoteBelongsToProfile($note, $profile);

        $copy = KnowledgeNote::query()->create([
            'profile_id' => $profile->id,
            'title' => $this->truncateTitle($note->title.' (copy)'),
            'content_html' => $note->content_html,
            'content_json' => $note->content_json,
            'is_pinned' => false,
            'is_favorite' => $note->is_favorite,
            'is_archived' => false,
        ]);

        $copy->tags()->sync($note->tags()->pluck('portfolio_knowledge_tags.id'));

        return $this->formatNote($copy->fresh('tags'));
    }

    /**
     * @param  list<int>  $noteIds
     * @return array{archived?: int, deleted?: int}
     */
    public function bulkAction(PortfolioProfile $profile, array $noteIds, string $action): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $noteIds))));
        if ($ids === []) {
            return [];
        }

        $notes = KnowledgeNote::query()
            ->where('profile_id', $profile->id)
            ->whereIn('id', $ids)
            ->get();

        if ($action === 'archive') {
            $count = KnowledgeNote::query()
                ->where('profile_id', $profile->id)
                ->whereIn('id', $ids)
                ->update(['is_archived' => true]);

            return ['archived' => $count];
        }

        if ($action === 'delete') {
            $count = $notes->count();
            KnowledgeNote::query()
                ->where('profile_id', $profile->id)
                ->whereIn('id', $ids)
                ->delete();

            return ['deleted' => $count];
        }

        throw ValidationException::withMessages([
            'action' => ['Unsupported bulk action.'],
        ]);
    }

    public function assertNoteBelongsToProfile(KnowledgeNote $note, PortfolioProfile $profile): void
    {
        if ((int) $note->profile_id !== (int) $profile->id) {
            abort(404);
        }
    }

    /**
     * @param  list<int>  $tagIds
     */
    protected function syncTags(PortfolioProfile $profile, KnowledgeNote $note, array $tagIds): void
    {
        $validIds = KnowledgeTag::query()
            ->where('profile_id', $profile->id)
            ->whereIn('id', $tagIds)
            ->pluck('id')
            ->all();

        $note->tags()->sync($validIds);
    }

    protected function resolveTitle(array $data): string
    {
        $title = trim((string) ($data['title'] ?? ''));
        if ($title !== '') {
            return $this->truncateTitle($title);
        }

        $plain = trim(strip_tags((string) ($data['content_html'] ?? '')));
        if ($plain === '') {
            throw ValidationException::withMessages([
                'content_html' => ['Note content is required.'],
            ]);
        }

        $firstLine = trim((string) preg_split('/\R/u', $plain, 2)[0]);

        return $this->truncateTitle($firstLine !== '' ? $firstLine : 'Untitled note');
    }

    protected function normalizeTitle(string $title): string
    {
        $trimmed = trim($title);
        if ($trimmed === '') {
            throw ValidationException::withMessages([
                'title' => ['Title is required.'],
            ]);
        }

        return $this->truncateTitle($trimmed);
    }

    protected function truncateTitle(string $title): string
    {
        return mb_substr($title, 0, 255);
    }

    protected function normalizeHtml(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        $trimmed = trim($html);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return array<string, mixed>
     */
    public function formatNote(KnowledgeNote $note): array
    {
        $note->loadMissing('tags');

        return [
            'id' => $note->id,
            'title' => $note->title,
            'content_html' => $note->content_html,
            'content_json' => $note->content_json,
            'is_pinned' => $note->is_pinned,
            'is_favorite' => $note->is_favorite,
            'is_archived' => $note->is_archived,
            'created_at' => $note->created_at?->toIso8601String(),
            'updated_at' => $note->updated_at?->toIso8601String(),
            'tags' => $note->tags->map(fn (KnowledgeTag $tag) => $this->tags->formatTag($tag))->values()->all(),
        ];
    }
}
