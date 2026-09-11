<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Wiki\WikiPageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WikiPageController extends Controller
{
    public function __construct(private WikiPageService $pages) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->pages->tree(\activePortfolio())]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'markdown' => ['nullable', 'string'],
            'parent_uuid' => ['nullable', 'uuid'],
        ]);

        return response()->json(['data' => $this->pages->create(\activePortfolio(), $request->user(), $data)], 201);
    }

    public function show(string $page): JsonResponse
    {
        $page = $this->pages->find(\activePortfolio(), $page);

        return response()->json(['data' => $this->pages->detail($page, \activePortfolio())]);
    }

    public function update(Request $request, string $page): JsonResponse
    {
        $data = $request->validate(['title' => ['sometimes', 'required', 'string', 'max:255'], 'markdown' => ['sometimes', 'string']]);

        return response()->json(['data' => $this->pages->update($this->pages->find(\activePortfolio(), $page), \activePortfolio(), $request->user(), $data)]);
    }

    public function move(Request $request, string $page): JsonResponse
    {
        $data = $request->validate(['parent_uuid' => ['nullable', 'uuid'], 'display_order' => ['required', 'integer', 'min:0']]);

        return response()->json(['data' => $this->pages->move($this->pages->find(\activePortfolio(), $page), \activePortfolio(), $request->user(), $data['parent_uuid'] ?? null, $data['display_order'])]);
    }

    public function revision(string $page, int $revision): JsonResponse
    {
        return response()->json(['data' => $this->pages->revision($this->pages->find(\activePortfolio(), $page), \activePortfolio(), $revision)]);
    }

    public function restore(Request $request, string $page, int $revision): JsonResponse
    {
        return response()->json(['data' => $this->pages->restore($this->pages->find(\activePortfolio(), $page), \activePortfolio(), $request->user(), $revision)]);
    }

    public function destroy(Request $request, string $page): JsonResponse
    {
        $data = $request->validate(['recursive' => ['sometimes', 'boolean'], 'confirm_count' => ['nullable', 'integer', 'min:1']]);
        $count = $this->pages->delete($this->pages->find(\activePortfolio(), $page), \activePortfolio(), (bool) ($data['recursive'] ?? false), $data['confirm_count'] ?? null);

        return response()->json(['data' => ['deleted' => $count]]);
    }
}
