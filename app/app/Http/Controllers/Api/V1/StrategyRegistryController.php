<?php

namespace App\Http\Controllers\Api\V1;

use App\Engines\Support\ApiEnvelope;
use App\Http\Controllers\Controller;
use App\Services\Artifacts\ArtifactType;
use App\Services\Artifacts\StrategyArtifactRegistry;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Strategy Registry HTTP API — reusable Strategy artifacts (SD-034).
 * PUT /api/v1/strategy remains the editor; optional strategy_id is UI selection, not exclusive-active.
 */
class StrategyRegistryController extends Controller
{
    public function __construct(
        private StrategyArtifactRegistry $registry,
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
            'origin' => $request->input('origin'),
        ], fn ($v) => $v !== null && $v !== '');

        $items = $this->registry->list($profile, $filters);

        return ApiEnvelope::success($items, ['count' => count($items), 'type' => ArtifactType::STRATEGY]);
    }

    public function show(string $id)
    {
        $profile = \activePortfolio();
        if ($profile === null) {
            return ApiEnvelope::error('NO_PORTFOLIO', 'Active portfolio required.', 422);
        }
        $env = $this->registry->get($id, $profile);
        if ($env === null) {
            return ApiEnvelope::error('STRATEGY_NOT_FOUND', "Unknown strategy: {$id}", 404);
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
            return ApiEnvelope::error('STRATEGY_NOT_FOUND', $e->getMessage(), 404);
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
            $envelope['artifact_type'] = ArtifactType::STRATEGY;
            $created = $this->registry->create($envelope, $profile);

            return ApiEnvelope::success($created, [], 201);
        } catch (InvalidArgumentException $e) {
            return ApiEnvelope::error('STRATEGY_CREATE_FAILED', $e->getMessage(), 422);
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
            $envelope['artifact_type'] = ArtifactType::STRATEGY;
            $updated = $this->registry->update($id, $envelope, $profile);

            return ApiEnvelope::success($updated);
        } catch (InvalidArgumentException $e) {
            return ApiEnvelope::error('STRATEGY_UPDATE_FAILED', $e->getMessage(), 422);
        }
    }

    public function validateEnvelope(Request $request)
    {
        $profile = \activePortfolio();
        $envelope = $request->all();
        $envelope['artifact_type'] = ArtifactType::STRATEGY;
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
            return ApiEnvelope::success($this->registry->exportOne($id, $profile));
        } catch (InvalidArgumentException $e) {
            return ApiEnvelope::error('STRATEGY_NOT_FOUND', $e->getMessage(), 404);
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
            $envelope['artifact_type'] = ArtifactType::STRATEGY;
            $created = $this->registry->importEnvelope($envelope, $profile);

            return ApiEnvelope::success($created, [], 201);
        } catch (InvalidArgumentException $e) {
            return ApiEnvelope::error('STRATEGY_IMPORT_FAILED', $e->getMessage(), 422);
        }
    }

    public function activate(string $id)
    {
        $profile = \activePortfolio();
        if ($profile === null) {
            return ApiEnvelope::error('NO_PORTFOLIO', 'Active portfolio required.', 422);
        }
        try {
            $activated = $this->registry->activate($id, $profile);

            return ApiEnvelope::success($activated);
        } catch (InvalidArgumentException $e) {
            return ApiEnvelope::error('STRATEGY_ACTIVATE_FAILED', $e->getMessage(), 422);
        }
    }

    public function archive(string $id)
    {
        $profile = \activePortfolio();
        if ($profile === null) {
            return ApiEnvelope::error('NO_PORTFOLIO', 'Active portfolio required.', 422);
        }
        try {
            $archived = $this->registry->archive($id, $profile);

            return ApiEnvelope::success($archived);
        } catch (InvalidArgumentException $e) {
            return ApiEnvelope::error('STRATEGY_ARCHIVE_FAILED', $e->getMessage(), 422);
        }
    }

    public function selection()
    {
        $profile = \activePortfolio();
        if ($profile === null) {
            return ApiEnvelope::error('NO_PORTFOLIO', 'Active portfolio required.', 422);
        }
        $items = $this->registry->list($profile, ['status' => 'active']);
        $editor = $items[0] ?? null;

        return ApiEnvelope::success([
            'enabled' => $items,
            'enabled_count' => count($items),
            'selected' => $editor,
            'editor' => $editor,
            'rule' => StrategyArtifactRegistry::ENABLEMENT_RULE,
        ]);
    }
}
