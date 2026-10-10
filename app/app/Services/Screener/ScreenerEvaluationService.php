<?php

namespace App\Services\Screener;

use App\Models\Stock;
use App\Services\Fundamentals\FundamentalScreenerOperandService;
use App\Services\ML\MlScreenerOperandService;
use Carbon\Carbon;

class ScreenerEvaluationService
{
    protected ?Stock $evaluationStock = null;

    protected ?Carbon $evaluationAsOf = null;

    public function __construct(
        protected TechnicalIndicatorService $indicators,
        protected FundamentalScreenerOperandService $fundamentalOperands,
        protected MlScreenerOperandService $mlOperands,
    ) {}

    /**
     * Max OHLCV rows required by the definition tree (stock + entity expressions).
     *
     * @param  array{root:array}  $definition
     */
    public function maxLookback(array $definition): int
    {
        return max(1, $this->nodeLookback($definition['root'] ?? []));
    }

    /**
     * Max OHLCV rows required by expressions evaluated on the scanned stock only
     * (left operands pinned to an index entity are excluded).
     *
     * @param  array{root:array}  $definition
     */
    public function stockLookback(array $definition): int
    {
        return max(1, $this->nodeLookback($definition['root'] ?? [], entityFilter: 'stock'));
    }

    /**
     * Index entity symbols referenced by left operands, mapped to the max lookback each needs.
     *
     * @param  array{root:array}  $definition
     * @return array<string,int>
     */
    public function entityLookbacks(array $definition): array
    {
        $out = [];
        $this->collectEntityLookbacks($definition['root'] ?? [], $out);

        return $out;
    }

    /**
     * @param  array{root:array}  $definition
     * @param  list<array{open:?float,high:?float,low:?float,close:?float,volume:?float,adjusted_close?:?float}>  $bars
     * @param  array<string,list<array<string,mixed>>>  $entityBars  Index symbol → chronological bars for entity-pinned left operands.
     * @return array{matched:bool,skipped:bool,skip_reason:?string,metrics:array<string,mixed>}
     */
    public function evaluateStock(array $definition, array $bars, array $entityBars = [], ?Stock $stock = null): array
    {
        $this->evaluationStock = $stock;
        $this->evaluationAsOf = $this->asOfFromBars($bars) ?? now();

        try {
            $lookback = $this->stockLookback($definition);
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

            $engines = ['stock' => $this->indicators->withBars($bars)];
            foreach ($entityBars as $symbol => $entBars) {
                $engines[$symbol] = $this->indicators->withBars(is_array($entBars) ? $entBars : []);
            }

            $metrics = [];
            $matched = $this->evalNode($definition['root'] ?? [], $engines, $metrics);

            return [
                'matched' => $matched,
                'skipped' => false,
                'skip_reason' => null,
                'metrics' => $metrics,
            ];
        } finally {
            $this->evaluationStock = null;
            $this->evaluationAsOf = null;
        }
    }

    /**
     * Stock-major evaluation: compute every indicator series once for the full
     * bar range, then answer each as-of date by indexing into those series.
     * Bars must carry a 'date' (Y-m-d) key and be sorted ascending; $asOfDates
     * must also be ascending. Replaces per-day bar re-slicing in backtests.
     *
     * @param  array{root:array}  $definition
     * @param  list<array{date:string,open:?float,high:?float,low:?float,close:?float,volume:?float,adjusted_close?:?float}>  $bars
     * @param  list<string>  $asOfDates
     * @param  array<string,list<array<string,mixed>>>  $entityBars  Index symbol → chronological bars (with 'date') for entity-pinned left operands.
     * @return array<string,array{matched:bool,skipped:bool}>  Keyed by as-of date.
     */
    public function evaluateAcrossDates(array $definition, array $bars, array $asOfDates, array $entityBars = [], ?Stock $stock = null): array
    {
        $this->evaluationStock = $stock;

        try {
            return $this->evaluateAcrossDatesInner($definition, $bars, $asOfDates, $entityBars);
        } finally {
            $this->evaluationStock = null;
            $this->evaluationAsOf = null;
        }
    }

    /**
     * @param  array{root:array}  $definition
     * @param  list<array{date:string,open:?float,high:?float,low:?float,close:?float,volume:?float,adjusted_close?:?float}>  $bars
     * @param  list<string>  $asOfDates
     * @param  array<string,list<array<string,mixed>>>  $entityBars
     * @return array<string,array{matched:bool,skipped:bool}>
     */
    private function evaluateAcrossDatesInner(array $definition, array $bars, array $asOfDates, array $entityBars): array
    {
        $root = $definition['root'] ?? [];
        $stockLookback = $this->stockLookback($definition);

        [$validBars, $stockDates] = $this->splitValidBars($bars);
        $engines = ['stock' => $this->indicators->withBars($validBars)];
        $dateLists = ['stock' => $stockDates];
        foreach ($entityBars as $symbol => $entBars) {
            [$entValid, $entDates] = $this->splitValidBars(is_array($entBars) ? $entBars : []);
            $engines[$symbol] = $this->indicators->withBars($entValid);
            $dateLists[$symbol] = $entDates;
        }

        $needsVolume = $this->treeNeedsVolume($root);
        $volStreak = [];
        if ($needsVolume) {
            $streak = 0;
            foreach ($validBars as $bar) {
                $streak = ($bar['volume'] ?? null) === null ? 0 : $streak + 1;
                $volStreak[] = $streak;
            }
        }

        // Two-pointer walk: dates ascend, so each pointer only moves forward.
        $pointers = array_fill_keys(array_keys($engines), -1);
        $out = [];
        foreach ($asOfDates as $asOf) {
            $asOf = (string) $asOf;
            foreach ($pointers as $key => $ptr) {
                $list = $dateLists[$key];
                $count = count($list);
                while ($ptr + 1 < $count && $list[$ptr + 1] <= $asOf) {
                    $ptr++;
                }
                $pointers[$key] = $ptr;
            }

            $stockIdx = $pointers['stock'];
            if ($stockIdx + 1 < $stockLookback) {
                $out[$asOf] = ['matched' => false, 'skipped' => true];

                continue;
            }
            if ($needsVolume && ($volStreak[$stockIdx] ?? 0) < $stockLookback) {
                $out[$asOf] = ['matched' => false, 'skipped' => true];

                continue;
            }

            $this->evaluationAsOf = Carbon::parse($asOf);
            $out[$asOf] = [
                'matched' => $this->evalNodeAtIndex($root, $engines, $pointers),
                'skipped' => false,
            ];
        }

        return $out;
    }

    /**
     * Bars with a usable close (adjusted fallback) plus their parallel date list.
     *
     * @param  list<array<string,mixed>>  $bars
     * @return array{0:list<array<string,mixed>>,1:list<string>}
     */
    private function splitValidBars(array $bars): array
    {
        $validBars = [];
        $dates = [];
        foreach ($bars as $bar) {
            if (! is_array($bar)) {
                continue;
            }
            $close = $bar['close'] ?? null;
            if ($close === null && isset($bar['adjusted_close'])) {
                $close = $bar['adjusted_close'];
            }
            if ($close === null) {
                continue;
            }
            $validBars[] = $bar;
            $dates[] = (string) ($bar['date'] ?? '');
        }

        return [$validBars, $dates];
    }

    /**
     * Condition tree evaluation reading pre-computed series at fixed indexes.
     * No metrics are collected — backtests only need the boolean outcome.
     *
     * @param  array<string,mixed>  $node
     * @param  array<string,TechnicalIndicatorService>  $engines
     * @param  array<string,int>  $indexes  Engine key → current bar index (-1 = no bar yet).
     */
    private function evalNodeAtIndex(array $node, array $engines, array $indexes): bool
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
                    if (is_array($child) && $this->evalNodeAtIndex($child, $engines, $indexes)) {
                        return true;
                    }
                }

                return false;
            }
            foreach ($children as $child) {
                if (! is_array($child) || ! $this->evalNodeAtIndex($child, $engines, $indexes)) {
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
        $weightFactor = $this->normalizeWeightFactor($node['weight_factor'] ?? 1);

        $left = $this->seriesValueAt($leftExpr, $this->exprEntity($leftExpr), $engines, $indexes);
        // RHS always evaluates on the scanned stock.
        $right = $this->seriesValueAt($rightExpr, 'stock', $engines, $indexes);
        if ($left === null || $right === null) {
            return false;
        }
        $scaledRight = $right * $weightFactor;

        return match ($operator) {
            'gt' => $left > $scaledRight,
            'gte' => $left >= $scaledRight,
            'lt' => $left < $scaledRight,
            'lte' => $left <= $scaledRight,
            'eq' => TechnicalIndicatorService::floatsEqual($left, $scaledRight),
            default => false,
        };
    }

    /**
     * @param  array<string,mixed>  $expr
     * @param  array<string,TechnicalIndicatorService>  $engines
     * @param  array<string,int>  $indexes
     */
    /**
     * @param  array<string,mixed>  $expr
     * @param  array<string,TechnicalIndicatorService>  $engines
     */
    private function evaluateExprValue(array $expr, string $entity, array $engines): ?float
    {
        $indicatorId = (string) ($expr['indicator'] ?? '');
        if ($entity === 'stock' && $indicatorId !== '' && $this->evaluationStock !== null && $this->evaluationAsOf !== null) {
            if ($this->fundamentalOperands->supports($indicatorId)) {
                return $this->fundamentalOperands->valueForIndicator(
                    $this->evaluationStock,
                    $indicatorId,
                    $this->evaluationAsOf,
                );
            }
            if ($this->mlOperands->supports($indicatorId)) {
                return $this->mlOperands->valueForIndicator(
                    $this->evaluationStock,
                    $indicatorId,
                    $this->evaluationAsOf,
                );
            }
        }

        $engine = $engines[$entity] ?? null;

        return $engine?->evaluate($expr);
    }

    /**
     * @param  array<string,mixed>  $expr
     * @param  array<string,TechnicalIndicatorService>  $engines
     * @param  array<string,int>  $indexes
     */
    private function seriesValueAt(array $expr, string $entity, array $engines, array $indexes): ?float
    {
        $indicatorId = (string) ($expr['indicator'] ?? '');
        if ($entity === 'stock' && $indicatorId !== '' && $this->evaluationStock !== null && $this->evaluationAsOf !== null) {
            if ($this->fundamentalOperands->supports($indicatorId)) {
                return $this->fundamentalOperands->valueForIndicator(
                    $this->evaluationStock,
                    $indicatorId,
                    $this->evaluationAsOf,
                );
            }
            if ($this->mlOperands->supports($indicatorId)) {
                return $this->mlOperands->valueForIndicator(
                    $this->evaluationStock,
                    $indicatorId,
                    $this->evaluationAsOf,
                );
            }
        }

        $engine = $engines[$entity] ?? null;
        $idx = $indexes[$entity] ?? -1;
        if ($engine === null || $idx < 0) {
            return null;
        }
        $series = $engine->evaluateSeries($expr);

        return $series[$idx] ?? null;
    }

    /**
     * @param  list<array<string,mixed>>  $bars
     */
    private function asOfFromBars(array $bars): ?Carbon
    {
        if ($bars === []) {
            return null;
        }
        $last = $bars[array_key_last($bars)];
        $date = $last['date'] ?? null;
        if (! is_string($date) || trim($date) === '') {
            return now();
        }

        return Carbon::parse($date);
    }

    /**
     * @param  array<string,mixed>  $node
     * @param  string|null  $entityFilter  When 'stock', ignore left operands pinned to an index entity.
     */
    private function nodeLookback(array $node, ?string $entityFilter = null): int
    {
        $type = $node['type'] ?? null;
        if ($type === 'group') {
            $max = 0;
            foreach ($node['children'] ?? [] as $child) {
                if (is_array($child)) {
                    $max = max($max, $this->nodeLookback($child, $entityFilter));
                }
            }

            return $max;
        }
        if ($type === 'condition') {
            $left = is_array($node['left'] ?? null) ? $node['left'] : [];
            $right = is_array($node['right'] ?? null) ? $node['right'] : [];

            $leftBars = ($entityFilter === 'stock' && $this->exprEntity($left) !== 'stock')
                ? 0
                : $this->indicators->minBarsFor($left);

            return max(
                $leftBars,
                $this->indicators->minBarsFor($right),
            );
        }

        return 1;
    }

    /**
     * @param  array<string,mixed>  $node
     * @param  array<string,int>  $out
     */
    private function collectEntityLookbacks(array $node, array &$out): void
    {
        $type = $node['type'] ?? null;
        if ($type === 'group') {
            foreach ($node['children'] ?? [] as $child) {
                if (is_array($child)) {
                    $this->collectEntityLookbacks($child, $out);
                }
            }

            return;
        }
        if ($type !== 'condition') {
            return;
        }
        $left = is_array($node['left'] ?? null) ? $node['left'] : [];
        $entity = $this->exprEntity($left);
        if ($entity === 'stock') {
            return;
        }
        $bars = $this->indicators->minBarsFor($left);
        $out[$entity] = max($out[$entity] ?? 0, $bars);
    }

    /**
     * @param  array<string,mixed>  $expr
     */
    private function exprEntity(array $expr): string
    {
        if (($expr['type'] ?? null) === 'constant') {
            return 'stock';
        }
        $entity = $expr['entity'] ?? null;
        if (! is_string($entity) || $entity === '' || $entity === 'stock') {
            return 'stock';
        }

        return $entity;
    }

    /**
     * @param  array<string,mixed>  $node
     * @param  array<string,TechnicalIndicatorService>  $engines  Keyed by 'stock' + index entity symbols.
     * @param  array<string,mixed>  $metrics
     */
    private function evalNode(array $node, array $engines, array &$metrics): bool
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
                    if (is_array($child) && $this->evalNode($child, $engines, $metrics)) {
                        return true;
                    }
                }

                return false;
            }
            foreach ($children as $child) {
                if (! is_array($child) || ! $this->evalNode($child, $engines, $metrics)) {
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
        $weightFactor = $this->normalizeWeightFactor($node['weight_factor'] ?? 1);

        $leftEntity = $this->exprEntity($leftExpr);
        $left = $this->evaluateExprValue($leftExpr, $leftEntity, $engines);
        // RHS always evaluates on the scanned stock.
        $right = $this->evaluateExprValue($rightExpr, 'stock', $engines);
        $scaledRight = $right === null ? null : $right * $weightFactor;

        $conditionMatched = $left !== null && $scaledRight !== null && match ($operator) {
            'gt' => $left > $scaledRight,
            'gte' => $left >= $scaledRight,
            'lt' => $left < $scaledRight,
            'lte' => $left <= $scaledRight,
            'eq' => TechnicalIndicatorService::floatsEqual($left, $scaledRight),
            default => false,
        };

        $metrics[] = [
            'left' => $this->describeExpr($leftExpr),
            'left_entity' => $leftEntity,
            'left_value' => $left,
            'operator' => $operator,
            'weight_factor' => $weightFactor,
            'right' => $this->describeExpr($rightExpr),
            'right_value' => $right,
            'right_scaled' => $scaledRight,
            'condition_matched' => $conditionMatched,
        ];

        // Indicator could not be produced → false at this leaf; the run snapshot
        // preserves null operand values so the UI can distinguish missing input.
        return $conditionMatched;
    }

    private function normalizeWeightFactor(mixed $weight): float
    {
        if ($weight === null || $weight === '' || ! is_numeric($weight)) {
            return 1.0;
        }
        $w = (float) $weight;

        return is_finite($w) ? $w : 1.0;
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
        $prefix = '';
        $entity = $this->exprEntity($expr);
        if ($entity !== 'stock') {
            $prefix = ScreenerCatalog::entityLabel($entity).' ';
        }
        if ($params === []) {
            return $prefix.$id;
        }
        $bits = [];
        foreach ($params as $k => $v) {
            $bits[] = $k.'='.$v;
        }

        return $prefix.$id.'('.implode(',', $bits).')';
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
            $left = is_array($node['left'] ?? null) ? $node['left'] : [];
            // Volume history is checked on the scanned stock; entity-pinned lefts read index bars instead.
            $leftNeedsVolume = $this->exprEntity($left) === 'stock' && $this->exprNeedsVolume($left);

            return $leftNeedsVolume || $this->exprNeedsVolume($node['right'] ?? []);
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
