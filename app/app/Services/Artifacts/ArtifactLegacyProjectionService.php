<?php

namespace App\Services\Artifacts;

use App\Models\ArtifactBinding;
use App\Models\PortfolioProfile;
use App\Models\ReusableArtifactVersion;
use App\Models\Screener;
use App\Models\TradingStrategy;
use App\Models\TradingStrategyVersion;
use App\Services\Screener\ScreenerDefinitionValidator;
use App\Services\Strategy\StrategyRegistrySupport;
use App\Services\StrategyConfigurationService;
use App\Services\StrategyEligibilityService;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Keeps the existing Strategy/Screener identities as runtime-compatible projections
 * of authoritative immutable Library bindings.
 */
final class ArtifactLegacyProjectionService
{
    public function __construct(
        private StrategyRegistrySupport $strategySupport,
        private StrategyConfigurationService $strategyConfiguration,
        private StrategyEligibilityService $strategyEligibility,
        private ScreenerDefinitionValidator $screenerValidator,
    ) {}

    public function sync(ArtifactBinding $binding, ReusableArtifactVersion $version): void
    {
        $binding->loadMissing('profile', 'artifact', 'activeRevision');
        $content = is_array($version->content_json) ? $version->content_json : [];
        $definition = is_array($content['definition'] ?? null) ? $content['definition'] : [];
        $settings = is_array($binding->activeRevision?->settings_json) ? $binding->activeRevision->settings_json : [];

        match ($binding->artifact->artifact_type) {
            ArtifactType::STRATEGY => $this->syncStrategy($binding->profile, $binding, $version, $content, $definition, $settings),
            ArtifactType::SCREENER => $this->syncScreener($binding->profile, $binding, $version, $content, $definition, $settings),
            default => null,
        };
    }

    /** @param array<string,mixed> $content @param array<string,mixed> $definition @param array<string,mixed> $settings */
    private function syncStrategy(
        PortfolioProfile $profile,
        ArtifactBinding $binding,
        ReusableArtifactVersion $version,
        array $content,
        array $definition,
        array $settings,
    ): void {
        $strategy = TradingStrategy::query()
            ->where('profile_id', $profile->id)
            ->where('reusable_artifact_id', $binding->artifact_id)
            ->first();
        if ($binding->status === ArtifactBinding::STATUS_ENABLED) {
            $sources = is_array($definition['eligibility_sources'] ?? null) ? $definition['eligibility_sources'] : [];
            if ($sources !== []) {
                $definition['eligibility_sources'] = $this->strategySupport->resolveEligibilitySources($profile, $sources);
            }
            $definition = $this->strategyConfiguration->normalizeConfig($definition);
            $this->strategyConfiguration->validateConfig($definition);
        }

        if (! $strategy) {
            $strategy = TradingStrategy::query()->create([
                'profile_id' => $profile->id,
                'name' => $this->uniqueName(TradingStrategy::query()->where('profile_id', $profile->id), (string) ($content['name'] ?? $binding->artifact->name)),
                'slug' => $this->uniqueSlug(TradingStrategy::query()->where('profile_id', $profile->id), (string) ($content['slug'] ?? $binding->artifact->slug)),
                'definition_hash' => $version->definition_hash,
                'description' => (string) ($content['metadata']['description'] ?? ''),
                'intent' => (string) ($content['metadata']['intent'] ?? ''),
                'summary' => (string) ($content['metadata']['summary'] ?? ''),
                'tags_json' => is_array($content['metadata']['tags'] ?? null) ? $content['metadata']['tags'] : [],
                'status' => TradingStrategy::STATUS_DRAFT,
                'allocation_pct' => (float) ($settings['allocation_pct'] ?? 100),
                'is_factory' => $binding->artifact->origin === ArtifactOrigin::FACTORY,
                'factory_key' => $content['metadata']['factory_key'] ?? null,
                'reusable_artifact_id' => $binding->artifact_id,
            ]);
        }

        $legacyVersion = $strategy->activeVersion;
        if (! $legacyVersion || $legacyVersion->definition_hash !== $version->definition_hash) {
            if ($legacyVersion) {
                $legacyVersion->forceFill(['status' => TradingStrategyVersion::STATUS_SUPERSEDED])->save();
            }
            $legacyVersion = TradingStrategyVersion::query()->create([
                'strategy_id' => $strategy->id,
                'version' => ((int) $strategy->versions()->max('version')) + 1,
                'version_label' => $version->semver,
                'config_json' => $definition,
                'definition_hash' => $version->definition_hash,
                'status' => TradingStrategyVersion::STATUS_DRAFT,
                'change_notes' => 'Compatibility projection of immutable artifact '.$version->semver,
            ]);
        }
        $enabled = $binding->status === ArtifactBinding::STATUS_ENABLED;
        $legacyVersion->forceFill([
            'config_json' => $definition,
            'status' => $enabled ? TradingStrategyVersion::STATUS_ACTIVE : TradingStrategyVersion::STATUS_DRAFT,
            'activated_at' => $enabled ? ($legacyVersion->activated_at ?? now()) : null,
        ])->save();
        $strategy->forceFill([
            'status' => $enabled ? TradingStrategy::STATUS_ACTIVE : TradingStrategy::STATUS_DRAFT,
            'active_version_id' => $legacyVersion->id,
            'definition_hash' => $version->definition_hash,
            'allocation_pct' => (float) ($settings['allocation_pct'] ?? $strategy->allocation_pct ?? 100),
        ])->save();
        $this->strategyEligibility->syncStrategyScreeners(
            $legacyVersion,
            is_array($definition['eligibility_sources'] ?? null) ? $definition['eligibility_sources'] : [],
        );
    }

    /** @param array<string,mixed> $content @param array<string,mixed> $definition @param array<string,mixed> $settings */
    private function syncScreener(
        PortfolioProfile $profile,
        ArtifactBinding $binding,
        ReusableArtifactVersion $version,
        array $content,
        array $definition,
        array $settings,
    ): void {
        $definition = $this->screenerValidator->validate($definition);
        $screener = Screener::query()
            ->where('profile_id', $profile->id)
            ->where('reusable_artifact_id', $binding->artifact_id)
            ->first();
        $metadata = is_array($content['metadata'] ?? null) ? $content['metadata'] : [];
        if (! $screener) {
            $universe = (string) ($metadata['universe'] ?? 'all_equities');
            $scope = match ($universe) {
                'all', 'all_active_equities' => 'all_equities',
                'portfolio', 'holding' => 'holdings',
                default => in_array($universe, ['holdings', 'watchlist', 'all_equities', 'index'], true) ? $universe : 'all_equities',
            };
            if (in_array($scope, ['watchlist', 'index'], true)) {
                throw new InvalidArgumentException('A new bound Screener projection cannot use watchlist/index scope without Portfolio-specific scope settings.');
            }
            $screener = Screener::query()->create([
                'profile_id' => $profile->id,
                'name' => $this->uniqueName(Screener::query()->where('profile_id', $profile->id), (string) ($content['name'] ?? $binding->artifact->name)),
                'slug' => $this->uniqueSlug(Screener::query()->where('profile_id', $profile->id), (string) ($content['slug'] ?? $binding->artifact->slug)),
                'artifact_version' => 1,
                'definition_hash' => $version->definition_hash,
                'description' => (string) ($metadata['description'] ?? ''),
                'intent' => (string) ($metadata['intent'] ?? ''),
                'summary' => (string) ($metadata['summary'] ?? ''),
                'tags_json' => is_array($metadata['tags'] ?? null) ? $metadata['tags'] : [],
                'artifact_status' => ArtifactStatus::ACTIVE,
                'scope' => $scope,
                'definition_json' => $definition,
                'telegram_enabled' => false,
                'is_enabled' => false,
                'is_factory' => $binding->artifact->origin === ArtifactOrigin::FACTORY,
                'factory_key' => $metadata['factory_key'] ?? null,
                'reusable_artifact_id' => $binding->artifact_id,
            ]);
        }
        if ($screener->definition_hash !== $version->definition_hash) {
            $screener->artifact_version = max(1, (int) $screener->artifact_version + 1);
        }
        $screener->forceFill([
            'definition_json' => $definition,
            'definition_hash' => $version->definition_hash,
            'is_enabled' => $binding->status === ArtifactBinding::STATUS_ENABLED,
            'schedule_enabled' => (bool) ($settings['schedule_enabled'] ?? $screener->schedule_enabled ?? false),
            'schedule_time' => $settings['schedule_time'] ?? $screener->schedule_time,
            'schedule_days' => is_array($settings['schedule_days'] ?? null) ? $settings['schedule_days'] : ($screener->schedule_days ?? []),
            'telegram_enabled' => (bool) ($settings['telegram_enabled'] ?? $screener->telegram_enabled ?? false),
        ])->save();
    }

    private function uniqueName($query, string $desired): string
    {
        $base = trim($desired) !== '' ? trim($desired) : 'Artifact';
        $candidate = $base;
        $suffix = 2;
        while ((clone $query)->where('name', $candidate)->exists()) {
            $candidate = $base.' ('.$suffix++.')';
        }

        return $candidate;
    }

    private function uniqueSlug($query, string $desired): string
    {
        $base = Str::slug($desired, '_') ?: 'artifact';
        $candidate = $base;
        $suffix = 2;
        while ((clone $query)->where('slug', $candidate)->exists()) {
            $candidate = $base.'_'.$suffix++;
        }

        return $candidate;
    }
}
