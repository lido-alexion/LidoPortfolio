<?php

namespace App\Services\Artifacts;

use App\Models\PortfolioProfile;
use InvalidArgumentException;

/**
 * Umbrella Artifact Registry + package import/export (SD-034).
 */
final class ArtifactRegistry
{
    public function __construct(
        private IndicatorArtifactRegistry $indicators,
        private ScreenerArtifactRegistry $screeners,
        private StrategyArtifactRegistry $strategies,
        private ArtifactValidationService $validator,
    ) {}

    public function forType(string $type): Contracts\ArtifactRegistryInterface
    {
        return match ($type) {
            ArtifactType::INDICATOR => $this->indicators,
            ArtifactType::SCREENER => $this->screeners,
            ArtifactType::STRATEGY => $this->strategies,
            default => throw new InvalidArgumentException("Unknown artifact type: {$type}"),
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function listAll(?PortfolioProfile $profile = null, array $filters = []): array
    {
        $type = $filters['type'] ?? null;
        unset($filters['type']);
        if (is_string($type) && ArtifactType::isValid($type)) {
            return $this->forType($type)->list($profile, $filters);
        }

        return [
            ...$this->indicators->list($profile, $filters),
            ...$this->screeners->list($profile, $filters),
            ...$this->strategies->list($profile, $filters),
        ];
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    public function validate(array $envelope, ?PortfolioProfile $profile = null): ValidationResult
    {
        return $this->validator->validateEnvelope($envelope, $profile);
    }

    /**
     * Export a portable package (bundle screeners when exporting strategy).
     *
     * @param  list<array{type: string, id: string}>  $targets
     * @return array<string, mixed>
     */
    public function exportPackage(array $targets, ?PortfolioProfile $profile = null): array
    {
        $artifacts = [];
        $indicatorRefs = [];
        foreach ($targets as $target) {
            $type = (string) ($target['type'] ?? '');
            $id = (string) ($target['id'] ?? '');
            $env = $this->forType($type)->exportOne($id, $profile);
            $artifacts[] = $env;
            if ($type === ArtifactType::STRATEGY) {
                foreach ($env['dependencies'] ?? [] as $dep) {
                    if (($dep['artifact_type'] ?? '') === ArtifactType::SCREENER) {
                        try {
                            $artifacts[] = $this->screeners->exportOne((string) $dep['ref'], $profile);
                        } catch (\Throwable) {
                            // Reference-only if screener missing locally
                        }
                    }
                }
            }
            foreach ($env['dependencies'] ?? [] as $dep) {
                if (($dep['artifact_type'] ?? '') === ArtifactType::INDICATOR) {
                    $ref = (string) ($dep['ref'] ?? '');
                    if ($ref !== '') {
                        $indicatorRefs[$ref] = [
                            'ref' => $ref,
                            'ref_scheme' => 'registry_id',
                            'min_definition_version' => '1.0.0',
                        ];
                    }
                }
            }
        }

        // Dedupe by type+slug
        $seen = [];
        $unique = [];
        foreach ($artifacts as $art) {
            $key = ($art['artifact_type'] ?? '').':'.($art['slug'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $art;
        }

        return [
            'schema_version' => ArtifactType::SCHEMA_VERSION,
            'package_id' => (string) \Illuminate\Support\Str::uuid(),
            'package_format' => ArtifactType::PACKAGE_FORMAT,
            'exported_at' => now()->toIso8601String(),
            'minimum_engine_version' => ArtifactType::MINIMUM_ENGINE_VERSION,
            'origin' => [
                'app' => 'StoX',
                'app_version' => (string) config('app.version', config('app.env')),
            ],
            'artifacts' => $unique,
            'indicator_refs' => array_values($indicatorRefs),
            'extension' => [],
            'checksum' => null,
        ];
    }

    /**
     * Import package → draft artifacts. Does not activate strategies or run screeners.
     *
     * @param  array<string, mixed>  $package
     * @return array{created: list<array<string, mixed>>, errors: list<array<string, mixed>>}
     */
    public function importPackage(array $package, ?PortfolioProfile $profile = null): array
    {
        $schema = (string) ($package['schema_version'] ?? '');
        if ($schema === '' || (int) explode('.', $schema)[0] > (int) explode('.', ArtifactType::SCHEMA_VERSION)[0]) {
            throw new InvalidArgumentException("Unsupported package schema_version: {$schema}");
        }
        if (($package['package_format'] ?? '') !== ArtifactType::PACKAGE_FORMAT) {
            throw new InvalidArgumentException('Invalid package_format');
        }

        $created = [];
        $errors = [];
        $artifacts = is_array($package['artifacts'] ?? null) ? $package['artifacts'] : [];

        // Import screeners before strategies
        usort($artifacts, function ($a, $b) {
            $order = [ArtifactType::INDICATOR => 0, ArtifactType::SCREENER => 1, ArtifactType::STRATEGY => 2];

            return ($order[$a['artifact_type'] ?? ''] ?? 9) <=> ($order[$b['artifact_type'] ?? ''] ?? 9);
        });

        foreach ($artifacts as $i => $envelope) {
            if (! is_array($envelope)) {
                continue;
            }
            $type = (string) ($envelope['artifact_type'] ?? '');
            try {
                $meta = is_array($envelope['metadata'] ?? null) ? $envelope['metadata'] : [];
                $meta['origin'] = ArtifactOrigin::IMPORTED;
                $meta['status'] = ArtifactStatus::DRAFT;
                $envelope['metadata'] = $meta;
                $created[] = $this->forType($type)->create($envelope, $profile);
            } catch (\Throwable $e) {
                $errors[] = [
                    'index' => $i,
                    'slug' => $envelope['slug'] ?? null,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return ['created' => $created, 'errors' => $errors];
    }
}
