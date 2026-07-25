<?php

namespace App\Services;

use App\Engines\Strategy\ExitStrategyEvaluator;
use App\Engines\Strategy\FactoryMomentumStrategy;
use App\Engines\Strategy\MinerviniTrendTemplateScreener;
use App\Engines\Strategy\SupportedIndicators;
use App\Models\PortfolioProfile;
use App\Models\Screener;
use App\Models\TradingStrategy;
use App\Models\TradingStrategyVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Strategy Configuration (SD-027 / SD-028 / SD-029 / SD-030).
 * Scoring + portfolio rules; eligibility via Screeners (not duplicated conditions).
 */
class StrategyConfigurationService
{
    public function __construct(
        protected StrategyEligibilityService $eligibility,
    ) {}
    /**
     * @return array<string, mixed>
     */
    public function defaultConfig(): array
    {
        return FactoryMomentumStrategy::config();
    }

    /**
     * Seed or return the active strategy version for a profile.
     */
    public function ensureActive(PortfolioProfile $profile): TradingStrategyVersion
    {
        $strategy = TradingStrategy::query()
            ->where('profile_id', $profile->id)
            ->where('status', TradingStrategy::STATUS_ACTIVE)
            ->with('activeVersion')
            ->first();

        if ($strategy?->activeVersion) {
            return $strategy->activeVersion;
        }

        return DB::transaction(function () use ($profile) {
            $existing = TradingStrategy::query()
                ->where('profile_id', $profile->id)
                ->where('status', TradingStrategy::STATUS_ACTIVE)
                ->lockForUpdate()
                ->first();

            if ($existing?->active_version_id) {
                $ver = TradingStrategyVersion::query()->find($existing->active_version_id);
                if ($ver) {
                    return $ver;
                }
            }

            return $this->seedFactoryStrategy($profile)->activeVersion;
        });
    }

    /**
     * Create the protected factory Momentum Strategy (idempotent per profile).
     */
    public function seedFactoryStrategy(PortfolioProfile $profile): TradingStrategy
    {
        return DB::transaction(function () use ($profile) {
            $factory = TradingStrategy::query()
                ->where('profile_id', $profile->id)
                ->where('factory_key', FactoryMomentumStrategy::FACTORY_KEY)
                ->lockForUpdate()
                ->first();

            if ($factory?->active_version_id) {
                $ver = TradingStrategyVersion::query()->find($factory->active_version_id);
                if ($ver) {
                    if ($factory->status !== TradingStrategy::STATUS_ACTIVE) {
                        TradingStrategy::query()
                            ->where('profile_id', $profile->id)
                            ->where('id', '!=', $factory->id)
                            ->where('status', TradingStrategy::STATUS_ACTIVE)
                            ->update(['status' => TradingStrategy::STATUS_ARCHIVED]);
                        $factory->forceFill(['status' => TradingStrategy::STATUS_ACTIVE])->save();
                    }
                    $this->ensureEligibilityLinked($profile, $ver);

                    return $factory->fresh(['activeVersion']);
                }
            }

            TradingStrategy::query()
                ->where('profile_id', $profile->id)
                ->where('status', TradingStrategy::STATUS_ACTIVE)
                ->update(['status' => TradingStrategy::STATUS_ARCHIVED]);

            if (! $factory) {
                $factory = TradingStrategy::query()->create([
                    'profile_id' => $profile->id,
                    'name' => FactoryMomentumStrategy::NAME,
                    'description' => FactoryMomentumStrategy::DESCRIPTION,
                    'status' => TradingStrategy::STATUS_ACTIVE,
                    'is_factory' => true,
                    'factory_key' => FactoryMomentumStrategy::FACTORY_KEY,
                    'duplicated_from_id' => null,
                ]);
            } else {
                $factory->forceFill([
                    'name' => FactoryMomentumStrategy::NAME,
                    'description' => FactoryMomentumStrategy::DESCRIPTION,
                    'status' => TradingStrategy::STATUS_ACTIVE,
                    'is_factory' => true,
                ])->save();
            }

            $minervini = $this->eligibility->ensureMinerviniScreener($profile);
            $config = $this->normalizeConfig($this->defaultConfig());
            $config['eligibility_sources'] = [
                [
                    'screener_id' => $minervini->id,
                    'screener_name' => $minervini->name,
                    'factory_key' => MinerviniTrendTemplateScreener::FACTORY_KEY,
                    'enabled' => true,
                    'priority' => 1,
                    'display_order' => 0,
                ],
            ];
            $this->validateConfig($config);

            $version = TradingStrategyVersion::query()->create([
                'strategy_id' => $factory->id,
                'version' => 1,
                'version_label' => FactoryMomentumStrategy::VERSION_LABEL,
                'config_json' => $config,
                'status' => TradingStrategyVersion::STATUS_ACTIVE,
                'change_notes' => 'Factory Momentum Strategy 1.0 — eligibility: Minervini Trend Template',
                'activated_at' => now(),
            ]);

            $this->eligibility->syncStrategyScreeners($version, $config['eligibility_sources']);

            $factory->forceFill([
                'active_version_id' => $version->id,
            ])->save();

            return $factory->fresh(['activeVersion']);
        });
    }

    public function getActiveStrategy(PortfolioProfile $profile): array
    {
        $version = $this->ensureActive($profile);
        $strategy = $version->strategy ?? TradingStrategy::query()->findOrFail($version->strategy_id);

        return $this->serializeStrategy($strategy, $version);
    }

    /**
     * Duplicate the active strategy (or a specific strategy) into an editable copy.
     * Factory remains untouched; copy becomes active.
     *
     * @param  array<string, mixed>|null  $configOverride
     */
    public function duplicateStrategy(
        PortfolioProfile $profile,
        ?int $strategyId = null,
        ?string $name = null,
        ?array $configOverride = null,
        ?string $changeNotes = null,
    ): array {
        $source = $strategyId
            ? TradingStrategy::query()->where('profile_id', $profile->id)->findOrFail($strategyId)
            : TradingStrategy::query()->findOrFail($this->ensureActive($profile)->strategy_id);

        $sourceVersion = $source->activeVersion
            ?? TradingStrategyVersion::query()->findOrFail($source->active_version_id);

        $baseConfig = $this->normalizeConfig($sourceVersion->config_json ?? []);
        if ($configOverride !== null) {
            $baseConfig = $this->normalizeConfig(array_replace_recursive($baseConfig, $configOverride));
            if (isset($configOverride['indicators'])) {
                $baseConfig = $this->normalizeConfig(array_merge($baseConfig, ['indicators' => $configOverride['indicators']]));
            }
        }
        $this->validateConfig($baseConfig);

        $copyName = $name !== null && trim($name) !== ''
            ? trim($name)
            : ($source->is_factory
                ? FactoryMomentumStrategy::NAME.' (Custom)'
                : $source->name.' (Copy)');

        return DB::transaction(function () use ($profile, $source, $baseConfig, $copyName, $changeNotes) {
            TradingStrategy::query()
                ->where('profile_id', $profile->id)
                ->where('status', TradingStrategy::STATUS_ACTIVE)
                ->update(['status' => TradingStrategy::STATUS_ARCHIVED]);

            $copy = TradingStrategy::query()->create([
                'profile_id' => $profile->id,
                'name' => $copyName,
                'description' => $source->description,
                'status' => TradingStrategy::STATUS_ACTIVE,
                'is_factory' => false,
                'factory_key' => null,
                'duplicated_from_id' => $source->id,
            ]);

            $version = TradingStrategyVersion::query()->create([
                'strategy_id' => $copy->id,
                'version' => 1,
                'version_label' => '1.0',
                'config_json' => $baseConfig,
                'status' => TradingStrategyVersion::STATUS_ACTIVE,
                'change_notes' => $changeNotes ?? 'Duplicated from '.($source->is_factory ? 'factory strategy' : $source->name),
                'activated_at' => now(),
            ]);

            $copy->forceFill(['active_version_id' => $version->id])->save();
            $this->eligibility->syncStrategyScreeners($version, $baseConfig['eligibility_sources'] ?? []);

            return $this->serializeStrategy($copy->fresh(), $version);
        });
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function updateActiveConfig(
        PortfolioProfile $profile,
        array $config,
        ?string $name = null,
        ?string $description = null,
        ?string $changeNotes = null,
    ): array {
        $normalized = $this->normalizeConfig($config);
        $this->validateConfig($normalized);
        $version = $this->ensureActive($profile);
        $strategy = TradingStrategy::query()->findOrFail($version->strategy_id);

        // Factory is protected: save forks into a new editable strategy.
        if ($strategy->is_factory) {
            return $this->duplicateStrategy(
                $profile,
                $strategy->id,
                $name !== null && trim($name) !== '' ? trim($name) : FactoryMomentumStrategy::NAME.' (Custom)',
                $normalized,
                $changeNotes ?? 'Customised from factory Momentum Strategy 1.0',
            );
        }

        return DB::transaction(function () use ($strategy, $normalized, $name, $description, $changeNotes) {
            TradingStrategyVersion::query()
                ->where('strategy_id', $strategy->id)
                ->where('status', TradingStrategyVersion::STATUS_ACTIVE)
                ->update(['status' => TradingStrategyVersion::STATUS_SUPERSEDED]);

            $next = ((int) TradingStrategyVersion::query()->where('strategy_id', $strategy->id)->max('version')) + 1;
            $newVersion = TradingStrategyVersion::query()->create([
                'strategy_id' => $strategy->id,
                'version' => $next,
                'version_label' => $next.'.0',
                'config_json' => $normalized,
                'status' => TradingStrategyVersion::STATUS_ACTIVE,
                'change_notes' => $changeNotes,
                'activated_at' => now(),
            ]);

            $strategy->forceFill([
                'name' => $name !== null && trim($name) !== '' ? trim($name) : $strategy->name,
                'description' => $description !== null ? $description : $strategy->description,
                'status' => TradingStrategy::STATUS_ACTIVE,
                'active_version_id' => $newVersion->id,
            ])->save();

            $this->eligibility->syncStrategyScreeners($newVersion, $normalized['eligibility_sources'] ?? []);

            return $this->serializeStrategy($strategy->fresh(), $newVersion);
        });
    }

    /**
     * @param  array<string, float|int|null>  $indicatorScores
     * @param  array<string, mixed>  $config
     * @return array{
     *     overall_score: float,
     *     breakdown: list<array<string, mixed>>,
     *     enabled_factor_count: int,
     *     enabled_indicator_count: int
     * }
     */
    public function score(array $indicatorScores, array $config): array
    {
        $config = $this->normalizeConfig($config);
        $scores = SupportedIndicators::canonicalizeScoreMap($indicatorScores);
        $indicators = $config['indicators'] ?? [];
        $breakdown = [];
        $totalWeight = 0.0;
        $earned = 0.0;
        $enabled = 0;

        foreach ($indicators as $indicator) {
            if (! ($indicator['enabled'] ?? false)) {
                continue;
            }
            $enabled++;
            $key = (string) ($indicator['key'] ?? '');
            $weight = (float) ($indicator['weight'] ?? 0);
            if ($weight <= 0 || $key === '' || ! SupportedIndicators::isSupported($key)) {
                continue;
            }
            $totalWeight += $weight;
            $raw = $scores[$key] ?? null;
            $value = is_numeric($raw) ? (float) $raw : null;
            $gated = false;

            if ($value === null) {
                $contribution = 0.0;
                $gated = true;
            } else {
                $min = isset($indicator['minimum']) && $indicator['minimum'] !== null && $indicator['minimum'] !== ''
                    ? (float) $indicator['minimum'] : null;
                $max = isset($indicator['maximum']) && $indicator['maximum'] !== null && $indicator['maximum'] !== ''
                    ? (float) $indicator['maximum'] : null;

                if ($min !== null && $value < $min) {
                    $contribution = 0.0;
                    $gated = true;
                } elseif ($max !== null && $value > $max) {
                    $contribution = 0.0;
                    $gated = true;
                } else {
                    $normalized = max(0.0, min(100.0, $value)) / 100.0;
                    if ($key === SupportedIndicators::RISK_SCORE && $max !== null && $max > 0) {
                        $normalized = max(0.0, min(1.0, 1.0 - ($value / 100.0)));
                    }
                    $contribution = round($normalized * $weight, 4);
                }
            }

            $earned += $contribution;
            $breakdown[] = [
                'key' => $key,
                'category' => (string) ($indicator['category'] ?? ''),
                'display_name' => (string) ($indicator['display_name'] ?? $key),
                'weight' => $weight,
                'value' => $value,
                'contribution' => $contribution,
                'max_contribution' => $weight,
                'gated' => $gated,
            ];
        }

        $overall = $totalWeight > 0
            ? round(($earned / $totalWeight) * 100.0, 4)
            : 0.0;

        return [
            'overall_score' => max(0.0, min(100.0, $overall)),
            'breakdown' => $breakdown,
            'enabled_factor_count' => $enabled,
            'enabled_indicator_count' => $enabled,
        ];
    }

    public function allocationPctForScore(float $score, array $config): float
    {
        $bands = $config['capital_allocation']['score_bands'] ?? [];
        $default = (float) ($config['portfolio_rules']['default_position_size_pct'] ?? 5);

        foreach ($bands as $band) {
            $min = (float) ($band['min'] ?? 0);
            $max = (float) ($band['max'] ?? 100);
            if ($score >= $min && $score < $max) {
                return (float) ($band['allocation_pct'] ?? $default);
            }
            if ($score >= $min && $score <= $max && $max >= 100) {
                return (float) ($band['allocation_pct'] ?? $default);
            }
        }

        return $default;
    }

    /**
     * Enabled indicator weight total (for UI / validation messages).
     *
     * @param  array<string, mixed>  $config
     */
    public function enabledWeightTotal(array $config): float
    {
        $sum = 0.0;
        foreach ($config['indicators'] ?? [] as $indicator) {
            if ($indicator['enabled'] ?? false) {
                $sum += (float) ($indicator['weight'] ?? 0);
            }
        }

        return round($sum, 4);
    }

    /**
     * Merge incoming config onto factory defaults; drop unknown indicator keys.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function normalizeConfig(array $config): array
    {
        $base = $this->defaultConfig();
        $incomingList = $config['indicators'] ?? $config['scoring_model'] ?? $config['factors'] ?? [];
        $byKey = [];
        if (is_array($incomingList)) {
            foreach ($incomingList as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $key = SupportedIndicators::canonicalizeKey((string) ($row['key'] ?? ''));
                if (! SupportedIndicators::isSupported($key)) {
                    continue;
                }
                $byKey[$key] = $row;
            }
        }

        $baseByKey = [];
        foreach ($base['indicators'] as $row) {
            $baseByKey[$row['key']] = $row;
        }

        $indicators = [];
        foreach (SupportedIndicators::definitions() as $def) {
            $key = $def['key'];
            $row = $byKey[$key] ?? [];
            $factoryRow = $baseByKey[$key] ?? [];
            $params = is_array($row['parameters'] ?? null) ? $row['parameters'] : [];
            $defaultParams = [];
            foreach ($def['parameters'] as $paramKey => $meta) {
                $defaultParams[$paramKey] = $params[$paramKey]
                    ?? ($factoryRow['parameters'][$paramKey] ?? $meta['default']);
            }

            $indicators[] = [
                'key' => $key,
                'category' => $def['category'],
                'display_name' => $def['display_name'],
                'description' => $def['description'],
                'enabled' => array_key_exists('enabled', $row)
                    ? (bool) $row['enabled']
                    : (bool) ($factoryRow['enabled'] ?? $def['default_enabled']),
                'weight' => array_key_exists('weight', $row)
                    ? (float) $row['weight']
                    : (float) ($factoryRow['weight'] ?? $def['default_weight']),
                'minimum' => array_key_exists('minimum', $row)
                    ? ($row['minimum'] === null || $row['minimum'] === '' ? null : (float) $row['minimum'])
                    : ($factoryRow['minimum'] ?? $def['default_minimum']),
                'maximum' => array_key_exists('maximum', $row)
                    ? ($row['maximum'] === null || $row['maximum'] === '' ? null : (float) $row['maximum'])
                    : ($factoryRow['maximum'] ?? $def['default_maximum']),
                'supports_maximum' => (bool) $def['supports_maximum'],
                'parameters' => $defaultParams,
            ];
        }

        $eligibility = [];
        $incomingEligibility = $config['eligibility_sources'] ?? null;
        if (is_array($incomingEligibility)) {
            foreach ($incomingEligibility as $i => $src) {
                if (! is_array($src)) {
                    continue;
                }
                $sid = (int) ($src['screener_id'] ?? 0);
                if ($sid < 1) {
                    continue;
                }
                $eligibility[] = [
                    'screener_id' => $sid,
                    'screener_name' => (string) ($src['screener_name'] ?? ''),
                    'factory_key' => $src['factory_key'] ?? null,
                    'enabled' => (bool) ($src['enabled'] ?? true),
                    'priority' => (int) ($src['priority'] ?? ($i + 1)),
                    'display_order' => (int) ($src['display_order'] ?? $i),
                ];
            }
        }

        $exitBase = $base['exit_strategy'] ?? [
            'enabled' => true,
            'mode' => 'any',
            'rules' => ExitStrategyEvaluator::defaultRules(),
        ];
        $exitIncoming = is_array($config['exit_strategy'] ?? null) ? $config['exit_strategy'] : [];
        $exitRules = is_array($exitIncoming['rules'] ?? null)
            ? $exitIncoming['rules']
            : ($exitBase['rules'] ?? ExitStrategyEvaluator::defaultRules());

        return [
            'eligibility_sources' => $eligibility,
            'indicators' => $indicators,
            'scoring_model' => $indicators,
            'thresholds' => array_merge($base['thresholds'], is_array($config['thresholds'] ?? null) ? $config['thresholds'] : []),
            'portfolio_rules' => array_merge($base['portfolio_rules'], is_array($config['portfolio_rules'] ?? null) ? $config['portfolio_rules'] : []),
            'capital_allocation' => array_replace_recursive(
                $base['capital_allocation'],
                is_array($config['capital_allocation'] ?? null) ? $config['capital_allocation'] : []
            ),
            'cash_rules' => array_merge(
                $base['cash_rules'] ?? [],
                is_array($config['cash_rules'] ?? null) ? $config['cash_rules'] : []
            ),
            'exit_strategy' => [
                'enabled' => array_key_exists('enabled', $exitIncoming)
                    ? (bool) $exitIncoming['enabled']
                    : (bool) ($exitBase['enabled'] ?? true),
                'mode' => (string) ($exitIncoming['mode'] ?? $exitBase['mode'] ?? 'any'),
                'rules' => $exitRules,
            ],
            'market_gates' => array_merge(
                $base['market_gates'] ?? [
                    'enabled' => false,
                    'min_sentiment' => 45,
                    'allowed_phases' => [],
                    'max_risk_raw' => 70,
                ],
                is_array($config['market_gates'] ?? null) ? $config['market_gates'] : []
            ),
            'recommendation_behaviour' => array_merge(
                $base['recommendation_behaviour'],
                is_array($config['recommendation_behaviour'] ?? null) ? $config['recommendation_behaviour'] : []
            ),
            'risk' => array_merge($base['risk'], is_array($config['risk'] ?? null) ? $config['risk'] : []),
        ];
    }

    /**
     * Backfill Minervini eligibility on older factory versions (SD-030 migration).
     */
    protected function ensureEligibilityLinked(PortfolioProfile $profile, TradingStrategyVersion $version): void
    {
        $config = is_array($version->config_json) ? $version->config_json : [];
        $sources = $config['eligibility_sources'] ?? [];
        if (is_array($sources) && $sources !== []) {
            $this->eligibility->syncStrategyScreeners($version, $sources);

            return;
        }

        $minervini = $this->eligibility->ensureMinerviniScreener($profile);
        $sources = [
            [
                'screener_id' => $minervini->id,
                'screener_name' => $minervini->name,
                'factory_key' => MinerviniTrendTemplateScreener::FACTORY_KEY,
                'enabled' => true,
                'priority' => 1,
                'display_order' => 0,
            ],
        ];
        $config = $this->normalizeConfig(array_merge($config, ['eligibility_sources' => $sources]));
        $version->forceFill(['config_json' => $config])->save();
        $this->eligibility->syncStrategyScreeners($version, $sources);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function validateConfig(array $config): void
    {
        $indicators = $config['indicators'] ?? null;
        if (! is_array($indicators) || $indicators === []) {
            throw ValidationException::withMessages(['indicators' => ['Indicator configuration is required.']]);
        }

        $weightSum = 0.0;
        $enabled = [];
        $seen = [];
        foreach ($indicators as $i => $indicator) {
            $key = (string) ($indicator['key'] ?? '');
            if (! SupportedIndicators::isSupported($key)) {
                throw ValidationException::withMessages(["indicators.$i.key" => ["Unsupported indicator: {$key}"]]);
            }
            if (isset($seen[$key])) {
                throw ValidationException::withMessages(["indicators.$i.key" => ['Duplicate indicator key.']]);
            }
            $seen[$key] = true;
            if ($indicator['enabled'] ?? false) {
                $w = (float) ($indicator['weight'] ?? 0);
                if ($w < 0) {
                    throw ValidationException::withMessages(["indicators.$i.weight" => ['Weight cannot be negative.']]);
                }
                $weightSum += $w;
                $enabled[] = [
                    'key' => $key,
                    'display_name' => (string) ($indicator['display_name'] ?? $key),
                    'weight' => $w,
                ];
            }
        }

        foreach (SupportedIndicators::keys() as $requiredKey) {
            if (! isset($seen[$requiredKey])) {
                throw ValidationException::withMessages(['indicators' => ["Missing catalogue indicator: {$requiredKey}"]]);
            }
        }

        if ($weightSum <= 0) {
            throw ValidationException::withMessages(['indicators' => ['At least one enabled indicator must have a positive weight.']]);
        }

        // Exact 100 — do not silently normalise.
        if (abs($weightSum - 100.0) > 0.01) {
            $delta = round($weightSum - 100.0, 2);
            $list = collect($enabled)
                ->map(fn ($e) => $e['display_name'].' ('.$e['weight'].')')
                ->implode(', ');
            $hint = $delta > 0
                ? "Reduce enabled weights by {$delta} points."
                : 'Increase enabled weights by '.abs($delta).' points.';

            throw ValidationException::withMessages([
                'indicators' => [
                    "Enabled indicator weights must sum to exactly 100 (current total: {$weightSum}). {$hint} Enabled: {$list}. Weights are not normalised automatically.",
                ],
                'weight_total' => [$weightSum],
            ]);
        }
    }

    public function enabledIndicatorCount(array $config): int
    {
        $n = 0;
        foreach ($config['indicators'] ?? $config['factors'] ?? [] as $f) {
            if ($f['enabled'] ?? false) {
                $n++;
            }
        }

        return $n;
    }

    /** @deprecated use enabledIndicatorCount */
    public function enabledFactorCount(array $config): int
    {
        return $this->enabledIndicatorCount($config);
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeStrategy(TradingStrategy $strategy, TradingStrategyVersion $version): array
    {
        $config = $this->normalizeConfig($version->config_json ?? []);
        $indicators = $config['indicators'] ?? [];
        $weightTotal = $this->enabledWeightTotal($config);
        $versionLabel = $version->version_label
            ?: ((string) $version->version).(str_contains((string) $version->version, '.') ? '' : '.0');

        $eligibility = [];
        foreach ($config['eligibility_sources'] ?? [] as $src) {
            $screener = Screener::query()->find((int) ($src['screener_id'] ?? 0));
            $eligibility[] = array_merge($src, [
                'screener_name' => $screener?->name ?? ($src['screener_name'] ?? ''),
                'description' => $screener?->description,
                'scope' => $screener?->scope,
                'is_factory_screener' => (bool) ($screener?->is_factory),
                'condition_count' => $this->countScreenerConditions($screener?->definition_json),
            ]);
        }
        $config['eligibility_sources'] = $eligibility;

        return [
            'id' => $strategy->id,
            'name' => $strategy->name,
            'description' => $strategy->description,
            'status' => $strategy->status,
            'is_factory' => (bool) $strategy->is_factory,
            'is_protected' => (bool) $strategy->is_factory,
            'factory_key' => $strategy->factory_key,
            'duplicated_from_id' => $strategy->duplicated_from_id,
            'version' => $version->version,
            'version_label' => $versionLabel,
            'version_id' => $version->id,
            'version_status' => $version->status,
            'change_notes' => $version->change_notes,
            'created_at' => optional($strategy->created_at)?->toIso8601String(),
            'modified_at' => optional($version->updated_at ?? $strategy->updated_at)?->toIso8601String(),
            'activated_at' => optional($version->activated_at)?->toIso8601String(),
            'enabled_indicator_count' => $this->enabledIndicatorCount($config),
            'enabled_factor_count' => $this->enabledIndicatorCount($config),
            'weight_total' => $weightTotal,
            'weights_valid' => abs($weightTotal - 100.0) <= 0.01,
            'catalogue' => SupportedIndicators::byCategory(),
            'config' => $config,
            'eligibility_sources' => $eligibility,
            'indicators' => $indicators,
            'scoring_model' => $indicators,
            'factors' => $indicators,
            'thresholds' => $config['thresholds'] ?? [],
            'portfolio_rules' => $config['portfolio_rules'] ?? [],
            'capital_allocation' => $config['capital_allocation'] ?? [],
            'cash_rules' => $config['cash_rules'] ?? [],
            'exit_strategy' => $config['exit_strategy'] ?? [],
            'market_gates' => $config['market_gates'] ?? [],
            'recommendation_behaviour' => $config['recommendation_behaviour'] ?? [],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $definition
     */
    protected function countScreenerConditions(?array $definition): int
    {
        if ($definition === null) {
            return 0;
        }
        $count = 0;
        $walk = function ($node) use (&$walk, &$count): void {
            if (! is_array($node)) {
                return;
            }
            if (($node['type'] ?? '') === 'condition') {
                $count++;

                return;
            }
            foreach ($node['children'] ?? [] as $child) {
                $walk($child);
            }
        };
        $walk($definition['root'] ?? $definition);

        return $count;
    }

    public function summaryCard(PortfolioProfile $profile): array
    {
        $payload = $this->getActiveStrategy($profile);

        return [
            'name' => $payload['name'],
            'version' => $payload['version'],
            'version_label' => $payload['version_label'],
            'is_factory' => $payload['is_factory'],
            'modified_at' => $payload['modified_at'],
            'enabled_factor_count' => $payload['enabled_indicator_count'],
            'enabled_indicator_count' => $payload['enabled_indicator_count'],
            'eligibility_source_count' => count($payload['eligibility_sources'] ?? []),
            'weight_total' => $payload['weight_total'],
            'status' => $payload['status'],
        ];
    }
}
