<?php

namespace App\Services\Indicators;

use InvalidArgumentException;

/**
 * Immutable metadata record for one Registry entry (SD-033 §6).
 *
 * This is documentation / discovery only — it does not compute values.
 */
final readonly class IndicatorDefinition
{
    /**
     * @param  list<string>  $dependsOn  Registry ids (required for composites; may be empty for stubs)
     * @param  list<array<string, mixed>>  $parameters  Normalised param schemas
     * @param  list<string>  $consumers  {@see IndicatorConsumer} ids
     * @param  list<string>  $aliases  Legacy keys
     * @param  array<string, bool>  $capabilities  Named flags (true when set)
     * @param  array<string, mixed>  $legacy  Façade-only fields (Strategy defaults, category labels, …)
     */
    public function __construct(
        public string $id,
        public string $displayName,
        public string $description,
        public string $type,
        public string $category,
        public string $version,
        public array $dependsOn,
        public array $parameters,
        public string $units,
        public int $precision,
        public bool $visible,
        public bool $screenable,
        public bool $chartable,
        public bool $sortable,
        public bool $filterable,
        public bool $supportsHistory,
        public bool $marketLevel,
        public bool $stockLevel,
        public bool $portfolioLevel,
        public array $consumers,
        public string $status,
        public ?string $formulaExplanation = null,
        public array $aliases = [],
        public array $capabilities = [],
        public array $legacy = [],
    ) {
        if ($id === '') {
            throw new InvalidArgumentException('Indicator id must be non-empty.');
        }
        if (! IndicatorType::isValid($type)) {
            throw new InvalidArgumentException("Invalid indicator type: {$type}");
        }
        if (! IndicatorCategory::isValid($category)) {
            throw new InvalidArgumentException("Invalid indicator category: {$category}");
        }
        if (! IndicatorStatus::isValid($status)) {
            throw new InvalidArgumentException("Invalid indicator status: {$status}");
        }
        if ($type === IndicatorType::COMPOSITE && $formulaExplanation === null && $status !== IndicatorStatus::PLANNED) {
            // Planned composites may defer formula text; active/stub composites should document behaviour.
        }
        foreach ($consumers as $consumer) {
            if (! IndicatorConsumer::isValid($consumer)) {
                throw new InvalidArgumentException("Invalid indicator consumer: {$consumer}");
            }
        }
    }

    public function hasCapability(string $flag): bool
    {
        return ($this->capabilities[$flag] ?? false) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'display_name' => $this->displayName,
            'description' => $this->description,
            'type' => $this->type,
            'category' => $this->category,
            'category_label' => IndicatorCategory::labels()[$this->category] ?? $this->category,
            'version' => $this->version,
            'depends_on' => $this->dependsOn,
            'parameters' => $this->parameters,
            'units' => $this->units,
            'precision' => $this->precision,
            'visible' => $this->visible,
            'screenable' => $this->screenable,
            'chartable' => $this->chartable,
            'sortable' => $this->sortable,
            'filterable' => $this->filterable,
            'supports_history' => $this->supportsHistory,
            'market_level' => $this->marketLevel,
            'stock_level' => $this->stockLevel,
            'portfolio_level' => $this->portfolioLevel,
            'consumers' => $this->consumers,
            'status' => $this->status,
            'formula_explanation' => $this->formulaExplanation,
            'aliases' => $this->aliases,
            'capabilities' => $this->capabilities,
            'legacy' => $this->legacy,
        ];
    }

    /**
     * Fluent-ish builder defaults for seeders.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function make(string $id, string $type, string $category, array $overrides = []): self
    {
        $defaults = [
            'display_name' => $id,
            'description' => '',
            'version' => '1.0.0',
            'depends_on' => [],
            'parameters' => [],
            'units' => 'none',
            'precision' => 2,
            'visible' => true,
            'screenable' => false,
            'chartable' => false,
            'sortable' => false,
            'filterable' => false,
            'supports_history' => false,
            'market_level' => false,
            'stock_level' => true,
            'portfolio_level' => false,
            'consumers' => [IndicatorConsumer::ADMIN_REGISTRY],
            'status' => IndicatorStatus::ACTIVE,
            'formula_explanation' => null,
            'aliases' => [],
            'capabilities' => [],
            'legacy' => [],
        ];
        $data = array_merge($defaults, $overrides);

        return new self(
            id: $id,
            displayName: (string) $data['display_name'],
            description: (string) $data['description'],
            type: $type,
            category: $category,
            version: (string) $data['version'],
            dependsOn: array_values($data['depends_on']),
            parameters: array_values($data['parameters']),
            units: (string) $data['units'],
            precision: (int) $data['precision'],
            visible: (bool) $data['visible'],
            screenable: (bool) $data['screenable'],
            chartable: (bool) $data['chartable'],
            sortable: (bool) $data['sortable'],
            filterable: (bool) $data['filterable'],
            supportsHistory: (bool) $data['supports_history'],
            marketLevel: (bool) $data['market_level'],
            stockLevel: (bool) $data['stock_level'],
            portfolioLevel: (bool) $data['portfolio_level'],
            consumers: array_values($data['consumers']),
            status: (string) $data['status'],
            formulaExplanation: isset($data['formula_explanation']) ? (string) $data['formula_explanation'] : null,
            aliases: array_values($data['aliases']),
            capabilities: is_array($data['capabilities']) ? $data['capabilities'] : [],
            legacy: is_array($data['legacy'] ?? null) ? $data['legacy'] : [],
        );
    }
}
