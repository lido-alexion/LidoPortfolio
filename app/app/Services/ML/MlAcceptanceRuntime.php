<?php
namespace App\Services\ML;

use App\Models\V7\MlModelVersion;
use Illuminate\Validation\ValidationException;

class MlAcceptanceRuntime
{
    public const TIMEOUT = 14400;

    public function identity(): array
    {
        $path = base_path('bootstrap/build-info.json');
        $build = is_file($path) ? json_decode(file_get_contents($path), true) : [];
        $profiles = [];
        foreach (MlScoringService::HORIZONS as $horizon) {
            $profiles[$horizon] = app(MlFeatureRegistryService::class)->featureSetForHorizon($horizon);
        }
        return [
            'build_id' => is_string($build['build_id'] ?? null) ? $build['build_id'] : null,
            'commit_sha' => is_string($build['commit_sha'] ?? null) ? $build['commit_sha'] : null,
            'environment' => app()->environment(),
            'registry' => config('ml_feature_registry.registry_version'),
            'configuration_sha256' => hash('sha256', json_encode([$profiles, config('ml'), config('ml_lifecycle.retry')], JSON_THROW_ON_ERROR)),
            'adapter_sha256' => is_file((string) config('ml.adapter_script')) ? hash_file('sha256', config('ml.adapter_script')) : null,
        ];
    }

    public function queueReady(): bool
    {
        $connection = config('queue.connections.'.config('queue.default'), []);
        return in_array($connection['driver'] ?? null, ['database', 'redis'], true)
            && ($connection['retry_after'] ?? 0) > self::TIMEOUT
            && (app()->environment('testing') || in_array(config('cache.default'), ['database', 'redis'], true));
    }

    public function assertQueue(): void
    {
        if (! $this->queueReady()) {
            throw ValidationException::withMessages(['queue' => ['Acceptance requires a database/Redis queue, visibility timeout above 14400 seconds and distributed cache locks.']]);
        }
    }

    public function activeModels(): array
    {
        return MlModelVersion::query()->where('status', 'active')->orderBy('id')->get(['id', 'horizon', 'artifact_sha256'])->toArray();
    }

    public function history(array $history, string $action, ?int $actor): array
    {
        $history[] = ['action' => $action, 'actor_id' => $actor, 'at' => now()->toIso8601String()];
        return $history;
    }
}
