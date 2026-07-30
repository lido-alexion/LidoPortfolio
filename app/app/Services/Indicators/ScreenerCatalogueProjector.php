<?php

namespace App\Services\Indicators;

/**
 * Projects Registry Primaries into legacy ScreenerCatalog::indicators() row shape.
 */
final class ScreenerCatalogueProjector
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function project(IndicatorRegistry $registry): array
    {
        $out = [];
        foreach ($registry->filter(['screenable' => true, 'status' => IndicatorStatus::ACTIVE]) as $def) {
            $params = [];
            foreach ($def->parameters as $param) {
                $params[] = [
                    'id' => (string) ($param['id'] ?? ''),
                    'label' => (string) ($param['label'] ?? $param['id'] ?? ''),
                    'default' => $param['default'] ?? null,
                    'min' => $param['min'] ?? null,
                    'max' => $param['max'] ?? null,
                    'step' => $param['step'] ?? 1,
                ];
            }
            $defaults = array_column($params, 'default', 'id');
            $minBarsFn = static fn (array $p): int => ScreenerMinBars::compute($def->id, $p);
            $out[] = [
                'id' => $def->id,
                'label' => $def->displayName,
                'params' => $params,
                'min_bars' => $minBarsFn($defaults),
                'min_bars_fn' => $minBarsFn,
                'needs_volume' => $def->hasCapability(IndicatorCapability::NEEDS_VOLUME),
            ];
        }

        return $out;
    }
}
