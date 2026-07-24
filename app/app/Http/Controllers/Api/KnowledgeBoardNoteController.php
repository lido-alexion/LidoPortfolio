<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KnowledgeNote;
use App\Models\KnowledgeTag;
use App\Services\KnowledgeBoardNoteService;
use App\Services\KnowledgeNotePaletteCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KnowledgeBoardNoteController extends Controller
{
    public function __construct(protected KnowledgeBoardNoteService $notes) {}

    public function palettes(): JsonResponse
    {
        return response()->json([
            'data' => KnowledgeNotePaletteCatalog::all(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $profile = \activePortfolio();

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'tag_ids' => ['nullable', 'array'],
            'tag_ids.*' => ['integer'],
            'tag_match' => ['nullable', 'string', 'in:any,all,exclude'],
            'sort' => ['nullable', 'string', 'in:updated_at,created_at,title,pinned_first'],
        ]);

        $data = $this->notes->listForProfile($profile, [
            'q' => $validated['q'] ?? '',
            'archived' => $request->boolean('archived'),
            'tag_ids' => $validated['tag_ids'] ?? [],
            'tag_match' => $validated['tag_match'] ?? 'any',
            'sort' => $validated['sort'] ?? 'updated_at',
        ]);

        return response()->json(['data' => $data->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $profile = \activePortfolio();

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'content_html' => ['nullable', 'string'],
            'content_json' => ['nullable', 'array'],
            'is_pinned' => ['sometimes', 'boolean'],
            'is_favorite' => ['sometimes', 'boolean'],
            'is_archived' => ['sometimes', 'boolean'],
            'color_palette' => $this->notes->paletteRule(),
            'tag_ids' => ['nullable', 'array'],
            'tag_ids.*' => ['integer'],
        ]);

        $note = $this->notes->create($profile, $validated);

        return response()->json(['data' => $note], 201);
    }

    public function show(KnowledgeNote $knowledgeNote): JsonResponse
    {
        $profile = \activePortfolio();
        $this->notes->assertNoteBelongsToProfile($knowledgeNote, $profile);

        return response()->json([
            'data' => $this->notes->formatNote($knowledgeNote->load('tags')),
        ]);
    }

    public function update(Request $request, KnowledgeNote $knowledgeNote): JsonResponse
    {
        $profile = \activePortfolio();

        $validated = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'content_html' => ['nullable', 'string'],
            'content_json' => ['nullable', 'array'],
            'is_pinned' => ['sometimes', 'boolean'],
            'is_favorite' => ['sometimes', 'boolean'],
            'is_archived' => ['sometimes', 'boolean'],
            'color_palette' => $this->notes->paletteRule(),
            'tag_ids' => ['nullable', 'array'],
            'tag_ids.*' => ['integer'],
        ]);

        $note = $this->notes->update($knowledgeNote, $profile, $validated);

        return response()->json(['data' => $note]);
    }

    public function destroy(KnowledgeNote $knowledgeNote): JsonResponse
    {
        $profile = \activePortfolio();
        $this->notes->delete($knowledgeNote, $profile);

        return response()->json(['message' => 'Note deleted.']);
    }

    public function duplicate(KnowledgeNote $knowledgeNote): JsonResponse
    {
        $profile = \activePortfolio();
        $note = $this->notes->duplicate($knowledgeNote, $profile);

        return response()->json(['data' => $note], 201);
    }

    public function bulk(Request $request): JsonResponse
    {
        $profile = \activePortfolio();

        $validated = $request->validate([
            'action' => ['required', 'string', 'in:archive,delete'],
            'note_ids' => ['required', 'array', 'min:1'],
            'note_ids.*' => ['integer'],
        ]);

        $result = $this->notes->bulkAction($profile, $validated['note_ids'], $validated['action']);

        return response()->json(['data' => $result]);
    }
}
