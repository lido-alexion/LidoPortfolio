<?php

namespace App\Services\Indicators;

use InvalidArgumentException;

/**
 * In-memory Indicator Registry — metadata & discovery only (SD-033 / Epic 1).
 *
 * Does not calculate indicators. Does not replace ScreenerCatalog or
 * SupportedIndicators at runtime (Epic 2). Seeded via {@see IndicatorRegistryFactory}.
 */
final class IndicatorRegistry
{
    /** @var array<string, IndicatorDefinition> */
    private array $byId = [];

    /**
     * @param  list<IndicatorDefinition>  $definitions
     */
    public function __construct(array $definitions = [])
    {
        foreach ($definitions as $definition) {
            $this->register($definition);
        }
    }

    public function register(IndicatorDefinition $definition): void
    {
        if (isset($this->byId[$definition->id])) {
            throw new InvalidArgumentException("Duplicate indicator id: {$definition->id}");
        }
        $this->byId[$definition->id] = $definition;
    }

    public function has(string $id): bool
    {
        return isset($this->byId[$id]);
    }

    public function get(string $id): IndicatorDefinition
    {
        if (! isset($this->byId[$id])) {
            throw new InvalidArgumentException("Unknown indicator id: {$id}");
        }

        return $this->byId[$id];
    }

    public function find(string $id): ?IndicatorDefinition
    {
        return $this->byId[$id] ?? null;
    }

    /**
     * Resolve legacy alias → canonical id, or return id if registered.
     */
    public function resolveId(string $idOrAlias): ?string
    {
        if (isset($this->byId[$idOrAlias])) {
            return $idOrAlias;
        }
        foreach ($this->byId as $definition) {
            if (in_array($idOrAlias, $definition->aliases, true)) {
                return $definition->id;
            }
        }

        return null;
    }

    /**
     * @return list<IndicatorDefinition>
     */
    public function all(): array
    {
        return array_values($this->byId);
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_keys($this->byId);
    }

    public function count(): int
    {
        return count($this->byId);
    }

    /**
     * @param  array{
     *     type?: string,
     *     category?: string,
     *     status?: string,
     *     screenable?: bool,
     *     chartable?: bool,
     *     strategy_scorable?: bool,
     *     consumer?: string,
     *     visible?: bool
     * }  $criteria
     * @return list<IndicatorDefinition>
     */
    public function filter(array $criteria = []): array
    {
        $out = [];
        foreach ($this->byId as $definition) {
            if (isset($criteria['type']) && $definition->type !== $criteria['type']) {
                continue;
            }
            if (isset($criteria['category']) && $definition->category !== $criteria['category']) {
                continue;
            }
            if (isset($criteria['status']) && $definition->status !== $criteria['status']) {
                continue;
            }
            if (array_key_exists('screenable', $criteria) && $definition->screenable !== (bool) $criteria['screenable']) {
                continue;
            }
            if (array_key_exists('chartable', $criteria) && $definition->chartable !== (bool) $criteria['chartable']) {
                continue;
            }
            if (array_key_exists('visible', $criteria) && $definition->visible !== (bool) $criteria['visible']) {
                continue;
            }
            if (! empty($criteria['strategy_scorable']) && ! $definition->hasCapability(IndicatorCapability::STRATEGY_SCORABLE)) {
                continue;
            }
            if (isset($criteria['consumer']) && ! in_array($criteria['consumer'], $definition->consumers, true)) {
                continue;
            }
            $out[] = $definition;
        }

        return $out;
    }

    /**
     * @return list<IndicatorDefinition>
     */
    public function byType(string $type): array
    {
        return $this->filter(['type' => $type]);
    }

    /**
     * Filter then optionally narrow by free-text search (id, display name, description).
     *
     * @param  array{
     *     type?: string,
     *     category?: string,
     *     status?: string,
     *     screenable?: bool,
     *     chartable?: bool,
     *     strategy_scorable?: bool,
     *     consumer?: string,
     *     visible?: bool
     * }  $criteria
     * @return list<IndicatorDefinition>
     */
    public function search(?string $q, array $criteria = []): array
    {
        $items = $this->filter($criteria);
        if ($q === null || trim($q) === '') {
            return $items;
        }
        $needle = mb_strtolower(trim($q));

        return array_values(array_filter(
            $items,
            static function (IndicatorDefinition $definition) use ($needle): bool {
                return str_contains(mb_strtolower($definition->id), $needle)
                    || str_contains(mb_strtolower($definition->displayName), $needle)
                    || str_contains(mb_strtolower($definition->description), $needle);
            },
        ));
    }

    /**
     * Dependency ids for a composite (empty if unknown / non-composite).
     *
     * @return list<string>
     */
    public function dependencies(string $id): array
    {
        $definition = $this->find($id);

        return $definition?->dependsOn ?? [];
    }

    /**
     * Nested dependency tree (ids only). Detects cycles.
     *
     * @return array<string, mixed>
     */
    public function dependencyTree(string $id, int $maxDepth = 8): array
    {
        return $this->buildTree($id, [], $maxDepth, false);
    }

    /**
     * Nested dependency tree with display metadata for Admin UI.
     *
     * @return array<string, mixed>
     */
    public function dependencyTreeDetailed(string $id, int $maxDepth = 8): array
    {
        return $this->buildTree($id, [], $maxDepth, true);
    }

    /**
     * @param  list<string>  $stack
     * @return array<string, mixed>
     */
    private function buildTree(string $id, array $stack, int $depthLeft, bool $detailed): array
    {
        $node = ['id' => $id, 'depends_on' => []];
        if ($detailed) {
            $definition = $this->find($id);
            $node['display_name'] = $definition?->displayName ?? $id;
            $node['type'] = $definition?->type;
            $node['status'] = $definition?->status;
            $node['category'] = $definition?->category;
        }
        if (in_array($id, $stack, true)) {
            $node['cycle'] = true;

            return $node;
        }
        if ($depthLeft <= 0) {
            $node['truncated'] = true;

            return $node;
        }
        $children = [];
        foreach ($this->dependencies($id) as $dep) {
            $children[] = $this->buildTree($dep, [...$stack, $id], $depthLeft - 1, $detailed);
        }
        $node['depends_on'] = $children;

        return $node;
    }

    /**
     * Validate dependency graph: missing targets and cycles among composites.
     *
     * @return list<string> Human-readable issues (empty = OK)
     */
    public function validateDependencies(): array
    {
        $issues = [];
        foreach ($this->byId as $definition) {
            foreach ($definition->dependsOn as $dep) {
                if (! isset($this->byId[$dep])) {
                    $issues[] = "{$definition->id} depends on missing id {$dep}";
                }
            }
            if ($definition->type === IndicatorType::COMPOSITE) {
                $seen = [];
                $stack = [];
                if ($this->hasCycle($definition->id, $seen, $stack)) {
                    $issues[] = "{$definition->id} participates in a dependency cycle";
                }
            }
        }

        return $issues;
    }

    /**
     * @param  array<string, bool>  $seen
     * @param  array<string, bool>  $stack
     */
    private function hasCycle(string $id, array &$seen, array &$stack): bool
    {
        if (isset($stack[$id])) {
            return true;
        }
        if (isset($seen[$id])) {
            return false;
        }
        $seen[$id] = true;
        $stack[$id] = true;
        foreach ($this->dependencies($id) as $dep) {
            if ($this->hasCycle($dep, $seen, $stack)) {
                return true;
            }
        }
        unset($stack[$id]);

        return false;
    }
}
