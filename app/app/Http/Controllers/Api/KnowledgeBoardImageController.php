<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KnowledgeImage;
use App\Services\KnowledgeBoardImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class KnowledgeBoardImageController extends Controller
{
    public function __construct(protected KnowledgeBoardImageService $images) {}

    public function store(Request $request): JsonResponse
    {
        $profile = \activePortfolio();

        $validated = $request->validate([
            'display' => ['required', 'file', 'max:4096'],
            'full' => ['required', 'file', 'max:12288'],
            'original_name' => ['nullable', 'string', 'max:255'],
            'display_width' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'display_height' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'full_width' => ['nullable', 'integer', 'min:1', 'max:20000'],
            'full_height' => ['nullable', 'integer', 'min:1', 'max:20000'],
        ]);

        $payload = $this->images->store($profile, [
            'display' => $validated['display'],
            'full' => $validated['full'],
            'original_name' => $validated['original_name'] ?? null,
            'display_width' => $validated['display_width'] ?? null,
            'display_height' => $validated['display_height'] ?? null,
            'full_width' => $validated['full_width'] ?? null,
            'full_height' => $validated['full_height'] ?? null,
        ]);

        return response()->json(['data' => $payload], 201);
    }

    public function show(KnowledgeImage $knowledgeImage): BinaryFileResponse
    {
        $profile = \activePortfolio();
        $this->assertBelongs($knowledgeImage, $profile);

        return $this->images->respond($knowledgeImage, 'display');
    }

    public function full(KnowledgeImage $knowledgeImage): BinaryFileResponse
    {
        $profile = \activePortfolio();
        $this->assertBelongs($knowledgeImage, $profile);

        return $this->images->respond($knowledgeImage, 'full');
    }

    protected function assertBelongs(KnowledgeImage $image, $profile): void
    {
        if ((int) $image->profile_id !== (int) $profile->id) {
            abort(404);
        }
    }
}
