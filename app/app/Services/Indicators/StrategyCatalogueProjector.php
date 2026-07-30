<?php

namespace App\Services\Indicators;

/**
 * Projects Registry Composites into legacy SupportedIndicators::definitions() row shape.
 */
final class StrategyCatalogueProjector
{
    /**
     * Active + stub strategy_scorable entries only (excludes Liquidity/Tradability
     * composites which are active but intentionally not strategy_scorable).
     *
     * @return list<array<string, mixed>>
     */
    public static function project(IndicatorRegistry $registry): array
    {
        $out = [];
        foreach ($registry->all() as $def) {
            if ($def->type !== IndicatorType::COMPOSITE) {
                continue;
            }
            if (! $def->hasCapability(IndicatorCapability::STRATEGY_SCORABLE)) {
                continue;
            }
            if (! in_array($def->status, [IndicatorStatus::ACTIVE, IndicatorStatus::STUB], true)) {
                continue;
            }

            $legacy = $def->legacy;
            $params = $legacy['strategy_parameters'] ?? [];
            if ($params === [] && $def->parameters !== []) {
                foreach ($def->parameters as $param) {
                    $pid = (string) ($param['id'] ?? '');
                    if ($pid === '') {
                        continue;
                    }
                    $params[$pid] = [
                        'type' => (string) ($param['type'] ?? 'integer'),
                        'label' => (string) ($param['label'] ?? $pid),
                        'default' => $param['default'] ?? null,
                    ];
                }
            }

            $out[] = [
                'key' => $def->id,
                'category' => (string) ($legacy['strategy_category_label'] ?? IndicatorCategory::labels()[$def->category] ?? $def->category),
                'display_name' => $def->displayName,
                'description' => $def->description,
                'supports_maximum' => (bool) ($legacy['supports_maximum'] ?? $def->hasCapability(IndicatorCapability::SUPPORTS_MAXIMUM)),
                'default_enabled' => (bool) ($legacy['default_enabled'] ?? true),
                'default_weight' => $legacy['default_weight'] ?? 0,
                'default_minimum' => $legacy['default_minimum'] ?? 0,
                'default_maximum' => $legacy['default_maximum'] ?? null,
                'parameters' => $params,
            ];
        }

        return $out;
    }
}
