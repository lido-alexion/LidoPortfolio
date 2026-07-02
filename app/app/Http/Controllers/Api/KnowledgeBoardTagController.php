<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KnowledgeTag;
use App\Services\KnowledgeBoardTagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KnowledgeBoardTagController extends Controller
{
    public function __construct(protected KnowledgeBoardTagService $tags) {}

    public function index(): JsonResponse
    {
        $profile = \activePortfolio();
        $data = $this->tags->listForProfile($profile);

        return response()->json(['data' => $data->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $profile = \activePortfolio();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'color' => ['nullable', 'string', 'max:7'],
        ]);

        $tag = $this->tags->create($profile, $validated['name'], $validated['color'] ?? null);

        return response()->json(['data' => $tag], 201);
    }

    public function update(Request $request, KnowledgeTag $knowledgeTag): JsonResponse
    {
        $profile = \activePortfolio();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'color' => ['nullable', 'string', 'max:7'],
        ]);

        $tag = $this->tags->update(
            $knowledgeTag,
            $profile,
            $validated['name'],
            $validated['color'] ?? null,
        );

        return response()->json(['data' => $tag]);
    }

    public function destroy(KnowledgeTag $knowledgeTag): JsonResponse
    {
        $profile = \activePortfolio();
        $this->tags->delete($knowledgeTag, $profile);

        return response()->json(['message' => 'Tag deleted.']);
    }

    public function merge(Request $request): JsonResponse
    {
        $profile = \activePortfolio();

        $validated = $request->validate([
            'source_id' => ['required', 'integer'],
            'target_id' => ['required', 'integer', 'different:source_id'],
        ]);

        $source = $this->tags->findForProfile($profile, (int) $validated['source_id']);
        $target = $this->tags->findForProfile($profile, (int) $validated['target_id']);

        if (! $source || ! $target) {
            abort(404);
        }

        $tag = $this->tags->merge($profile, $source, $target);

        return response()->json(['data' => $tag]);
    }
}
