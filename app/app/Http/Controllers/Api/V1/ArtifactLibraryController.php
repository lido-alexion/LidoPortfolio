<?php

namespace App\Http\Controllers\Api\V1;

use App\Engines\Support\ApiEnvelope;
use App\Http\Controllers\Controller;
use App\Models\ReusableArtifact;
use App\Models\ReusableArtifactVersion;
use App\Services\Artifacts\ArtifactLibraryQueryService;
use App\Services\Artifacts\ArtifactOrigin;
use App\Services\Artifacts\ArtifactType;
use App\Services\Artifacts\ReusableArtifactLifecycleService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ArtifactLibraryController extends Controller
{
    public function __construct(
        private ArtifactLibraryQueryService $library,
        private ReusableArtifactLifecycleService $lifecycle,
    ) {}

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

    public function createDraft(Request $request)
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'in:'.implode(',', [
                ArtifactType::SCREENER,
                ArtifactType::STRATEGY,
                ArtifactType::BUNDLE,
            ])],
            'slug' => ['required', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:200'],
            'semver' => ['nullable', 'string', 'max:64'],
            'content' => ['required', 'array'],
            'ai_assisted' => ['nullable', 'boolean'],
        ]);
        try {
            $draft = $this->lifecycle->createDraft(
                $request->user(),
                $validated['type'],
                $validated['slug'],
                $validated['name'],
                $validated['content'],
                $validated['semver'] ?? '1.0.0',
                ($validated['ai_assisted'] ?? false) ? ArtifactOrigin::AI_ASSISTED : ArtifactOrigin::USER,
            );

            return ApiEnvelope::success($this->detailFor($draft, $request), [], 201);
        } catch (InvalidArgumentException $error) {
            return ApiEnvelope::error('ARTIFACT_DRAFT_CREATE_FAILED', $error->getMessage(), 422);
        }
    }

    public function updateDraft(Request $request, int $version)
    {
        $validated = $request->validate([
            'expected_lock_version' => ['required', 'integer', 'min:0'],
            'content' => ['required', 'array'],
            'documentation' => ['nullable', 'array'],
            'change_summary' => ['nullable', 'string', 'max:1000'],
        ]);
        try {
            $draft = $this->ownedVersion($version, $request);
            $updated = $this->lifecycle->updateDraft(
                $draft,
                $request->user(),
                $validated['expected_lock_version'],
                $validated['content'],
                $validated['documentation'] ?? [],
                $validated['change_summary'] ?? null,
            );

            return ApiEnvelope::success($this->detailFor($updated, $request));
        } catch (ModelNotFoundException) {
            return ApiEnvelope::error('ARTIFACT_DRAFT_NOT_FOUND', 'Owned artifact Draft was not found.', 404);
        } catch (\RuntimeException $error) {
            return ApiEnvelope::error('ARTIFACT_DRAFT_CONFLICT', $error->getMessage(), 409);
        } catch (InvalidArgumentException $error) {
            return ApiEnvelope::error('ARTIFACT_DRAFT_UPDATE_FAILED', $error->getMessage(), 422);
        }
    }

    public function publish(Request $request, int $version)
    {
        $validated = $request->validate([
            'dependencies' => ['nullable', 'array'],
            'dependencies.*.kind' => ['nullable', 'string', 'max:48'],
            'dependencies.*.artifact_version_id' => ['nullable', 'integer'],
            'dependencies.*.indicator_id' => ['nullable', 'string', 'max:120'],
            'dependencies.*.indicator_version' => ['nullable', 'string', 'max:64'],
            'dependencies.*.required' => ['nullable', 'boolean'],
            'change_summary' => ['nullable', 'string', 'max:1000'],
        ]);
        try {
            $draft = $this->ownedVersion($version, $request);
            $published = $this->lifecycle->publish(
                $draft,
                $request->user(),
                $validated['dependencies'] ?? [],
                $validated['change_summary'] ?? null,
            );

            return ApiEnvelope::success($this->detailFor($published, $request));
        } catch (ModelNotFoundException) {
            return ApiEnvelope::error('ARTIFACT_DRAFT_NOT_FOUND', 'Owned artifact Draft was not found.', 404);
        } catch (InvalidArgumentException $error) {
            return ApiEnvelope::error('ARTIFACT_PUBLISH_FAILED', $error->getMessage(), 422);
        }
    }

    public function nextDraft(Request $request, int $version)
    {
        $validated = $request->validate([
            'semver' => ['required', 'string', 'max:64'],
            'content' => ['nullable', 'array'],
        ]);
        try {
            $published = $this->ownedVersion($version, $request);
            $draft = $this->lifecycle->createNextDraft(
                $published,
                $request->user(),
                $validated['semver'],
                $validated['content'] ?? null,
            );

            return ApiEnvelope::success($this->detailFor($draft, $request), [], 201);
        } catch (ModelNotFoundException) {
            return ApiEnvelope::error('ARTIFACT_VERSION_NOT_FOUND', 'Owned artifact version was not found.', 404);
        } catch (InvalidArgumentException $error) {
            return ApiEnvelope::error('ARTIFACT_NEXT_DRAFT_FAILED', $error->getMessage(), 422);
        }
    }

    public function fork(Request $request, int $version)
    {
        $validated = $request->validate([
            'slug' => ['required', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:200'],
        ]);
        try {
            $source = ReusableArtifactVersion::query()->with('artifact')->findOrFail($version);
            $draft = $this->lifecycle->fork($source, $request->user(), $validated['slug'], $validated['name']);

            return ApiEnvelope::success($this->detailFor($draft, $request), [], 201);
        } catch (ModelNotFoundException|InvalidArgumentException) {
            return ApiEnvelope::error('ARTIFACT_FORK_FAILED', 'Published artifact version is not available for Fork.', 404);
        }
    }

    public function archive(Request $request, string $uuid)
    {
        try {
            $artifact = ReusableArtifact::query()
                ->where('artifact_uuid', $uuid)
                ->where('owner_user_id', $request->user()->id)
                ->firstOrFail();
            $this->lifecycle->archive($artifact, $request->user());

            return ApiEnvelope::success($this->library->detail($uuid, $request->user(), activePortfolio()));
        } catch (ModelNotFoundException) {
            return ApiEnvelope::error('ARTIFACT_NOT_FOUND', 'Owned artifact was not found.', 404);
        }
    }

    private function ownedVersion(int $id, Request $request): ReusableArtifactVersion
    {
        return ReusableArtifactVersion::query()
            ->whereKey($id)
            ->whereHas('artifact', fn ($query) => $query->where('owner_user_id', $request->user()->id))
            ->with('artifact')
            ->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function detailFor(ReusableArtifactVersion $version, Request $request): array
    {
        $version->loadMissing('artifact');

        return $this->library->detail($version->artifact->artifact_uuid, $request->user(), activePortfolio());
    }
}
