<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

final class DashboardLayoutDefinition
{
    private const SCHEMA = 'stox.dashboard.layout';
    private const VERSION = 1;
    private const CARDS = [
        'portfolio-summary' => ['sizes' => ['small', 'medium', 'large'], 'default' => 'large'],
        'top-movers' => ['sizes' => ['small', 'medium'], 'default' => 'medium'],
        'market-diagnostics' => ['sizes' => ['medium', 'large'], 'default' => 'large'],
        'alerts' => ['sizes' => ['medium', 'large'], 'default' => 'large'],
        'calendar' => ['sizes' => ['medium', 'large'], 'default' => 'medium'],
        'patterns' => ['sizes' => ['medium', 'large'], 'default' => 'large'],
        'relative-strength' => ['sizes' => ['medium', 'large'], 'default' => 'medium'],
        'allocation' => ['sizes' => ['medium', 'large'], 'default' => 'medium'],
        'portfolio-growth' => ['sizes' => ['large'], 'default' => 'large'],
    ];
    private const FIELDS = ['portfolio_value', 'invested_value', 'total_gain_loss', 'xirr', 'cash_available'];
    private const CORE_FIELDS = ['portfolio_value', 'invested_value', 'total_gain_loss'];

    public static function normalize(mixed $definition): array
    {
        if (!is_array($definition) || ($definition['schema'] ?? null) !== self::SCHEMA || !is_int($definition['version'] ?? null) || $definition['version'] < 1 || $definition['version'] > self::VERSION) {
            throw ValidationException::withMessages(['definition' => 'Unsupported dashboard schema or version.']);
        }

        $result = ['schema' => self::SCHEMA, 'version' => self::VERSION, 'locked' => ($definition['locked'] ?? false) === true];
        foreach (['desktop', 'mobile'] as $variant) {
            $source = $definition[$variant] ?? [];
            if (!is_array($source)) throw ValidationException::withMessages(["definition.$variant" => 'Layout variant must be an object.']);

            $cardsById = [];
            foreach (is_array($source['cards'] ?? null) ? $source['cards'] : [] as $item) {
                if (!is_array($item) || !is_string($item['id'] ?? null) || !isset(self::CARDS[$item['id']]) || isset($cardsById[$item['id']])) continue;
                $id = $item['id'];
                $spec = self::CARDS[$id];
                $cardsById[$id] = [
                    'id' => $id,
                    'order' => self::order($item['order'] ?? array_search($id, array_keys(self::CARDS), true), array_search($id, array_keys(self::CARDS), true)),
                    'size' => in_array($item['size'] ?? null, $spec['sizes'], true) ? $item['size'] : $spec['default'],
                    'visible' => $id === 'portfolio-summary' || ($item['visible'] ?? true) !== false,
                ];
            }
            foreach (self::CARDS as $id => $spec) {
                if (!isset($cardsById[$id])) $cardsById[$id] = ['id' => $id, 'order' => array_search($id, array_keys(self::CARDS), true), 'size' => $spec['default'], 'visible' => true];
            }
            if (!array_filter($cardsById, fn ($card) => $card['visible'])) $cardsById['portfolio-summary']['visible'] = true;

            $fieldsById = [];
            foreach (is_array($source['summaryFields'] ?? null) ? $source['summaryFields'] : [] as $item) {
                if (!is_array($item) || !in_array($item['id'] ?? null, self::FIELDS, true) || isset($fieldsById[$item['id']])) continue;
                $id = $item['id'];
                $fieldsById[$id] = [
                    'id' => $id,
                    'order' => self::order($item['order'] ?? array_search($id, self::FIELDS, true), array_search($id, self::FIELDS, true)),
                    'visible' => in_array($id, self::CORE_FIELDS, true) || ($item['visible'] ?? true) !== false,
                ];
            }
            foreach (self::FIELDS as $id) {
                if (!isset($fieldsById[$id])) $fieldsById[$id] = ['id' => $id, 'order' => array_search($id, self::FIELDS, true), 'visible' => true];
            }
            $result[$variant] = ['cards' => array_values($cardsById), 'summaryFields' => array_values($fieldsById)];
        }
        return $result;
    }

    private static function order(mixed $value, int $fallback): int
    {
        return is_numeric($value) && is_finite((float) $value) ? max(0, min(100, (int) $value)) : $fallback;
    }
}
