<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KnowledgeNote;
use App\Models\WikiPage;
use App\Services\Wiki\WikiPageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KnowledgeBoardSearchController extends Controller
{
    public function __construct(private WikiPageService $wiki) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:1', 'max:200']]);
        $profile = \activePortfolio();
        $like = '%'.addcslashes(trim($data['q']), '%_\\').'%';

        $notes = KnowledgeNote::query()->where('profile_id', $profile->id)->where('is_archived', false)
            ->where(fn ($query) => $query->where('title', 'like', $like)->orWhere('content_html', 'like', $like))
            ->limit(50)->get()->map(fn (KnowledgeNote $note): array => [
                'type' => 'note', 'id' => $note->id, 'title' => $note->title,
                'context' => 'Knowledge Board / Notes',
                'excerpt' => $this->excerpt(strip_tags((string) $note->content_html)),
            ]);
        $pages = WikiPage::query()->where('profile_id', $profile->id)
            ->where(fn ($query) => $query->where('title', 'like', $like)->orWhere('markdown', 'like', $like))
            ->limit(50)->get()->map(fn (WikiPage $page): array => [
                'type' => 'wiki_page', 'id' => $page->uuid, 'title' => $page->title,
                'context' => $this->wiki->hierarchyPath($page, $profile),
                'excerpt' => $this->excerpt($page->markdown),
            ]);

        return response()->json(['data' => $notes->merge($pages)->sortBy([
            ['type', 'asc'], ['title', 'asc'], ['id', 'asc'],
        ])->values()]);
    }

    private function excerpt(string $content): string
    {
        return mb_substr(trim(preg_replace('/\s+/u', ' ', $content) ?? ''), 0, 240);
    }
}
