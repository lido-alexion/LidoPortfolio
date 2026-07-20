<?php

namespace App\Services\Screener;

use InvalidArgumentException;

class ScreenerDefinitionValidator
{
    /**
     * @param  array<string,mixed>  $definition
     * @return array{root:array}
     */
    public function validate(array $definition): array
    {
        if (! isset($definition['root']) || ! is_array($definition['root'])) {
            throw new InvalidArgumentException('definition_json.root is required.');
        }

        $conditionCount = 0;
        $this->validateNode($definition['root'], 1, $conditionCount);

        if ($conditionCount < 1) {
            throw new InvalidArgumentException('Screener needs at least one condition.');
        }
        if ($conditionCount > ScreenerCatalog::MAX_CONDITIONS) {
            throw new InvalidArgumentException('Too many conditions (max '.ScreenerCatalog::MAX_CONDITIONS.').');
        }

        return ['root' => $definition['root']];
    }

    /**
     * @param  array<string,mixed>  $node
     */
    private function validateNode(array $node, int $depth, int &$conditionCount): void
    {
        if ($depth > ScreenerCatalog::MAX_NESTING_DEPTH) {
            throw new InvalidArgumentException('Condition nesting exceeds max depth of '.ScreenerCatalog::MAX_NESTING_DEPTH.'.');
        }

        $type = $node['type'] ?? null;
        if ($type === 'group') {
            $op = strtoupper((string) ($node['op'] ?? ''));
            if (! in_array($op, ['AND', 'OR'], true)) {
                throw new InvalidArgumentException('Group op must be AND or OR.');
            }
            $children = $node['children'] ?? null;
            if (! is_array($children) || $children === []) {
                throw new InvalidArgumentException('Group must have children.');
            }
            foreach ($children as $child) {
                if (! is_array($child)) {
                    throw new InvalidArgumentException('Invalid group child.');
                }
                $this->validateNode($child, $depth + 1, $conditionCount);
            }

            return;
        }

        if ($type === 'condition') {
            $conditionCount++;
            $this->validateOperand($node['left'] ?? null, 'left');
            $op = (string) ($node['operator'] ?? '');
            if (! in_array($op, ['gt', 'gte', 'lt', 'lte', 'eq'], true)) {
                throw new InvalidArgumentException('Invalid condition operator.');
            }
            $this->validateOperand($node['right'] ?? null, 'right');

            return;
        }

        throw new InvalidArgumentException('Node type must be group or condition.');
    }

    private function validateOperand(mixed $operand, string $side): void
    {
        if (! is_array($operand)) {
            throw new InvalidArgumentException("Condition {$side} is required.");
        }
        if (($operand['type'] ?? null) === 'constant') {
            if (! is_numeric($operand['value'] ?? null)) {
                throw new InvalidArgumentException("Condition {$side} constant must be numeric.");
            }

            return;
        }
        $id = (string) ($operand['indicator'] ?? '');
        if (! in_array($id, ScreenerCatalog::indicatorIds(), true)) {
            throw new InvalidArgumentException("Unknown indicator on {$side}: {$id}");
        }
        $params = is_array($operand['params'] ?? null) ? $operand['params'] : [];
        $this->validateParams($id, $params);
    }

    /**
     * @param  array<string,mixed>  $params
     */
    private function validateParams(string $id, array $params): void
    {
        $schema = null;
        foreach (ScreenerCatalog::indicators() as $ind) {
            if ($ind['id'] === $id) {
                $schema = $ind;
                break;
            }
        }
        if ($schema === null) {
            return;
        }
        foreach ($schema['params'] as $param) {
            $pid = $param['id'];
            if (! array_key_exists($pid, $params)) {
                continue;
            }
            $v = $params[$pid];
            if (! is_numeric($v)) {
                throw new InvalidArgumentException("Param {$pid} must be numeric for {$id}.");
            }
            $v = (float) $v;
            if ($v < (float) $param['min'] || $v > (float) $param['max']) {
                throw new InvalidArgumentException("Param {$pid} out of range for {$id}.");
            }
        }
    }
}
