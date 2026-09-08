<?php

namespace App\Http\Controllers\Api\V1;

use App\Engines\Support\ApiEnvelope;
use App\Http\Controllers\Controller;
use App\Models\ArtifactBinding;
use App\Models\ArtifactBundleDeployment;
use App\Models\ArtifactShareGrant;
use App\Models\ReusableArtifactVersion;
use App\Models\User;
use App\Services\Artifacts\ArtifactBindingService;
use App\Services\Artifacts\ArtifactBundleDeploymentService;
use App\Services\Artifacts\ArtifactPackageService;
use App\Services\Artifacts\ArtifactSharingService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

class ArtifactActionController extends Controller
{
    public function __construct(
        private ArtifactBindingService $bindings,
        private ArtifactSharingService $sharing,
        private ArtifactPackageService $packages,
        private ArtifactBundleDeploymentService $bundles,
    ) {}

    public function bind(Request $request, int $version)
    {
        $validated = $request->validate([
            'settings' => ['nullable', 'array'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        return $this->run(function () use ($request, $version, $validated) {
            $binding = $this->bindings->bind(
                activePortfolio(),
                ReusableArtifactVersion::query()->findOrFail($version),
                $request->user(),
                $validated['settings'] ?? [],
                $validated['enabled'] ?? false,
            );

            return ApiEnvelope::success($this->bindingData($binding), [], 201);
        }, 'ARTIFACT_BIND_FAILED');
    }

    public function upgrade(Request $request, string $uuid)
    {
        $validated = $request->validate([
            'artifact_version_id' => ['required', 'integer'],
            'expected_lock_version' => ['required', 'integer', 'min:0'],
            'settings' => ['nullable', 'array'],
            'change_summary' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->run(function () use ($request, $uuid, $validated) {
            $binding = $this->ownedBinding($uuid);
            $upgraded = $this->bindings->upgrade(
                $binding,
                ReusableArtifactVersion::query()->findOrFail($validated['artifact_version_id']),
                $request->user(),
                $validated['expected_lock_version'],
                array_key_exists('settings', $validated) ? $validated['settings'] : null,
                $validated['change_summary'] ?? null,
            );

            return ApiEnvelope::success($this->bindingData($upgraded));
        }, 'ARTIFACT_BINDING_UPGRADE_FAILED');
    }

    public function settings(Request $request, string $uuid)
    {
        $validated = $request->validate([
            'expected_lock_version' => ['required', 'integer', 'min:0'],
            'settings' => ['required', 'array'],
            'change_summary' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->run(function () use ($request, $uuid, $validated) {
            $binding = $this->bindings->updateSettings(
                $this->ownedBinding($uuid),
                $request->user(),
                $validated['expected_lock_version'],
                $validated['settings'],
                $validated['change_summary'] ?? null,
            );

            return ApiEnvelope::success($this->bindingData($binding));
        }, 'ARTIFACT_BINDING_SETTINGS_FAILED');
    }

    public function enable(Request $request, string $uuid)
    {
        $validated = $request->validate([
            'expected_lock_version' => ['required', 'integer', 'min:0'],
            'enabled' => ['required', 'boolean'],
        ]);

        return $this->run(function () use ($request, $uuid, $validated) {
            $binding = $this->bindings->setEnabled(
                $this->ownedBinding($uuid),
                $request->user(),
                $validated['expected_lock_version'],
                $validated['enabled'],
            );

            return ApiEnvelope::success($this->bindingData($binding));
        }, 'ARTIFACT_BINDING_ENABLE_FAILED');
    }

    public function share(Request $request, int $version)
    {
        $validated = $request->validate(['recipient_email' => ['required', 'email', 'max:255']]);

        return $this->run(function () use ($request, $version, $validated) {
            $recipient = User::query()->where('email', mb_strtolower(trim($validated['recipient_email'])))->firstOrFail();
            $grant = $this->sharing->share(
                ReusableArtifactVersion::query()->findOrFail($version),
                $request->user(),
                $recipient,
            );

            return ApiEnvelope::success($this->grantData($grant), [], 201);
        }, 'ARTIFACT_SHARE_FAILED');
    }

    public function adopt(Request $request, string $uuid)
    {
        return $this->run(function () use ($request, $uuid) {
            $grant = ArtifactShareGrant::query()->where('grant_uuid', $uuid)->firstOrFail();
            $adoptions = $this->sharing->adopt($grant, $request->user());

            return ApiEnvelope::success(['grant_uuid' => $uuid, 'adopted_versions' => count($adoptions)]);
        }, 'ARTIFACT_ADOPTION_FAILED');
    }

    public function revoke(Request $request, string $uuid)
    {
        return $this->run(function () use ($request, $uuid) {
            $grant = ArtifactShareGrant::query()->where('grant_uuid', $uuid)->firstOrFail();

            return ApiEnvelope::success($this->grantData($this->sharing->revoke($grant, $request->user())));
        }, 'ARTIFACT_SHARE_REVOKE_FAILED');
    }

    public function export(Request $request, int $version)
    {
        return $this->run(fn () => ApiEnvelope::success($this->packages->export(
            ReusableArtifactVersion::query()->findOrFail($version),
            $request->user(),
        )), 'ARTIFACT_EXPORT_FAILED');
    }

    public function import(Request $request)
    {
        $package = $request->input('package', $request->all());

        return $this->run(function () use ($request, $package) {
            $drafts = $this->packages->import($package, $request->user());

            return ApiEnvelope::success(array_map(fn ($draft) => [
                'artifact_uuid' => $draft->artifact->artifact_uuid,
                'version_id' => $draft->id,
                'slug' => $draft->artifact->slug,
                'status' => $draft->status,
            ], $drafts), ['count' => count($drafts)], 201);
        }, 'ARTIFACT_IMPORT_FAILED');
    }

    public function planBundle(Request $request, int $version)
    {
        $validated = $request->validate(['member_settings' => ['nullable', 'array']]);

        return $this->run(function () use ($request, $version, $validated) {
            $deployment = $this->bundles->plan(
                ReusableArtifactVersion::query()->findOrFail($version),
                activePortfolio(),
                $request->user(),
                $validated['member_settings'] ?? [],
            );

            return ApiEnvelope::success($this->deploymentData($deployment), [], 201);
        }, 'ARTIFACT_BUNDLE_PLAN_FAILED');
    }

    public function deployBundle(Request $request, string $uuid)
    {
        return $this->run(function () use ($request, $uuid) {
            $deployment = ArtifactBundleDeployment::query()
                ->where('deployment_uuid', $uuid)
                ->where('requested_by_user_id', $request->user()->id)
                ->firstOrFail();

            return ApiEnvelope::success($this->deploymentData($this->bundles->deploy($deployment, $request->user())));
        }, 'ARTIFACT_BUNDLE_DEPLOY_FAILED');
    }

    private function ownedBinding(string $uuid): ArtifactBinding
    {
        return ArtifactBinding::query()
            ->where('binding_uuid', $uuid)
            ->where('profile_id', activePortfolio()->id)
            ->with('activeRevision.artifactVersion')
            ->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function bindingData(ArtifactBinding $binding): array
    {
        $binding->loadMissing('activeRevision.artifactVersion', 'revisions');

        return [
            'binding_uuid' => $binding->binding_uuid,
            'status' => $binding->status,
            'usability_state' => $binding->usability_state,
            'usability_reasons' => $binding->usability_reasons_json ?? [],
            'lock_version' => $binding->lock_version,
            'active_version_id' => $binding->activeRevision?->artifact_version_id,
            'active_version' => $binding->activeRevision?->artifactVersion?->semver,
            'settings' => $binding->activeRevision?->settings_json ?? [],
            'revision_number' => $binding->activeRevision?->revision_number,
        ];
    }

    /** @return array<string, mixed> */
    private function grantData(ArtifactShareGrant $grant): array
    {
        return [
            'grant_uuid' => $grant->grant_uuid,
            'status' => $grant->status,
            'dependency_version_ids' => $grant->dependency_version_ids_json ?? [],
            'granted_at' => $grant->granted_at?->toIso8601String(),
            'revoked_at' => $grant->revoked_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function deploymentData(ArtifactBundleDeployment $deployment): array
    {
        $deployment->loadMissing('items.memberVersion.artifact');

        return [
            'deployment_uuid' => $deployment->deployment_uuid,
            'status' => $deployment->status,
            'plan' => $deployment->plan_json,
            'error_code' => $deployment->error_code,
            'items' => $deployment->items->map(fn ($item) => [
                'artifact_uuid' => $item->memberVersion->artifact->artifact_uuid,
                'version' => $item->memberVersion->semver,
                'action' => $item->action,
                'binding_id' => $item->binding_id,
                'resulting_revision_id' => $item->resulting_revision_id,
            ])->values()->all(),
        ];
    }

    private function run(callable $operation, string $errorCode)
    {
        try {
            return $operation();
        } catch (ModelNotFoundException) {
            return ApiEnvelope::error($errorCode, 'Requested artifact resource is not available.', 404);
        } catch (RuntimeException $error) {
            return ApiEnvelope::error($errorCode, $error->getMessage(), 409);
        } catch (InvalidArgumentException $error) {
            return ApiEnvelope::error($errorCode, $error->getMessage(), 422);
        }
    }
}
