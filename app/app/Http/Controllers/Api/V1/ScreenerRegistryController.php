<?php

namespace App\Http\Controllers\Api\V1;

use App\Engines\Support\ApiEnvelope;
use App\Http\Controllers\Controller;
use App\Services\Artifacts\ArtifactType;
use App\Services\Artifacts\ScreenerArtifactRegistry;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Screener Registry HTTP API — first-class reusable Screener artifacts (SD-034).
 * Does not replace /api/screeners* run/edit UX; does not change execution.
 */
class ScreenerRegistryController extends Controller
{
    public function __construct(
        private ScreenerArtifactRegistry $registry,
    ) {}

    public function meta()
    {
        $profile = \activePortfolio();
        if ($profile === null) {
            return ApiEnvelope::error('NO_PORTFOLIO', 'Active portfolio required.', 422);
        }

        return ApiEnvelope::success($this->registry->meta($profile));
    }

    public function index(Request $request)
    {
        $profile = \activePortfolio();
        if ($profile === null) {
            return ApiEnvelope::error('NO_PORTFOLIO', 'Active portfolio required.', 422);
        }

        $filters = array_filter([
            'q' => $request->input('q'),
            'status' => $request->input('status'),
            'ownership' => $request->input('ownership'),
            'origin' => $request->input('origin'),
            'include_shared' => $request->input('include_shared', '1'),
        ], fn ($v) => $v !== null && $v !== '');

        $items = $this->registry->list($profile, $filters);

        return ApiEnvelope::success($items, ['count' => count($items), 'type' => ArtifactType::SCREENER]);
    }

    public function show(string $id)
    {
        $profile = \activePortfolio();
        if ($profile === null) {
            return ApiEnvelope::error('NO_PORTFOLIO', 'Active portfolio required.', 422);
        }
        $env = $this->registry->get($id, $profile);
        if ($env === null) {
            return ApiEnvelope::error('SCREENER_NOT_FOUND', "Unknown screener: {$id}", 404);
        }

        return ApiEnvelope::success($env);
    }

    public function versions(string $id)
    {
        $profile = \activePortfolio();
        if ($profile === null) {
            return ApiEnvelope::error('NO_PORTFOLIO', 'Active portfolio required.', 422);
        }
        try {
            $versions = $this->registry->listVersions($id, $profile);

            return ApiEnvelope::success($versions, ['count' => count($versions)]);
        } catch (InvalidArgumentException $e) {
            return ApiEnvelope::error('SCREENER_NOT_FOUND', $e->getMessage(), 404);
        }
    }

    public function store(Request $request)
    {
        $profile = \activePortfolio();
        if ($profile === null) {
            return ApiEnvelope::error('NO_PORTFOLIO', 'Active portfolio required.', 422);
        }
        try {
            $envelope = $request->all();
            $envelope['artifact_type'] = ArtifactType::SCREENER;
            $created = $this->registry->create($envelope, $profile);

            return ApiEnvelope::success($created, [], 201);
        } catch (InvalidArgumentException $e) {
            return ApiEnvelope::error('SCREENER_CREATE_FAILED', $e->getMessage(), 422);
        }
    }

    public function update(Request $request, string $id)
    {
        $profile = \activePortfolio();
        if ($profile === null) {
            return ApiEnvelope::error('NO_PORTFOLIO', 'Active portfolio required.', 422);
        }
        try {
            $envelope = $request->all();
            $envelope['artifact_type'] = ArtifactType::SCREENER;
            $updated = $this->registry->update($id, $envelope, $profile);

            return ApiEnvelope::success($updated);
        } catch (InvalidArgumentException $e) {
            return ApiEnvelope::error('SCREENER_UPDATE_FAILED', $e->getMessage(), 422);
        }
    }

    public function validateEnvelope(Request $request)
    {
        $profile = \activePortfolio();
        $envelope = $request->all();
        $envelope['artifact_type'] = ArtifactType::SCREENER;
        $result = $this->registry->validate($envelope, $profile);

        return ApiEnvelope::success($result->toArray());
    }

    public function export(string $id)
    {
        $profile = \activePortfolio();
        if ($profile === null) {
            return ApiEnvelope::error('NO_PORTFOLIO', 'Active portfolio required.', 422);
        }
        try {
            $env = $this->registry->exportOne($id, $profile);

            return ApiEnvelope::success($env);
        } catch (InvalidArgumentException $e) {
            return ApiEnvelope::error('SCREENER_NOT_FOUND', $e->getMessage(), 404);
        }
    }

    public function import(Request $request)
    {
        $profile = \activePortfolio();
        if ($profile === null) {
            return ApiEnvelope::error('NO_PORTFOLIO', 'Active portfolio required.', 422);
        }
        try {
            $envelope = $request->input('artifact') ?? $request->all();
            if (isset($envelope['artifact']) && is_array($envelope['artifact'])) {
                $envelope = $envelope['artifact'];
            }
            $envelope['artifact_type'] = ArtifactType::SCREENER;
            $created = $this->registry->importEnvelope($envelope, $profile);

            return ApiEnvelope::success($created, [], 201);
        } catch (InvalidArgumentException $e) {
            return ApiEnvelope::error('SCREENER_IMPORT_FAILED', $e->getMessage(), 422);
        }
    }

    public function importShared(int $sourceId)
    {
        $profile = \activePortfolio();
        if ($profile === null) {
            return ApiEnvelope::error('NO_PORTFOLIO', 'Active portfolio required.', 422);
        }
        try {
            $created = $this->registry->importShared($profile, $sourceId);

            return ApiEnvelope::success($created, [], 201);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return ApiEnvelope::error('SHARED_SCREENER_NOT_FOUND', 'Shared screener not found.', 404);
        } catch (InvalidArgumentException $e) {
            return ApiEnvelope::error('SCREENER_IMPORT_FAILED', $e->getMessage(), 422);
        }
    }
}
