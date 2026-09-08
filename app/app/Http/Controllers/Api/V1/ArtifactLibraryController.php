<?php

namespace App\Http\Controllers\Api\V1;

use App\Engines\Support\ApiEnvelope;
use App\Http\Controllers\Controller;
use App\Services\Artifacts\ArtifactLibraryQueryService;
use App\Services\Artifacts\ArtifactType;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ArtifactLibraryController extends Controller
{
    public function __construct(private ArtifactLibraryQueryService $library) {}

    public function index(Request $request)
    {
        $validated = $request->validate([
            'type' => ['nullable', 'string', 'in:'.implode(',', ArtifactType::all())],
            'q' => ['nullable', 'string', 'max:200'],
        ]);
        $items = $this->library->index($request->user(), activePortfolio(), $validated);

        return ApiEnvelope::success($items, ['count' => count($items)]);
    }

    public function show(Request $request, string $uuid)
    {
        try {
            return ApiEnvelope::success($this->library->detail($uuid, $request->user(), activePortfolio()));
        } catch (ModelNotFoundException|InvalidArgumentException) {
            return ApiEnvelope::error('ARTIFACT_NOT_FOUND', 'Artifact is not available in this account Library.', 404);
        }
    }

    public function diff(Request $request, string $uuid)
    {
        $validated = $request->validate([
            'from' => ['required', 'string', 'max:64'],
            'to' => ['required', 'string', 'max:64', 'different:from'],
        ]);
        try {
            return ApiEnvelope::success($this->library->diff(
                $uuid,
                $validated['from'],
                $validated['to'],
                $request->user(),
            ));
        } catch (ModelNotFoundException|InvalidArgumentException) {
            return ApiEnvelope::error('ARTIFACT_VERSIONS_NOT_FOUND', 'Both versions must be available in this account Library.', 404);
        }
    }
}
