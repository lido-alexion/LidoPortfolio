<?php

namespace App\Services\Artifacts;

/**
 * Builds / normalises Trading Artifact envelopes (JSON spec §2).
 */
final class ArtifactEnvelope
{
    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $definition
     * @param  list<array<string, mixed>>  $dependencies
     * @param  array<string, mixed>|null  $validation
     * @return array<string, mixed>
     */
    public static function make(
        string $artifactType,
        string $slug,
        string $name,
        array $definition,
        array $metadata = [],
        array $dependencies = [],
        int $artifactVersion = 1,
        ?string $artifactId = null,
        ?array $validation = null,
        ?string $minimumEngineVersion = null,
    ): array {
        if (! ArtifactType::isValid($artifactType)) {
            throw new \InvalidArgumentException("Invalid artifact_type: {$artifactType}");
        }

        $envelope = [
            'schema_version' => ArtifactType::SCHEMA_VERSION,
            'artifact_type' => $artifactType,
            'artifact_id' => $artifactId,
            'slug' => $slug,
            'name' => $name,
            'artifact_version' => max(1, $artifactVersion),
            'definition_hash' => DefinitionHasher::hash($definition),
            'minimum_engine_version' => $minimumEngineVersion ?? ArtifactType::MINIMUM_ENGINE_VERSION,
            'metadata' => $metadata,
            'definition' => $definition,
            'dependencies' => array_values($dependencies),
        ];
        if ($validation !== null) {
            $envelope['validation'] = $validation;
        }

        return $envelope;
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @return array<string, mixed>
     */
    public static function withFreshHash(array $envelope): array
    {
        $definition = is_array($envelope['definition'] ?? null) ? $envelope['definition'] : [];
        $envelope['definition_hash'] = DefinitionHasher::hash($definition);
        $envelope['schema_version'] = $envelope['schema_version'] ?? ArtifactType::SCHEMA_VERSION;

        return $envelope;
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    public static function typeOf(array $envelope): string
    {
        return (string) ($envelope['artifact_type'] ?? '');
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    public static function slugOf(array $envelope): string
    {
        return (string) ($envelope['slug'] ?? '');
    }
}
