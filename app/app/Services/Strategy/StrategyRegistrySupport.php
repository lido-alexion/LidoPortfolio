<?php

namespace App\Services\Strategy;

use App\Models\PortfolioProfile;
use App\Models\Screener;
use App\Models\TradingStrategy;
use App\Models\TradingStrategyVersion;
use App\Services\Artifacts\DefinitionHasher;
use App\Services\StrategyEligibilityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Strategy Registry helpers: portable eligibility refs, activate, hash/slug.
 * Does not change Recommendation scoring algorithms.
 */
final class StrategyRegistrySupport
{
    public function __construct(
        private StrategyEligibilityService $eligibility,
    ) {}

    public function slugify(string $name, ?string $factoryKey = null, ?int $id = null): string
    {
        if ($factoryKey === 'momentum_factory') {
            return 'momentum_strategy';
        }
        if ($factoryKey !== null && trim($factoryKey) !== '') {
            $slug = Str::slug(str_replace('-', '_', $factoryKey), '_');
            if ($slug !== '') {
                return Str::limit($slug, 100, '');
            }
        }
        $slug = Str::slug(str_replace('-', '_', $name), '_');
        if ($slug === '') {
            $slug = 'strategy'.($id ? '_'.$id : '');
        }

        return Str::limit($slug, 100, '');
    }

    public function uniqueSlug(PortfolioProfile $profile, string $desired, ?int $exceptStrategyId = null): string
    {
        $base = $desired !== '' ? $desired : 'strategy';
        $slug = $base;
        $n = 2;
        while (
            TradingStrategy::query()
                ->where('profile_id', $profile->id)
                ->where('slug', $slug)
                ->when($exceptStrategyId, fn ($q) => $q->where('id', '!=', $exceptStrategyId))
                ->exists()
        ) {
            $slug = Str::limit($base.'_'.$n, 100, '');
            $n++;
        }

        return $slug;
    }

    /**
     * Resolve portable screener refs → local screener_id. Never embeds definition trees.
     *
     * @param  list<array<string, mixed>>  $sources
     * @return list<array<string, mixed>>
     */
    public function resolveEligibilitySources(PortfolioProfile $profile, array $sources): array
    {
        $out = [];
        foreach ($sources as $i => $src) {
            if (! is_array($src)) {
                continue;
            }
            $factoryKey = (string) ($src['screener_factory_key'] ?? $src['factory_key'] ?? '');
            $slug = (string) ($src['screener_slug'] ?? '');
            $sid = (int) ($src['screener_id'] ?? 0);

            $screener = null;
            if ($sid > 0) {
                $screener = Screener::query()
                    ->where('profile_id', $profile->id)
                    ->where('id', $sid)
                    ->first();
            }
            if (! $screener && $factoryKey !== '') {
                if ($factoryKey === \App\Engines\Strategy\MinerviniTrendTemplateScreener::FACTORY_KEY) {
                    $screener = $this->eligibility->ensureMinerviniScreener($profile);
                } else {
                    $screener = Screener::query()
                        ->where('profile_id', $profile->id)
                        ->where('factory_key', $factoryKey)
                        ->first();
                }
            }
            if (! $screener && $slug !== '') {
                $screener = Screener::query()
                    ->where('profile_id', $profile->id)
                    ->where(function ($q) use ($slug) {
                        $q->where('slug', $slug)->orWhere('factory_key', $slug);
                    })
                    ->first();
            }

            if (! $screener) {
                $label = $factoryKey ?: ($slug ?: ('#'.$sid));
                throw new InvalidArgumentException(
                    "Cannot resolve Screener reference \"{$label}\" in this portfolio. Import or create the Screener first (strategies never embed Screener definitions)."
                );
            }

            // Ensure Minervini factory screener has registry fields if freshly created
            if ($screener->slug === null || $screener->slug === '') {
                $screener->forceFill([
                    'slug' => $screener->factory_key ?: ('screener_'.$screener->id),
                ])->save();
            }

            $out[] = [
                'screener_id' => $screener->id,
                'screener_name' => $screener->name,
                'factory_key' => $screener->factory_key ?: ($factoryKey !== '' ? $factoryKey : null),
                'screener_factory_key' => $screener->factory_key ?: ($factoryKey !== '' ? $factoryKey : null),
                'screener_slug' => $screener->slug ?: ($slug !== '' ? $slug : $screener->factory_key),
                'enabled' => (bool) ($src['enabled'] ?? true),
                'priority' => (int) ($src['priority'] ?? ($i + 1)),
                'display_order' => (int) ($src['display_order'] ?? $i),
                'min_artifact_version' => isset($src['min_artifact_version']) ? (int) $src['min_artifact_version'] : null,
            ];
        }

        return $out;
    }

    /**
     * Strip portfolio-local ids for export (registry / factory keys only).
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function toPortableDefinition(array $config): array
    {
        $def = $config;
        if (isset($def['eligibility_sources']) && is_array($def['eligibility_sources'])) {
            $def['eligibility_sources'] = array_values(array_map(function ($src) {
                if (! is_array($src)) {
                    return $src;
                }
                $factoryKey = (string) ($src['screener_factory_key'] ?? $src['factory_key'] ?? '');
                $slug = (string) ($src['screener_slug'] ?? '');
                if ($slug === '' && $factoryKey !== '') {
                    $slug = $factoryKey;
                }
                // If only screener_id was stored, try to recover portable keys from DB
                if ($slug === '' && $factoryKey === '' && ! empty($src['screener_id'])) {
                    $s = Screener::query()->find((int) $src['screener_id']);
                    if ($s) {
                        $factoryKey = (string) ($s->factory_key ?? '');
                        $slug = (string) ($s->slug ?: $s->factory_key ?: '');
                    }
                }
                $row = [
                    'enabled' => (bool) ($src['enabled'] ?? true),
                    'priority' => (int) ($src['priority'] ?? 1),
                ];
                if ($slug !== '') {
                    $row['screener_slug'] = $slug;
                }
                if ($factoryKey !== '') {
                    $row['screener_factory_key'] = $factoryKey;
                }
                if (isset($src['min_artifact_version'])) {
                    $row['min_artifact_version'] = (int) $src['min_artifact_version'];
                }
                // Never export portfolio-specific screener_id
                return $row;
            }, $def['eligibility_sources']));
        }

        // Prefer scoring_model alias in portable JSON; keep indicators for BC consumers
        if (isset($def['indicators']) && ! isset($def['scoring_model'])) {
            $def['scoring_model'] = $def['indicators'];
        }

        return $def;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    public function hashDefinition(array $definition): string
    {
        return DefinitionHasher::hash($this->toPortableDefinition($definition));
    }

    /**
     * Enable a strategy for the portfolio without disabling other enabled strategies.
     */
    public function activate(PortfolioProfile $profile, TradingStrategy $strategy): TradingStrategy
    {
        if ((int) $strategy->profile_id !== (int) $profile->id) {
            throw new InvalidArgumentException('Strategy does not belong to the active portfolio.');
        }

        return DB::transaction(function () use ($profile, $strategy) {
            $version = $strategy->activeVersion
                ?? TradingStrategyVersion::query()->where('strategy_id', $strategy->id)->orderByDesc('id')->first();
            if (! $version) {
                throw new InvalidArgumentException('Strategy has no version to activate.');
            }

            $config = is_array($version->config_json) ? $version->config_json : [];
            $sources = is_array($config['eligibility_sources'] ?? null) ? $config['eligibility_sources'] : [];
            // Re-resolve portable refs if any lack local ids
            $needsResolve = false;
            foreach ($sources as $src) {
                if (! is_array($src) || (int) ($src['screener_id'] ?? 0) < 1) {
                    $needsResolve = true;
                    break;
                }
            }
            if ($needsResolve && $sources !== []) {
                $resolved = $this->resolveEligibilitySources($profile, $sources);
                $config['eligibility_sources'] = $resolved;
                $version->forceFill([
                    'config_json' => $config,
                    'definition_hash' => $this->hashDefinition($config),
                ])->save();
            }

            TradingStrategyVersion::query()
                ->where('strategy_id', $strategy->id)
                ->where('id', '!=', $version->id)
                ->where('status', TradingStrategyVersion::STATUS_ACTIVE)
                ->update(['status' => TradingStrategyVersion::STATUS_SUPERSEDED]);

            $version->forceFill([
                'status' => TradingStrategyVersion::STATUS_ACTIVE,
                'activated_at' => now(),
            ])->save();

            $strategy->forceFill([
                'status' => TradingStrategy::STATUS_ACTIVE,
                'active_version_id' => $version->id,
            ])->save();

            $this->eligibility->syncStrategyScreeners(
                $version,
                is_array($config['eligibility_sources'] ?? null) ? $config['eligibility_sources'] : []
            );

            return $strategy->fresh(['activeVersion']);
        });
    }

    public function ensureRegistryFields(TradingStrategy $strategy, TradingStrategyVersion $version): void
    {
        $config = is_array($version->config_json) ? $version->config_json : [];
        $hash = $this->hashDefinition($config);
        $dirty = false;
        if ($strategy->slug === null || $strategy->slug === '') {
            $strategy->slug = $this->uniqueSlug(
                PortfolioProfile::query()->findOrFail($strategy->profile_id),
                $this->slugify((string) $strategy->name, $strategy->factory_key, $strategy->id),
                $strategy->id
            );
            $dirty = true;
        }
        if ($strategy->definition_hash !== $hash) {
            $strategy->definition_hash = $hash;
            $dirty = true;
        }
        if ($dirty) {
            $strategy->save();
        }
        if (($version->definition_hash ?? null) !== $hash) {
            $version->forceFill(['definition_hash' => $hash])->save();
        }
    }
}
