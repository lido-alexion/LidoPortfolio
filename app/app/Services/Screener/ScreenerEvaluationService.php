<?php

namespace App\Services\Screener;

class ScreenerEvaluationService
{
    public function __construct(
        protected TechnicalIndicatorService $indicators,
    ) {}

    /**
     * Max OHLCV rows required by the definition tree.
     *
     * @param  array{root:array}  $definition
     */
    public function maxLookback(array $definition): int
    {
        return max(1, $this->nodeLookback($definition['root'] ?? []));
    }

    /**
     * @param  array{root:array}  $definition
     * @param  list<array{open:?float,high:?float,low:?float,close:?float,volume:?float,adjusted_close?:?float}>  $bars
     * @return array{matched:bool,skipped:bool,skip_reason:?string,metrics:array<string,mixed>}
     */
    public function evaluateStock(array $definition, array $bars): array
    {
        $lookback = $this->maxLookback($definition);
        $engine = $this->indicators->withBars($bars);
        $validCount = $this->countValidCloses($bars);

        if ($validCount < $lookback) {
            return [
                'matched' => false,
                'skipped' => true,
                'skip_reason' => 'insufficient_data',
                'metrics' => [],
            ];
        }

        if ($this->treeNeedsVolume($definition['root'] ?? []) && ! $this->hasVolumeHistory($bars, $lookback)) {
            return [
                'matched' => false,
                'skipped' => true,
                'skip_reason' => 'insufficient_volume',
                'metrics' => [],
            ];
        }

        $metrics = [];
        $matched = $this->evalNode($definition['root'] ?? [], $engine, $metrics);

        return [
            'matched' => $matched,
            'skipped' => false,
            'skip_reason' => null,
            'metrics' => $metrics,
        ];
    }

    /**
     * @param  array<string,mixed>  $node
     */
    private function nodeLookback(array $node): int
    {
        $type = $node['type'] ?? null;
        if ($type === 'group') {
            $max = 0;
            foreach ($node['children'] ?? [] as $child) {
                if (is_array($child)) {
                    $max = max($max, $this->nodeLookback($child));
                }
            }

            return $max;
        }
        if ($type === 'condition') {
            $left = is_array($node['left'] ?? null) ? $node['left'] : [];
            $right = is_array($node['right'] ?? null) ? $node['right'] : [];

            return max(
                $this->indicators->minBarsFor($left),
                $this->indicators->minBarsFor($right),
            );
        }

        return 1;
    }

    /**
     * @param  array<string,mixed>  $node
     * @param  array<string,mixed>  $metrics
     */
    private function evalNode(array $node, TechnicalIndicatorService $engine, array &$metrics): bool
    {
        $type = $node['type'] ?? null;
        if ($type === 'group') {
            $op = strtoupper((string) ($node['op'] ?? 'AND'));
            $children = $node['children'] ?? [];
            if ($children === []) {
                return false;
            }
            if ($op === 'OR') {
                foreach ($children as $child) {
                    if (is_array($child) && $this->evalNode($child, $engine, $metrics)) {
                        return true;
                    }
                }

                return false;
            }
            foreach ($children as $child) {
                if (! is_array($child) || ! $this->evalNode($child, $engine, $metrics)) {
                    return false;
                }
            }

            return true;
        }

        if ($type !== 'condition') {
            return false;
        }

        $leftExpr = is_array($node['left'] ?? null) ? $node['left'] : [];
        $rightExpr = is_array($node['right'] ?? null) ? $node['right'] : [];
        $operator = (string) ($node['operator'] ?? 'gt');

        $left = $engine->evaluate($leftExpr);
        $right = $engine->evaluate($rightExpr);

        $metrics[] = [
            'left' => $this->describeExpr($leftExpr),
            'left_value' => $left,
            'operator' => $operator,
            'right' => $this->describeExpr($rightExpr),
            'right_value' => $right,
        ];

        if ($left === null || $right === null) {
            // Indicator could not be produced → treat as skip at stock level only when
            // entire tree can't run; for a leaf, false (division-by-zero style).
            return false;
        }

        return match ($operator) {
            'gt' => $left > $right,
            'gte' => $left >= $right,
            'lt' => $left < $right,
            'lte' => $left <= $right,
            'eq' => TechnicalIndicatorService::floatsEqual($left, $right),
            default => false,
        };
    }

    /**
     * @param  array<string,mixed>  $expr
     */
    private function describeExpr(array $expr): string
    {
        if (($expr['type'] ?? null) === 'constant') {
            return (string) ($expr['value'] ?? '');
        }
        $id = (string) ($expr['indicator'] ?? '');
        $params = is_array($expr['params'] ?? null) ? $expr['params'] : [];
        if ($params === []) {
            return $id;
        }
        $bits = [];
        foreach ($params as $k => $v) {
            $bits[] = $k.'='.$v;
        }

        return $id.'('.implode(',', $bits).')';
    }

    /**
     * @param  list<array<string,mixed>>  $bars
     */
    private function countValidCloses(array $bars): int
    {
        $n = 0;
        foreach ($bars as $bar) {
            $close = $bar['close'] ?? null;
            if ($close === null && isset($bar['adjusted_close'])) {
                $close = $bar['adjusted_close'];
            }
            if ($close !== null) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * @param  array<string,mixed>  $node
     */
    private function treeNeedsVolume(array $node): bool
    {
        $type = $node['type'] ?? null;
        if ($type === 'group') {
            foreach ($node['children'] ?? [] as $child) {
                if (is_array($child) && $this->treeNeedsVolume($child)) {
                    return true;
                }
            }

            return false;
        }
        if ($type === 'condition') {
            return $this->exprNeedsVolume($node['left'] ?? [])
                || $this->exprNeedsVolume($node['right'] ?? []);
        }

        return false;
    }

    /**
     * @param  array<string,mixed>|mixed  $expr
     */
    private function exprNeedsVolume(mixed $expr): bool
    {
        if (! is_array($expr) || ($expr['type'] ?? null) === 'constant') {
            return false;
        }

        return ScreenerCatalog::needsVolume((string) ($expr['indicator'] ?? ''));
    }

    /**
     * @param  list<array<string,mixed>>  $bars
     */
    private function hasVolumeHistory(array $bars, int $lookback): bool
    {
        $withClose = [];
        foreach ($bars as $bar) {
            $close = $bar['close'] ?? null;
            if ($close === null && isset($bar['adjusted_close'])) {
                $close = $bar['adjusted_close'];
            }
            if ($close === null) {
                continue;
            }
            $withClose[] = $bar;
        }
        if (count($withClose) < $lookback) {
            return false;
        }
        $slice = array_slice($withClose, -$lookback);
        foreach ($slice as $bar) {
            if (! isset($bar['volume']) || $bar['volume'] === null) {
                return false;
            }
        }

        return true;
    }
}
