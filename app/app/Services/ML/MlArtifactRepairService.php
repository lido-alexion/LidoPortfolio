<?php

namespace App\Services\ML;

use App\Models\V7\MlModelVersion;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class MlArtifactRepairService
{
    public function __construct(private MlArtifactPaths $paths) {}

    /** @return array{status:string, path:?string} */
    public function repair(int $id, bool $dryRun = true): array
    {
        return DB::transaction(function () use ($id, $dryRun): array {
            $model = MlModelVersion::query()->lockForUpdate()->findOrFail($id);
            $original = $model->artifact_path;
            if ($original === null) {
                return ['status' => 'skipped', 'path' => null];
            }
            $destination = $this->paths->destination($original);
            if (is_link($destination)) {
                throw new RuntimeException('Canonical artifact destination must not be a symlink.');
            }
            $sources = array_unique([$original, $this->paths->normalize($original), $destination]);
            $source = null;
            foreach ($sources as $candidate) {
                if (file_exists($candidate) || is_link($candidate)) {
                    $this->verify($candidate, $model->artifact_sha256);
                    $source ??= $candidate;
                }
            }
            if ($source === null) {
                throw new RuntimeException('No artifact source or canonical destination exists.');
            }
            if ($dryRun) {
                return ['status' => $original === $destination ? 'verified' : 'would_repair', 'path' => $destination];
            }
            $this->paths->directory(true);
            if (! file_exists($destination)) {
                $temporary = tempnam(dirname($destination), '.ml-repair-');
                if ($temporary === false) {
                    throw new RuntimeException('Cannot create repair staging file.');
                }
                try {
                    if (! copy($source, $temporary)) {
                        throw new RuntimeException('Artifact copy failed.');
                    }
                    $this->verify($temporary, $model->artifact_sha256);
                    chmod($temporary, 0664);
                    // Atomic publication without overwriting a concurrent destination.
                    if (! @link($temporary, $destination) && ! file_exists($destination)) {
                        throw new RuntimeException('Cannot publish canonical artifact.');
                    }
                } finally {
                    unlink($temporary);
                }
            }
            $this->verify($destination, $model->artifact_sha256);
            if ($original !== $destination) {
                // Only the path changes: no promotion, timestamps or evidence mutation.
                DB::table($model->getTable())->where('id', $id)->update(['artifact_path' => $destination]);
            }

            return ['status' => $original === $destination ? 'verified' : 'repaired', 'path' => $destination];
        });
    }

    private function verify(string $path, ?string $digest): void
    {
        if ($digest === null || ! preg_match('/^[a-f0-9]{64}$/i', $digest)
            || ! is_file($path) || ! is_readable($path)
            || ! hash_equals(strtolower($digest), (string) hash_file('sha256', $path))) {
            throw new RuntimeException('Artifact SHA-256 verification failed: '.$path);
        }
    }
}
