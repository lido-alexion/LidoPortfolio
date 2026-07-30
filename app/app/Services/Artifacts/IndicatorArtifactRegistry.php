<?php

namespace App\Services\Artifacts;

use App\Models\PortfolioProfile;
use App\Models\TradingArtifactDraft;
use App\Services\Artifacts\Contracts\ArtifactRegistryInterface;
use App\Services\Indicators\IndicatorCapability;
use App\Services\Indicators\IndicatorDefinition;
use App\Services\Indicators\IndicatorRegistry;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Indicator Artifact Registry — projects SD-033 IndicatorRegistry + portfolio drafts.
 * Create/Update never mutate release-shipped calculators (SD-028); drafts are metadata only.
 */
final class IndicatorArtifactRegistry implements ArtifactRegistryInterface
{
    public function __construct(
        private IndicatorRegistry $registry,
        private ArtifactValidationService $validator,
    ) {}

    public function type(): string
    {
        return ArtifactType::INDICATOR;
    }

    public function list(?PortfolioProfile $profile = null, array $filters = []): array
    {
        $out = [];
        foreach ($this->registry->all() as $def) {
            $env = $this->projectDefinition($def);
            if ($this->matchesFilters($env, $filters)) {
                $out[] = $env;
            }
        }
        if ($profile !== null) {
            $drafts = TradingArtifactDraft::query()
                ->where('artifact_type', ArtifactType::INDICATOR)
                ->where(function ($q) use ($profile) {
                    $q->where('profile_id', $profile->id)->orWhereNull('profile_id');
                })
                ->orderBy('slug')
                ->get();
            foreach ($drafts as $draft) {
                $env = $draft->envelope_json;
                if (! is_array($env)) {
                    continue;
                }
                $env['artifact_id'] = $draft->artifact_uuid;
                $env['metadata']['storage'] = 'draft';
                if ($this->matchesFilters($env, $filters)) {
                    $out[] = ArtifactEnvelope::withFreshHash($env);
                }
            }
        }
        usort($out, fn ($a, $b) => strcmp((string) $a['slug'], (string) $b['slug']));

        return $out;
    }

    public function get(string $idOrSlug, ?PortfolioProfile $profile = null): ?array
    {
        if ($profile !== null) {
            $draft = TradingArtifactDraft::query()
                ->where('artifact_type', ArtifactType::INDICATOR)
                ->where(function ($q) use ($profile) {
                    $q->where('profile_id', $profile->id)->orWhereNull('profile_id');
                })
                ->where(function ($q) use ($idOrSlug) {
                    $q->where('artifact_uuid', $idOrSlug)->orWhere('slug', $idOrSlug);
                })
                ->first();
            if ($draft) {
                $env = is_array($draft->envelope_json) ? $draft->envelope_json : [];
                $env['artifact_id'] = $draft->artifact_uuid;
                $env['metadata']['storage'] = 'draft';

                return ArtifactEnvelope::withFreshHash($env);
            }
        }

        $resolved = $this->registry->resolveId($idOrSlug) ?? ($this->registry->has($idOrSlug) ? $idOrSlug : null);
        if ($resolved === null) {
            return null;
        }

        return $this->projectDefinition($this->registry->get($resolved));
    }

    public function create(array $envelope, ?PortfolioProfile $profile = null): array
    {
        $envelope['artifact_type'] = ArtifactType::INDICATOR;
        $envelope = ArtifactEnvelope::withFreshHash($envelope);
        $result = $this->validate($envelope, $profile);
        if (! $result->ok) {
            throw new InvalidArgumentException('Validation failed: '.json_encode($result->toArray()));
        }

        $slug = ArtifactEnvelope::slugOf($envelope);
        $meta = is_array($envelope['metadata'] ?? null) ? $envelope['metadata'] : [];
        $meta['scope'] = $meta['scope'] ?? ArtifactScope::PORTFOLIO;
        $meta['status'] = ArtifactStatus::DRAFT;
        $meta['origin'] = $meta['origin'] ?? ArtifactOrigin::USER;
        $meta['storage'] = 'draft';
        $envelope['metadata'] = $meta;
        $envelope['artifact_version'] = (int) ($envelope['artifact_version'] ?? 1);

        $uuid = (string) Str::uuid();
        $envelope['artifact_id'] = $uuid;

        TradingArtifactDraft::query()->create([
            'profile_id' => $profile?->id,
            'artifact_type' => ArtifactType::INDICATOR,
            'artifact_uuid' => $uuid,
            'slug' => $slug,
            'name' => (string) ($envelope['name'] ?? $slug),
            'artifact_version' => (int) $envelope['artifact_version'],
            'status' => ArtifactStatus::DRAFT,
            'origin' => (string) $meta['origin'],
            'definition_hash' => $envelope['definition_hash'] ?? null,
            'envelope_json' => $envelope,
        ]);

        return $envelope;
    }

    public function update(string $idOrSlug, array $envelope, ?PortfolioProfile $profile = null): array
    {
        $draft = TradingArtifactDraft::query()
            ->where('artifact_type', ArtifactType::INDICATOR)
            ->when($profile, fn ($q) => $q->where('profile_id', $profile->id))
            ->where(function ($q) use ($idOrSlug) {
                $q->where('artifact_uuid', $idOrSlug)->orWhere('slug', $idOrSlug);
            })
            ->first();

        if (! $draft) {
            if ($this->registry->has($idOrSlug) || $this->registry->resolveId($idOrSlug)) {
                throw new InvalidArgumentException(
                    'Release-shipped indicators are immutable via Artifact Registry (SD-028). Create a draft overlay instead.',
                );
            }
            throw new InvalidArgumentException("Indicator draft not found: {$idOrSlug}");
        }

        $envelope['artifact_type'] = ArtifactType::INDICATOR;
        $envelope['artifact_id'] = $draft->artifact_uuid;
        $envelope['slug'] = $draft->slug;
        $envelope['artifact_version'] = ((int) $draft->artifact_version) + 1;
        $envelope = ArtifactEnvelope::withFreshHash($envelope);
        $result = $this->validate($envelope, $profile);
        if (! $result->ok) {
            throw new InvalidArgumentException('Validation failed: '.json_encode($result->toArray()));
        }

        $draft->forceFill([
            'name' => (string) ($envelope['name'] ?? $draft->name),
            'artifact_version' => (int) $envelope['artifact_version'],
            'definition_hash' => $envelope['definition_hash'] ?? null,
            'envelope_json' => $envelope,
            'status' => (string) ($envelope['metadata']['status'] ?? ArtifactStatus::DRAFT),
            'origin' => (string) ($envelope['metadata']['origin'] ?? $draft->origin),
        ])->save();

        return $envelope;
    }

    public function validate(array $envelope, ?PortfolioProfile $profile = null): ValidationResult
    {
        $envelope['artifact_type'] = ArtifactType::INDICATOR;

        return $this->validator->validateEnvelope($envelope, $profile);
    }

    public function exportOne(string $idOrSlug, ?PortfolioProfile $profile = null): array
    {
        $env = $this->get($idOrSlug, $profile);
        if ($env === null) {
            throw new InvalidArgumentException("Indicator not found: {$idOrSlug}");
        }

        return ArtifactEnvelope::withFreshHash($env);
    }

    private function projectDefinition(IndicatorDefinition $def): array
    {
        $definition = [
            'registry_id' => $def->id,
            'indicator_kind' => $def->type,
            'registry_category' => $def->category,
            'definition_version' => $def->version,
            'units' => $def->units,
            'precision' => $def->precision,
            'parameters' => $def->parameters,
            'depends_on' => $def->dependsOn,
            'capabilities' => [
                'screenable' => $def->screenable,
                'strategy_scorable' => $def->hasCapability(IndicatorCapability::STRATEGY_SCORABLE),
                'evaluation_fact' => $def->hasCapability(IndicatorCapability::EVALUATION_FACT),
                'chartable' => $def->chartable,
                'needs_volume' => $def->hasCapability(IndicatorCapability::NEEDS_VOLUME),
            ],
            'consumers' => $def->consumers,
            'aliases' => $def->aliases,
            'status' => $def->status,
            'documentation' => [
                'summary' => $def->description,
                'notes' => array_values(array_filter([
                    $def->formulaExplanation ? 'See in-app Registry formula explanation (documentation only).' : null,
                ])),
            ],
        ];

        $deps = [];
        foreach ($def->dependsOn as $dep) {
            $deps[] = [
                'kind' => 'uses_indicator',
                'artifact_type' => ArtifactType::INDICATOR,
                'ref' => $dep,
                'ref_scheme' => 'registry_id',
                'resolution' => 'runtime_registry',
                'required' => true,
            ];
        }

        return ArtifactEnvelope::make(
            ArtifactType::INDICATOR,
            $def->id,
            $def->displayName,
            $definition,
            [
                'scope' => ArtifactScope::SYSTEM,
                'status' => $def->status === 'planned' ? ArtifactStatus::DRAFT : ArtifactStatus::ACTIVE,
                'origin' => ArtifactOrigin::FACTORY,
                'factory_key' => 'indicator.'.$def->id,
                'description' => $def->description,
                'intent' => $def->description,
                'summary' => $def->description,
                'tags' => [$def->category, $def->type],
                'category' => $def->category,
                'storage' => 'indicator_registry',
            ],
            $deps,
            1,
            $def->id,
        );
    }

    /**
     * @param  array<string, mixed>  $env
     * @param  array<string, mixed>  $filters
     */
    private function matchesFilters(array $env, array $filters): bool
    {
        if (isset($filters['q']) && trim((string) $filters['q']) !== '') {
            $q = mb_strtolower(trim((string) $filters['q']));
            $hay = mb_strtolower(($env['slug'] ?? '').' '.($env['name'] ?? '').' '.($env['metadata']['description'] ?? ''));
            if (! str_contains($hay, $q)) {
                return false;
            }
        }
        if (isset($filters['status']) && ($env['metadata']['status'] ?? '') !== $filters['status']) {
            return false;
        }

        return true;
    }
}
