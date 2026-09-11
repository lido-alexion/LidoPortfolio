<?php

namespace App\Services\Wiki;

use App\Models\PortfolioProfile;
use App\Models\User;
use App\Models\WikiPage;
use App\Models\WikiPageRevision;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class WikiPageService
{
    public function __construct(private WikiMarkdownRenderer $renderer) {}

    public function find(PortfolioProfile $profile, string $uuid): WikiPage
    {
        return WikiPage::query()->where('profile_id', $profile->id)->where('uuid', $uuid)->firstOrFail();
    }

    public function tree(PortfolioProfile $profile): Collection
    {
        $pages = WikiPage::query()->where('profile_id', $profile->id)
            ->orderBy('display_order')->orderBy('id')->get();

        return $this->treeLevel($pages, null);
    }

    public function detail(WikiPage $page, PortfolioProfile $profile): array
    {
        $this->assertOwned($page, $profile);

        return [
            ...$page->toArray(),
            'rendered_html' => $this->renderer->render($profile, $page->markdown, false, $page),
            'breadcrumbs' => $this->breadcrumbs($page),
            'revisions' => $page->revisions()->get(),
        ];
    }

    public function attachImage(WikiPage $page, PortfolioProfile $profile, string $imageUuid): WikiPage
    {
        $this->assertOwned($page, $profile);
        $image = $profile->knowledgeImages()->where('uuid', $imageUuid)->firstOrFail();
        $page->images()->syncWithoutDetaching([$image->id]);

        return $page->fresh('images');
    }

    public function hierarchyPath(WikiPage $page, PortfolioProfile $profile): string
    {
        $this->assertOwned($page, $profile);

        return collect($this->breadcrumbs($page))->pluck('title')->implode(' / ');
    }

    public function preview(WikiPage $page, PortfolioProfile $profile, string $markdown): string
    {
        $this->assertOwned($page, $profile);

        return $this->renderer->render($profile, $markdown, false, $page);
    }

    public function create(PortfolioProfile $profile, User $user, array $data): WikiPage
    {
        return DB::transaction(function () use ($profile, $user, $data): WikiPage {
            $parent = $this->validatedParent($profile, $data['parent_uuid'] ?? null);
            $page = WikiPage::query()->create([
                'profile_id' => $profile->id,
                'parent_id' => $parent?->id,
                'uuid' => (string) Str::uuid(),
                'title' => trim($data['title']),
                'slug' => $this->slug($data['title']),
                'markdown' => (string) ($data['markdown'] ?? ''),
                'display_order' => $this->nextOrder($profile, $parent?->id),
            ]);
            $this->revise($page, $user, 'created');

            return $page->fresh();
        });
    }

    public function update(WikiPage $page, PortfolioProfile $profile, User $user, array $data): WikiPage
    {
        $this->assertOwned($page, $profile);

        return DB::transaction(function () use ($page, $user, $data): WikiPage {
            $page = WikiPage::query()->lockForUpdate()->findOrFail($page->id);
            if (array_key_exists('title', $data)) {
                $page->title = trim($data['title']);
                $page->slug = $this->slug($data['title']);
            }
            if (array_key_exists('markdown', $data)) {
                $page->markdown = (string) $data['markdown'];
            }
            if (! $page->isDirty()) {
                return $page;
            }
            $page->save();
            $this->revise($page, $user, 'updated');

            return $page->fresh();
        });
    }

    public function move(WikiPage $page, PortfolioProfile $profile, User $user, ?string $parentUuid, int $displayOrder): WikiPage
    {
        $this->assertOwned($page, $profile);

        return DB::transaction(function () use ($page, $profile, $user, $parentUuid, $displayOrder): WikiPage {
            $page = WikiPage::query()->lockForUpdate()->findOrFail($page->id);
            $parent = $this->validatedParent($profile, $parentUuid);
            if ($parent && ($parent->is($page) || $this->ancestorIds($parent)->contains($page->id))) {
                throw ValidationException::withMessages(['parent_uuid' => ['A Wiki Page cannot become its own ancestor.']]);
            }
            $siblings = WikiPage::query()->where('profile_id', $profile->id)->where('parent_id', $parent?->id)
                ->whereKeyNot($page->id)->orderBy('display_order')->orderBy('id')->get()->values();
            $position = min(max(0, $displayOrder), $siblings->count());
            $siblings->splice($position, 0, [$page]);
            foreach ($siblings as $order => $sibling) {
                $sibling->forceFill(['parent_id' => $parent?->id, 'display_order' => $order])->saveQuietly();
            }
            $page->refresh();
            $this->revise($page, $user, 'moved');

            return $page->fresh();
        });
    }

    public function revision(WikiPage $page, PortfolioProfile $profile, int $revisionId): array
    {
        $this->assertOwned($page, $profile);
        $revision = $page->revisions()->whereKey($revisionId)->firstOrFail();

        return [
            'revision' => $revision,
            'current' => ['title' => $page->title, 'slug' => $page->slug, 'markdown' => $page->markdown, 'parent_id' => $page->parent_id, 'display_order' => $page->display_order],
            'changed' => [
                'title' => $revision->title !== $page->title,
                'markdown' => $revision->markdown !== $page->markdown,
                'hierarchy' => (int) $revision->parent_id !== (int) $page->parent_id || (int) $revision->display_order !== (int) $page->display_order,
            ],
        ];
    }

    public function restore(WikiPage $page, PortfolioProfile $profile, User $user, int $revisionId): WikiPage
    {
        $this->assertOwned($page, $profile);

        return DB::transaction(function () use ($page, $profile, $user, $revisionId): WikiPage {
            $page = WikiPage::query()->lockForUpdate()->findOrFail($page->id);
            $revision = WikiPageRevision::query()->where('page_id', $page->id)->whereKey($revisionId)->firstOrFail();
            $parent = $revision->parent_id ? WikiPage::query()->where('profile_id', $profile->id)->find($revision->parent_id) : null;
            if ($parent && ($parent->is($page) || $this->ancestorIds($parent)->contains($page->id))) {
                throw ValidationException::withMessages(['revision' => ['This revision cannot be restored because its historical parent would create a cycle.']]);
            }
            $page->forceFill([
                'title' => $revision->title, 'slug' => $revision->slug, 'markdown' => $revision->markdown,
                'parent_id' => $parent?->id, 'display_order' => $revision->display_order,
            ])->save();
            $this->revise($page, $user, 'restored');

            return $page->fresh();
        });
    }

    public function delete(WikiPage $page, PortfolioProfile $profile, bool $recursive, ?int $confirmedCount): int
    {
        $this->assertOwned($page, $profile);
        $ids = $this->descendantIds($page);
        $count = $ids->count() + 1;
        if ($count > 1 && (! $recursive || $confirmedCount !== $count)) {
            throw ValidationException::withMessages([
                'confirm_count' => ["This branch contains {$count} pages. Confirm that exact count to delete it recursively."],
            ]);
        }

        DB::transaction(function () use ($page, $ids): void {
            $ids->reverse()->each(fn (int $id) => WikiPage::query()->findOrFail($id)->delete());
            $page->delete();
        });

        return $count;
    }

    private function revise(WikiPage $page, User $user, string $changeType): void
    {
        $number = (int) WikiPageRevision::query()->where('page_id', $page->id)->max('revision_number') + 1;
        WikiPageRevision::query()->create([
            'page_id' => $page->id, 'user_id' => $user->id, 'revision_number' => $number,
            'change_type' => $changeType, 'title' => $page->title, 'slug' => $page->slug,
            'parent_id' => $page->parent_id, 'display_order' => $page->display_order,
            'markdown' => $page->markdown, 'created_at' => now(),
        ]);
    }

    private function validatedParent(PortfolioProfile $profile, ?string $uuid): ?WikiPage
    {
        return $uuid ? $this->find($profile, $uuid) : null;
    }

    private function nextOrder(PortfolioProfile $profile, ?int $parentId): int
    {
        return (int) WikiPage::query()->where('profile_id', $profile->id)->where('parent_id', $parentId)->max('display_order') + 1;
    }

    private function ancestorIds(WikiPage $page): Collection
    {
        $ids = collect();
        while ($page->parent_id !== null && $ids->count() < 100) {
            $page = WikiPage::query()->findOrFail($page->parent_id);
            $ids->push($page->id);
        }

        return $ids;
    }

    private function breadcrumbs(WikiPage $page): array
    {
        $crumbs = [['title' => 'Knowledge Board', 'uuid' => null]];
        $ancestors = collect();
        $cursor = $page;
        while ($cursor->parent_id !== null && $ancestors->count() < 100) {
            $cursor = WikiPage::query()->findOrFail($cursor->parent_id);
            $ancestors->prepend(['title' => $cursor->title, 'uuid' => $cursor->uuid]);
        }

        return collect($crumbs)->merge($ancestors)->push(['title' => $page->title, 'uuid' => $page->uuid])->all();
    }

    private function descendantIds(WikiPage $page): Collection
    {
        $ids = collect();
        $frontier = collect([$page->id]);
        while ($frontier->isNotEmpty() && $ids->count() < 10000) {
            $children = WikiPage::query()->whereIn('parent_id', $frontier)->pluck('id');
            $ids = $ids->merge($children);
            $frontier = $children;
        }

        return $ids;
    }

    private function treeLevel(Collection $pages, ?int $parentId): Collection
    {
        return $pages->where('parent_id', $parentId)->values()->map(fn (WikiPage $page): array => [
            'uuid' => $page->uuid, 'title' => $page->title, 'slug' => $page->slug,
            'parent_uuid' => $page->parent_id ? $pages->firstWhere('id', $page->parent_id)?->uuid : null,
            'display_order' => $page->display_order, 'children' => $this->treeLevel($pages, $page->id),
        ]);
    }

    private function slug(string $title): string
    {
        return Str::slug($title) ?: 'page';
    }

    private function assertOwned(WikiPage $page, PortfolioProfile $profile): void
    {
        abort_unless((int) $page->profile_id === (int) $profile->id, 404);
    }
}
