<?php

namespace App\Services\Artifacts;

use App\Models\PortfolioProfile;
use App\Models\TradingStrategy;
use App\Models\TradingStrategyVersion;
use App\Services\Artifacts\Contracts\ArtifactRegistryInterface;
use App\Services\Strategy\StrategyRegistrySupport;
use App\Services\StrategyConfigurationService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Strategy Artifact Registry — envelope I/O over portfolio_tos_strategies (BC).
 * Create/import stores drafts (not enabled). Enable (activate) does not disable other enabled strategies.
 * Eligibility refs are Screener slug/factory_key only in export — never embedded trees.
 */
final class StrategyArtifactRegistry implements ArtifactRegistryInterface
{
    public const ENABLEMENT_RULE = 'multiple_enabled_per_portfolio';
    public function __construct(
        private StrategyConfigurationService $strategies,
        private ArtifactValidationService $validator,
        private StrategyRegistrySupport $support,
    ) {}

    public function type(): string
    {
        return ArtifactType::STRATEGY;
    }

    public function list(?PortfolioProfile $profile = null, array $filters = []): array
    {
        if ($profile === null) {
            return [];
        }
        $this->strategies->ensureActive($profile);
        $rows = TradingStrategy::query()
            ->where('profile_id', $profile->id)
            ->with('activeVersion')
            ->orderByDesc('id')
            ->get();
        $out = [];
        foreach ($rows as $strategy) {
            $version = $strategy->activeVersion
                ?? TradingStrategyVersion::query()->where('strategy_id', $strategy->id)->orderByDesc('id')->first();
            if (! $version) {
                continue;
            }
            $this->support->ensureRegistryFields($strategy, $version);
            $env = $this->project($strategy->fresh(), $version->fresh());
            if ($this->matches($env, $filters)) {
                $out[] = $env;
            }
        }

        return $out;
    }

    public function get(string $idOrSlug, ?PortfolioProfile $profile = null): ?array
    {
        if ($profile === null) {
            return null;
        }
        $this->strategies->ensureActive($profile);
        $strategy = $this->findStrategy($profile, $idOrSlug);
        if (! $strategy) {
            return null;
        }
        $version = $strategy->activeVersion
            ?? TradingStrategyVersion::query()->where('strategy_id', $strategy->id)->orderByDesc('id')->first();
        if (! $version) {
            return null;
        }
        $this->support->ensureRegistryFields($strategy, $version);

        return $this->project($strategy->fresh(), $version->fresh());
    }

    public function create(array $envelope, ?PortfolioProfile $profile = null): array
    {
        if ($profile === null) {
            throw new InvalidArgumentException('Portfolio context required for Strategy create.');
        }
        $envelope['artifact_type'] = ArtifactType::STRATEGY;
        $envelope = $this->normalizeScoringAlias($envelope);
        $envelope = ArtifactEnvelope::withFreshHash($envelope);
        $result = $this->validate($envelope, $profile);
        if (! $result->ok) {
            throw new InvalidArgumentException('Validation failed: '.json_encode($result->toArray()));
        }

        $config = $this->envelopeToConfig($envelope, $profile);
        $meta = is_array($envelope['metadata'] ?? null) ? $envelope['metadata'] : [];
        $desiredSlug = (string) ($envelope['slug'] ?? '');
        if ($desiredSlug === '') {
            $desiredSlug = $this->support->slugify(
                (string) ($envelope['name'] ?? 'Imported Strategy'),
                isset($meta['factory_key']) ? (string) $meta['factory_key'] : null
            );
        }
        $slug = $this->support->uniqueSlug($profile, $desiredSlug);

        return DB::transaction(function () use ($profile, $envelope, $config, $meta, $slug) {
            $hash = $this->support->hashDefinition($config);
            $strategy = TradingStrategy::query()->create([
                'profile_id' => $profile->id,
                'name' => (string) ($envelope['name'] ?? 'Imported Strategy'),
                'slug' => $slug,
                'definition_hash' => $hash,
                'description' => (string) ($meta['description'] ?? $meta['summary'] ?? ''),
                'intent' => (string) ($meta['intent'] ?? ''),
                'summary' => (string) ($meta['summary'] ?? ''),
                'tags_json' => is_array($meta['tags'] ?? null) ? $meta['tags'] : [],
                'status' => TradingStrategy::STATUS_DRAFT,
                'is_factory' => ($meta['origin'] ?? '') === ArtifactOrigin::FACTORY,
                'factory_key' => $meta['factory_key'] ?? null,
            ]);

            $version = TradingStrategyVersion::query()->create([
                'strategy_id' => $strategy->id,
                'version' => 1,
                'version_label' => '1.0',
                'config_json' => $config,
                'definition_hash' => $hash,
                'status' => TradingStrategyVersion::STATUS_DRAFT,
                'change_notes' => 'Created via Strategy Registry (draft — not active)',
                'activated_at' => null,
            ]);

            $strategy->forceFill(['active_version_id' => $version->id])->save();

            return $this->project($strategy->fresh(), $version->fresh());
        });
    }

    public function update(string $idOrSlug, array $envelope, ?PortfolioProfile $profile = null): array
    {
        if ($profile === null) {
            throw new InvalidArgumentException('Portfolio context required for Strategy update.');
        }
        $strategy = $this->findStrategy($profile, $idOrSlug);
        if (! $strategy) {
            throw new InvalidArgumentException("Strategy not found: {$idOrSlug}");
        }

        $envelope['artifact_type'] = ArtifactType::STRATEGY;
        $envelope = $this->normalizeScoringAlias($envelope);
        $envelope = ArtifactEnvelope::withFreshHash($envelope);
        $result = $this->validate($envelope, $profile);
        if (! $result->ok) {
            throw new InvalidArgumentException('Validation failed: '.json_encode($result->toArray()));
        }

        $config = $this->envelopeToConfig($envelope, $profile);
        $meta = is_array($envelope['metadata'] ?? null) ? $envelope['metadata'] : [];
        $hash = $this->support->hashDefinition($config);

        if ($strategy->status === TradingStrategy::STATUS_ACTIVE) {
            $this->strategies->updateActiveConfig(
                $profile,
                $config,
                (string) ($envelope['name'] ?? $strategy->name),
                (string) ($meta['description'] ?? $strategy->description),
                'Updated via Strategy Registry',
            );
            $strategy = $strategy->fresh(['activeVersion']);
            $strategy->forceFill([
                'definition_hash' => $hash,
                'intent' => (string) ($meta['intent'] ?? $strategy->intent ?? ''),
                'summary' => (string) ($meta['summary'] ?? $strategy->summary ?? ''),
                'tags_json' => is_array($meta['tags'] ?? null) ? $meta['tags'] : $strategy->tags_json,
            ])->save();
            if ($strategy->activeVersion) {
                $strategy->activeVersion->forceFill(['definition_hash' => $hash])->save();
            }

            return $this->project($strategy->fresh(['activeVersion']), $strategy->activeVersion);
        }

        return DB::transaction(function () use ($strategy, $envelope, $config, $meta, $hash) {
            $prev = TradingStrategyVersion::query()->where('strategy_id', $strategy->id)->orderByDesc('id')->firstOrFail();
            $prevHash = $prev->definition_hash ?: $this->support->hashDefinition(is_array($prev->config_json) ? $prev->config_json : []);
            $nextVersion = (int) $prev->version;
            if ($prevHash !== $hash) {
                $nextVersion = max(1, $nextVersion + 1);
                $prev->forceFill(['status' => TradingStrategyVersion::STATUS_SUPERSEDED])->save();
                $version = TradingStrategyVersion::query()->create([
                    'strategy_id' => $strategy->id,
                    'version' => $nextVersion,
                    'version_label' => $nextVersion.'.0',
                    'config_json' => $config,
                    'definition_hash' => $hash,
                    'status' => TradingStrategyVersion::STATUS_DRAFT,
                    'change_notes' => (string) ($meta['change_notes'] ?? 'Updated draft via Strategy Registry'),
                    'activated_at' => null,
                ]);
            } else {
                $prev->forceFill([
                    'config_json' => $config,
                    'definition_hash' => $hash,
                    'change_notes' => (string) ($meta['change_notes'] ?? 'Metadata update via Strategy Registry'),
                ])->save();
                $version = $prev;
            }

            $strategy->forceFill([
                'name' => (string) ($envelope['name'] ?? $strategy->name),
                'description' => (string) ($meta['description'] ?? $strategy->description),
                'intent' => (string) ($meta['intent'] ?? $strategy->intent ?? ''),
                'summary' => (string) ($meta['summary'] ?? $strategy->summary ?? ''),
                'tags_json' => is_array($meta['tags'] ?? null) ? $meta['tags'] : $strategy->tags_json,
                'definition_hash' => $hash,
                'active_version_id' => $version->id,
            ])->save();

            return $this->project($strategy->fresh(), $version->fresh());
        });
    }

    public function validate(array $envelope, ?PortfolioProfile $profile = null): ValidationResult
    {
        $envelope['artifact_type'] = ArtifactType::STRATEGY;
        $envelope = $this->normalizeScoringAlias($envelope);

        return $this->validator->validateEnvelope($envelope, $profile);
    }

    public function exportOne(string $idOrSlug, ?PortfolioProfile $profile = null): array
    {
        $env = $this->get($idOrSlug, $profile);
        if ($env === null) {
            throw new InvalidArgumentException("Strategy not found: {$idOrSlug}");
        }

        return $env;
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @return array<string, mixed>
     */
    public function importEnvelope(array $envelope, PortfolioProfile $profile): array
    {
        $envelope['artifact_type'] = ArtifactType::STRATEGY;
        $envelope = $this->normalizeScoringAlias($envelope);
        $envelope = ArtifactEnvelope::withFreshHash($envelope);
        $result = $this->validate($envelope, $profile);
        if (! $result->ok) {
            throw new InvalidArgumentException('Validation failed: '.json_encode($result->toArray()));
        }

        $slug = (string) ($envelope['slug'] ?? '');
        if ($slug !== '' && TradingStrategy::query()->where('profile_id', $profile->id)->where('slug', $slug)->exists()) {
            $envelope['slug'] = $slug.'_import_'.substr(bin2hex(random_bytes(3)), 0, 6);
        }
        $name = (string) ($envelope['name'] ?? 'Imported Strategy');
        if (TradingStrategy::query()->where('profile_id', $profile->id)->where('name', $name)->exists()) {
            $envelope['name'] = $name.' (import)';
        }

        return $this->create($envelope, $profile);
    }

    /**
     * Enable this strategy. Other enabled strategies in the portfolio remain enabled.
     *
     * @return array<string, mixed>
     */
    public function activate(string $idOrSlug, PortfolioProfile $profile): array
    {
        $strategy = $this->findStrategy($profile, $idOrSlug);
        if (! $strategy) {
            throw new InvalidArgumentException("Strategy not found: {$idOrSlug}");
        }
        $activated = $this->support->activate($profile, $strategy);
        $version = $activated->activeVersion
            ?? TradingStrategyVersion::query()->where('strategy_id', $activated->id)->orderByDesc('id')->firstOrFail();

        return $this->project($activated, $version);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listVersions(string $idOrSlug, PortfolioProfile $profile): array
    {
        $strategy = $this->findStrategy($profile, $idOrSlug);
        if (! $strategy) {
            throw new InvalidArgumentException("Strategy not found: {$idOrSlug}");
        }

        return TradingStrategyVersion::query()
            ->where('strategy_id', $strategy->id)
            ->orderByDesc('version')
            ->get()
            ->map(fn (TradingStrategyVersion $v) => [
                'version' => $v->version,
                'version_label' => $v->version_label,
                'status' => $v->status,
                'definition_hash' => $v->definition_hash,
                'change_notes' => $v->change_notes,
                'activated_at' => optional($v->activated_at)?->toIso8601String(),
                'created_at' => optional($v->created_at)?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function meta(PortfolioProfile $profile): array
    {
        $this->strategies->ensureActive($profile);
        $rows = TradingStrategy::query()->where('profile_id', $profile->id)->get();
        $active = $rows->where('status', TradingStrategy::STATUS_ACTIVE)->count();
        $draft = $rows->where('status', TradingStrategy::STATUS_DRAFT)->count();
        $archived = $rows->where('status', TradingStrategy::STATUS_ARCHIVED)->count();
        $factory = $rows->where('is_factory', true)->count();

        return [
            'counts' => [
                'total' => $rows->count(),
                'active' => $active,
                'draft' => $draft,
                'archived' => $archived,
                'factory' => $factory,
            ],
            'statuses' => [
                ['id' => 'active', 'label' => 'Active (enabled)'],
                ['id' => 'draft', 'label' => 'Draft'],
                ['id' => 'archived', 'label' => 'Archived'],
            ],
            'origins' => [
                ['id' => 'factory', 'label' => 'Factory'],
                ['id' => 'user', 'label' => 'User'],
            ],
            'schema_version' => ArtifactType::SCHEMA_VERSION,
            'minimum_engine_version' => ArtifactType::MINIMUM_ENGINE_VERSION,
            'selection_rule' => self::ENABLEMENT_RULE,
            'enablement_rule' => self::ENABLEMENT_RULE,
        ];
    }

    private function findStrategy(PortfolioProfile $profile, string $idOrSlug): ?TradingStrategy
    {
        if (ctype_digit($idOrSlug)) {
            return TradingStrategy::query()
                ->where('profile_id', $profile->id)
                ->where('id', (int) $idOrSlug)
                ->with('activeVersion')
                ->first();
        }

        return TradingStrategy::query()
            ->where('profile_id', $profile->id)
            ->where(function ($q) use ($idOrSlug) {
                $q->where('slug', $idOrSlug)
                    ->orWhere('factory_key', $idOrSlug)
                    ->orWhere('name', $idOrSlug);
            })
            ->with('activeVersion')
            ->first();
    }

    private function project(TradingStrategy $strategy, TradingStrategyVersion $version): array
    {
        $config = is_array($version->config_json) ? $version->config_json : [];
        $portable = $this->support->toPortableDefinition($config);
        $scoring = $portable['scoring_model'] ?? $portable['indicators'] ?? [];
        $portable['scoring_model'] = $scoring;

        $slug = $strategy->slug ?: ($strategy->factory_key === 'momentum_factory'
            ? 'momentum_strategy'
            : ($strategy->factory_key ?: ('strategy_'.$strategy->id)));

        $deps = [];
        foreach ($portable['eligibility_sources'] ?? [] as $src) {
            if (! is_array($src)) {
                continue;
            }
            $ref = (string) ($src['screener_slug'] ?? $src['screener_factory_key'] ?? '');
            if ($ref === '') {
                continue;
            }
            $deps[] = [
                'kind' => 'uses_screener',
                'artifact_type' => ArtifactType::SCREENER,
                'ref' => $ref,
                'ref_scheme' => isset($src['screener_factory_key']) ? 'factory_key' : 'slug',
                'resolution' => 'registry',
                'required' => true,
            ];
        }
        foreach ($scoring as $row) {
            if (! is_array($row) || empty($row['key'])) {
                continue;
            }
            $deps[] = [
                'kind' => 'uses_indicator',
                'artifact_type' => ArtifactType::INDICATOR,
                'ref' => (string) $row['key'],
                'ref_scheme' => 'registry_id',
                'resolution' => 'runtime_registry',
                'required' => (bool) ($row['enabled'] ?? false),
            ];
        }

        $status = match ($strategy->status) {
            TradingStrategy::STATUS_ACTIVE => ArtifactStatus::ACTIVE,
            TradingStrategy::STATUS_ARCHIVED => ArtifactStatus::ARCHIVED,
            default => ArtifactStatus::DRAFT,
        };

        $tags = is_array($strategy->tags_json) ? $strategy->tags_json : [];
        if ($tags === [] && $strategy->factory_key) {
            $tags = [$strategy->factory_key];
        }

        return ArtifactEnvelope::make(
            ArtifactType::STRATEGY,
            $slug,
            (string) $strategy->name,
            $portable,
            [
                'scope' => ArtifactScope::PORTFOLIO,
                'status' => $status,
                'origin' => $strategy->is_factory ? ArtifactOrigin::FACTORY : ArtifactOrigin::USER,
                'factory_key' => $strategy->factory_key,
                'description' => (string) ($strategy->description ?? ''),
                'summary' => (string) ($strategy->summary ?? $strategy->description ?? ''),
                'intent' => (string) ($strategy->intent ?? $strategy->description ?? ''),
                'tags' => $tags,
                'storage' => 'portfolio_tos_strategies',
                'legacy_id' => $strategy->id,
                'legacy_version_id' => $version->id,
                'is_enabled' => $strategy->status === TradingStrategy::STATUS_ACTIVE,
                'is_selected' => $strategy->status === TradingStrategy::STATUS_ACTIVE,
                'allocation_pct' => $strategy->allocation_pct !== null ? (float) $strategy->allocation_pct : 100.0,
            ],
            $deps,
            max(1, (int) $version->version),
            (string) $strategy->id,
        );
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @return array<string, mixed>
     */
    private function envelopeToConfig(array $envelope, PortfolioProfile $profile): array
    {
        $def = is_array($envelope['definition'] ?? null) ? $envelope['definition'] : [];
        if (isset($def['scoring_model']) && ! isset($def['indicators'])) {
            $def['indicators'] = $def['scoring_model'];
        }
        if (isset($def['indicators']) && ! isset($def['scoring_model'])) {
            $def['scoring_model'] = $def['indicators'];
        }

        $sources = is_array($def['eligibility_sources'] ?? null) ? $def['eligibility_sources'] : [];
        if ($sources !== []) {
            $def['eligibility_sources'] = $this->support->resolveEligibilitySources($profile, $sources);
        }

        return $this->strategies->normalizeConfig($def);
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @return array<string, mixed>
     */
    private function normalizeScoringAlias(array $envelope): array
    {
        if (! isset($envelope['definition']) || ! is_array($envelope['definition'])) {
            return $envelope;
        }
        $def = $envelope['definition'];
        if (isset($def['scoring_model']) && ! isset($def['indicators'])) {
            $def['indicators'] = $def['scoring_model'];
        }
        if (isset($def['indicators']) && ! isset($def['scoring_model'])) {
            $def['scoring_model'] = $def['indicators'];
        }
        // Reject embedded screener trees early for clearer errors
        foreach ($def['eligibility_sources'] ?? [] as $src) {
            if (is_array($src) && (isset($src['definition']) || isset($src['definition_json']) || isset($src['root']))) {
                throw new InvalidArgumentException(
                    'Strategy must not embed Screener definitions. Reference screeners by screener_slug / screener_factory_key only.'
                );
            }
        }
        $envelope['definition'] = $def;

        return $envelope;
    }

    /**
     * @param  array<string, mixed>  $env
     * @param  array<string, mixed>  $filters
     */
    private function matches(array $env, array $filters): bool
    {
        if (isset($filters['q']) && trim((string) $filters['q']) !== '') {
            $q = mb_strtolower(trim((string) $filters['q']));
            $meta = is_array($env['metadata'] ?? null) ? $env['metadata'] : [];
            $hay = mb_strtolower(($env['slug'] ?? '').' '.($env['name'] ?? '').' '.($meta['intent'] ?? ''));
            if (! str_contains($hay, $q)) {
                return false;
            }
        }
        if (isset($filters['status']) && trim((string) $filters['status']) !== '') {
            if (($env['metadata']['status'] ?? '') !== (string) $filters['status']) {
                return false;
            }
        }
        if (isset($filters['origin']) && trim((string) $filters['origin']) !== '') {
            if (($env['metadata']['origin'] ?? '') !== (string) $filters['origin']) {
                return false;
            }
        }

        return true;
    }
}
