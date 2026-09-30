<?php

namespace App\Services\ML;

class MlFeatureRegistryService
{
    /**
     * @return array<string, mixed>
     */
    public function catalog(): array
    {
        $features = config('ml_feature_registry.features', []);
        $implemented = [
            ...MlTrainingDatasetBuilder::NUMERIC_FEATURES,
            ...MlTrainingDatasetBuilder::CATEGORICAL_FEATURES,
        ];

        $rows = [];
        foreach ($features as $key => $meta) {
            if (! is_array($meta)) {
                continue;
            }
            $rows[] = array_merge(['key' => $key], $this->normalizedMetadata((string) $key, $meta), [
                'implemented' => in_array($key, $implemented, true),
            ]);
        }

        return [
            'registry_version' => (string) config('ml_feature_registry.registry_version', 'v8-registry-1'),
            'implemented_count' => count(array_filter($rows, fn (array $r) => $r['implemented'])),
            'catalogue_count' => count($rows),
            'features' => $rows,
            'horizons' => MlScoringService::HORIZONS,
            'training_builder' => MlTrainingDatasetBuilder::class,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function featureSetForHorizon(string $horizon): array
    {
        return $this->resolveFeatureProfile($horizon);
    }

    /**
     * Resolve a reproducible feature profile from registry metadata and a
     * target horizon. Requested keys are validated strictly so callers cannot
     * silently train on an ineligible or unknown feature.
     *
     * @param list<string>|null $requestedKeys
     * @return array<string,mixed>
     */
    public function resolveFeatureProfile(string $horizon, ?array $requestedKeys = null): array
    {
        if (! in_array($horizon, MlScoringService::HORIZONS, true)) {
            throw new \InvalidArgumentException('Unsupported ML horizon: '.$horizon);
        }

        $implemented = [
            ...MlTrainingDatasetBuilder::NUMERIC_FEATURES,
            ...MlTrainingDatasetBuilder::CATEGORICAL_FEATURES,
        ];
        $registry = config('ml_feature_registry.features', []);
        $requestedKeys ??= $implemented;
        if (count($requestedKeys) !== count(array_unique($requestedKeys))) {
            throw new \InvalidArgumentException('Feature profile contains duplicate feature keys.');
        }

        $keys = [];
        foreach ($requestedKeys as $key) {
            if (! in_array($key, $implemented, true) || ! isset($registry[$key]) || ! is_array($registry[$key])) {
                throw new \InvalidArgumentException("Unknown ML feature key: {$key}");
            }
            $horizons = $registry[$key]['horizons'] ?? [];
            if (! is_array($horizons) || ! in_array($horizon, $horizons, true)) {
                throw new \InvalidArgumentException("Feature {$key} is not eligible for {$horizon}.");
            }
            $keys[] = $key;
        }
        if ($keys === []) {
            throw new \InvalidArgumentException("Resolved ML feature profile for {$horizon} is empty.");
        }

        $featureMetadata = [];
        foreach ($keys as $key) {
            $featureMetadata[$key] = $this->normalizedMetadata($key, $registry[$key]);
        }
        $definitionHash = $this->definitionHash($horizon, $keys);
        $featureSetVersion = $this->featureSetVersion($horizon, $keys);

        return [
            'horizon' => $horizon,
            'registry_version' => (string) config('ml_feature_registry.registry_version', 'v8-registry-1'),
            'feature_set_id' => $featureSetVersion,
            'feature_set_version' => $featureSetVersion,
            'definition_hash' => $definitionHash,
            'feature_keys' => $keys,
            'feature_versions' => array_map(fn (array $meta): string => (string) ($meta['formula_version'] ?? 'unversioned'), $featureMetadata),
            'feature_metadata' => $featureMetadata,
            'preprocessing' => config('ml_feature_registry.preprocessing', [
                'version' => 'v8-preprocessing-1',
                'missing_values' => 'median_with_missingness_flags',
                'fitted_on' => 'training_partition_only',
            ]),
            'exclusions' => array_values(array_map(
                fn (string $key): array => ['feature' => $key, 'reason' => 'horizon_ineligible'],
                array_values(array_diff($implemented, $keys)),
            )),
            'numeric' => array_values(array_intersect($keys, MlTrainingDatasetBuilder::NUMERIC_FEATURES)),
            'categorical' => array_values(array_intersect($keys, MlTrainingDatasetBuilder::CATEGORICAL_FEATURES)),
        ];
    }

    /** @param array<string,mixed> $meta @return array<string,mixed> */
    private function normalizedMetadata(string $key, array $meta): array
    {
        $group = (string) ($meta['group'] ?? 'uncategorized');
        $isCategorical = ($meta['kind'] ?? 'numeric') === 'categorical';
        $core = in_array($key, ['relative_strength_3m', 'price_return_3m', 'benchmark_trend_score', 'roe', 'debt_equity', 'operating_margin', 'net_margin', 'realized_volatility_20d'], true);
        $tier = isset($meta['tier']) ? (string) $meta['tier'] : ($core ? 'core' : 'challenger');
        $coverageClass = isset($meta['coverage_class'])
            ? (string) $meta['coverage_class']
            : (($meta['evidence_required'] ?? false) ? 'evidence_required' : ($core ? 'standard' : 'evidence_required'));
        $source = match (true) {
            str_contains($group, 'fundamental') => 'fundamental_facts_with_availability_date',
            str_contains($group, 'sector') => 'stox_sector_context',
            str_contains($group, 'market') => 'stox_benchmark_and_breadth',
            default => 'point_in_time_stock_prices',
        };
        $normalized = array_merge([
            'feature_id' => $key,
            'formula_version' => 'v8-formula-1',
            'data_source' => $source,
            'transformation' => 'code_defined_formula',
            'lookback' => $this->lookbackFor($key),
            'horizon_applicability' => $meta['horizons'] ?? [],
            'sector_applicability' => str_contains($group, 'bank') ? ['bank', 'nbfc'] : ['all'],
            'tier' => $tier,
            'coverage_class' => $coverageClass,
            'missing_value_policy' => $isCategorical ? 'unknown_category' : 'median_plus_missingness_flag',
            'pit_safety' => ($meta['pit_safe'] ?? false) ? 'point_in_time_safe' : 'not_verified',
            'deprecation' => null,
        ], $meta);
        $normalized['definition_hash'] = hash('sha256', json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $normalized;
    }

    private function lookbackFor(string $key): ?string
    {
        foreach (['12m' => ['12m'], '6m' => ['6m', '63d'], '3m' => ['3m', '20d', '21d'], '1m' => ['1m', '14d']] as $lookback => $needles) {
            foreach ($needles as $needle) if (str_contains($key, $needle)) return $lookback;
        }
        return str_contains($key, 'cagr') ? '3y' : null;
    }

    private function definitionHash(string $horizon, array $keys): string
    {
        $rows = [];
        foreach ($keys as $key) $rows[] = $this->normalizedMetadata($key, (array) config('ml_feature_registry.features.'.$key, []));
        return hash('sha256', json_encode(['horizon' => $horizon, 'features' => $rows], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function featureSetVersion(string $horizon, array $keys): string
    {
        return (string) config('ml_feature_registry.registry_version', 'v8-registry-1').'-'.$horizon.'-'.substr($this->definitionHash($horizon, $keys), 0, 12);
    }
}
