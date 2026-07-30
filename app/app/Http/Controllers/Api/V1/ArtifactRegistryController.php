<?php

namespace App\Http\Controllers\Api\V1;

use App\Engines\Support\ApiEnvelope;
use App\Http\Controllers\Controller;
use App\Services\Artifacts\ArtifactRegistry;
use App\Services\Artifacts\ArtifactType;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Trading Artifact Registry HTTP API (SD-034 infrastructure).
 * Additive — does not replace /api/screeners* or /api/v1/strategy.
 */
class ArtifactRegistryController extends Controller
{
    public function __construct(
        private ArtifactRegistry $artifacts,
    ) {}

    public function index(Request $request)
    {
        $profile = \activePortfolio();
        $filters = array_filter([
            'type' => $request->input('type'),
            'q' => $request->input('q'),
            'status' => $request->input('status'),
        ], fn ($v) => $v !== null && $v !== '');

        $items = $this->artifacts->listAll($profile, $filters);

        return ApiEnvelope::success($items, ['count' => count($items)]);
    }

    public function indexType(Request $request, string $type)
    {
        $this->assertType($type);
        $profile = \activePortfolio();
        $filters = array_filter([
            'q' => $request->input('q'),
            'status' => $request->input('status'),
        ], fn ($v) => $v !== null && $v !== '');

        $items = $this->artifacts->forType($type)->list($profile, $filters);

        return ApiEnvelope::success($items, ['count' => count($items), 'type' => $type]);
    }

    public function show(string $type, string $id)
    {
        $this->assertType($type);
        $env = $this->artifacts->forType($type)->get($id, \activePortfolio());
        if ($env === null) {
            return ApiEnvelope::error('ARTIFACT_NOT_FOUND', "Unknown {$type}: {$id}", 404);
        }

        return ApiEnvelope::success($env);
    }

    public function store(Request $request, string $type)
    {
        $this->assertType($type);
        if ($type === ArtifactType::INDICATOR && ! $request->user()?->is_admin) {
            return ApiEnvelope::error('FORBIDDEN', 'Only admins may create indicator drafts.', 403);
        }
        try {
            $envelope = $request->all();
            $envelope['artifact_type'] = $type;
            $created = $this->artifacts->forType($type)->create($envelope, \activePortfolio());

            return ApiEnvelope::success($created, [], 201);
        } catch (InvalidArgumentException $e) {
            return ApiEnvelope::error('ARTIFACT_CREATE_FAILED', $e->getMessage(), 422);
        }
    }

    public function update(Request $request, string $type, string $id)
    {
        $this->assertType($type);
        if ($type === ArtifactType::INDICATOR && ! $request->user()?->is_admin) {
            return ApiEnvelope::error('FORBIDDEN', 'Only admins may update indicator drafts.', 403);
        }
        try {
            $envelope = $request->all();
            $envelope['artifact_type'] = $type;
            $updated = $this->artifacts->forType($type)->update($id, $envelope, \activePortfolio());

            return ApiEnvelope::success($updated);
        } catch (InvalidArgumentException $e) {
            return ApiEnvelope::error('ARTIFACT_UPDATE_FAILED', $e->getMessage(), 422);
        }
    }

    public function validateArtifact(Request $request, string $type)
    {
        $this->assertType($type);
        $envelope = $request->all();
        $envelope['artifact_type'] = $type;
        $result = $this->artifacts->forType($type)->validate($envelope, \activePortfolio());

        return ApiEnvelope::success($result->toArray(), [], $result->ok ? 200 : 422);
    }

    public function exportOne(string $type, string $id)
    {
        $this->assertType($type);
        try {
            $env = $this->artifacts->forType($type)->exportOne($id, \activePortfolio());

            return ApiEnvelope::success($env);
        } catch (InvalidArgumentException $e) {
            return ApiEnvelope::error('ARTIFACT_NOT_FOUND', $e->getMessage(), 404);
        }
    }

    public function exportPackage(Request $request)
    {
        $validated = $request->validate([
            'targets' => 'required|array|min:1',
            'targets.*.type' => 'required|string|in:indicator,screener,strategy',
            'targets.*.id' => 'required|string',
        ]);
        try {
            $package = $this->artifacts->exportPackage($validated['targets'], \activePortfolio());

            return ApiEnvelope::success($package);
        } catch (InvalidArgumentException $e) {
            return ApiEnvelope::error('ARTIFACT_EXPORT_FAILED', $e->getMessage(), 422);
        }
    }

    public function importPackage(Request $request)
    {
        $package = $request->all();
        if (isset($package['package']) && is_array($package['package'])) {
            $package = $package['package'];
        }
        try {
            $result = $this->artifacts->importPackage($package, \activePortfolio());

            return ApiEnvelope::success($result, [], empty($result['errors']) ? 201 : 207);
        } catch (InvalidArgumentException $e) {
            return ApiEnvelope::error('ARTIFACT_IMPORT_FAILED', $e->getMessage(), 422);
        }
    }

    public function validatePackage(Request $request)
    {
        $package = $request->input('package', $request->all());
        $artifacts = is_array($package['artifacts'] ?? null) ? $package['artifacts'] : [];
        $results = [];
        $allOk = true;
        foreach ($artifacts as $i => $envelope) {
            if (! is_array($envelope)) {
                continue;
            }
            $type = (string) ($envelope['artifact_type'] ?? '');
            if (! ArtifactType::isValid($type)) {
                $allOk = false;
                $results[] = ['index' => $i, 'ok' => false, 'errors' => [['code' => 'ARTIFACT_TYPE_INVALID', 'message' => $type]]];

                continue;
            }
            $r = $this->artifacts->forType($type)->validate($envelope, \activePortfolio());
            $allOk = $allOk && $r->ok;
            $results[] = ['index' => $i, 'slug' => $envelope['slug'] ?? null] + $r->toArray();
        }

        return ApiEnvelope::success(['ok' => $allOk, 'results' => $results], [], $allOk ? 200 : 422);
    }

    private function assertType(string $type): void
    {
        if (! ArtifactType::isValid($type)) {
            abort(404, "Unknown artifact type: {$type}");
        }
    }
}
