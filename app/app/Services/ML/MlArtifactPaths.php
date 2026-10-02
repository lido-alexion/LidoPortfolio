<?php

namespace App\Services\ML;

use RuntimeException;

/** Persistent artifact paths shared by training, inference and lifecycle operations. */
class MlArtifactPaths
{
    public function directory(bool $create = false): string
    {
        $configured = config('ml.model_directory');
        $base = $this->normalize(realpath(base_path()) ?: base_path());
        $default = preg_match('~^(.*)/releases/[^/]+(?:/app)?$~', $base, $match)
            ? $match[1].'/shared/ml/models'
            : storage_path('app/ml-models');
        $path = (string) ($configured ?: $default);
        if (preg_match('~^(.*)/releases/[^/]+/(?:app/)?(?:\.\./)+shared/ml/models/?$~', $path, $legacy)) {
            $path = $legacy[1].'/shared/ml/models';
        }
        $path = $this->normalize($path);
        // Repair the old default even when explicitly cached/configured.
        $path = preg_replace('~^(.*)/releases/shared/ml/models$~', '$1/shared/ml/models', $path);
        // Resolve existing ancestors too, so a not-yet-created child of shared
        // storage never retains a current/release symlink in its persisted path.
        $ancestor = $path;
        $tail = '';
        while (! file_exists($ancestor) && dirname($ancestor) !== $ancestor) {
            $tail = '/'.basename($ancestor).$tail;
            $ancestor = dirname($ancestor);
        }
        $path = (realpath($ancestor) ?: $ancestor).$tail;
        if (preg_match('~/releases/[^/]+(?:/|$)~', $path)) {
            throw new RuntimeException('ML model directory must be outside deployment releases.');
        }

        if ($create && ! is_dir($path) && ! mkdir($path, 0775, true) && ! is_dir($path)) {
            throw new RuntimeException('ML model artifact directory is unavailable.');
        }

        return realpath($path) ?: $path;
    }

    public function isLegacy(string $path): bool
    {
        return (bool) preg_match('~/releases/[^/]+/(?:app/)?(?:\.\./)*shared/ml/models/[^/]+$~', $path);
    }

    public function destination(string $path): string
    {
        return $this->directory().'/'.basename($path);
    }

    public function resolve(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }
        if ($this->isLegacy($path)) {
            return $this->destination($path);
        }

        return realpath($path) ?: $this->normalize($path);
    }

    /** Lexical normalization works even after a release component has been removed. */
    public function normalize(string $path): string
    {
        if (! str_starts_with($path, '/')) {
            $path = base_path($path);
        }
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '..') {
                array_pop($parts);
            } elseif ($part !== '' && $part !== '.') {
                $parts[] = $part;
            }
        }

        return '/'.implode('/', $parts);
    }
}
