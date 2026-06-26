<?php

namespace App\Services\Alerts;

use InvalidArgumentException;

class AlertMessageRenderer
{
    private const MAX_BLOCK_ITERATIONS = 200;

    public function __construct(
        protected FormulaEvaluator $formula,
    ) {}

    /**
     * @param  array<string, float|null>  $numericVariables
     * @param  array<string, string>  $displayValues
     */
    public function render(string $template, array $numericVariables, array $displayValues, bool $strict = false): string
    {
        $message = $this->resolveSpecialBlocks($template, $numericVariables, $strict);

        return $this->formula->substituteTags($message, $displayValues);
    }

    /**
     * @param  array<string, float|null>  $numericVariables
     * @param  array<string, string>  $displayValues
     */
    public function assertResolvable(string $template, array $numericVariables, array $displayValues): void
    {
        $rendered = $this->render($template, $numericVariables, $displayValues, true);

        if (preg_match('/\{\{[^}]+\}\}/', $rendered)) {
            throw new InvalidArgumentException('Unresolved column tags remain (missing values or unknown columns).');
        }

        if (str_contains($rendered, '[[') || str_contains($rendered, ']]')) {
            throw new InvalidArgumentException('Unresolved [[ ... ]] number format blocks (check syntax and column values).');
        }

        if (str_contains($rendered, '<<') || str_contains($rendered, '>>')) {
            throw new InvalidArgumentException('Unresolved << ... >> math blocks (check syntax and column values).');
        }
    }

    /**
     * Resolve innermost [[...]] and <<...>> blocks until none remain.
     *
     * @param  array<string, float|null>  $numericVariables
     */
    protected function resolveSpecialBlocks(string $template, array $numericVariables, bool $strict = false): string
    {
        for ($iteration = 0; $iteration < self::MAX_BLOCK_ITERATIONS; $iteration++) {
            $block = $this->findNextResolvableBlock($template);
            if ($block === null) {
                break;
            }

            $replacement = $this->processBlock($block, $numericVariables, $strict);
            if ($replacement === $block['full']) {
                if ($strict) {
                    throw new InvalidArgumentException('Could not resolve a template formatting block.');
                }
                break;
            }

            $template = substr_replace(
                $template,
                $replacement,
                $block['start'],
                strlen($block['full']),
            );
        }

        return $template;
    }

    /**
     * @return array{type: string, start: int, full: string, inner: string}|null
     */
    protected function findNextResolvableBlock(string $template): ?array
    {
        $blocks = [];

        if (preg_match_all('/\[\[([^\[\]]+)\]\]/', $template, $formatMatches, PREG_OFFSET_CAPTURE)) {
            foreach ($formatMatches[0] as $index => $fullMatch) {
                $blocks[] = [
                    'type' => 'format',
                    'start' => $fullMatch[1],
                    'full' => $fullMatch[0],
                    'inner' => $formatMatches[1][$index][0],
                ];
            }
        }

        if (preg_match_all('/<<([^<>]+)>>/', $template, $mathMatches, PREG_OFFSET_CAPTURE)) {
            foreach ($mathMatches[0] as $index => $fullMatch) {
                $blocks[] = [
                    'type' => 'math',
                    'start' => $fullMatch[1],
                    'full' => $fullMatch[0],
                    'inner' => $mathMatches[1][$index][0],
                ];
            }
        }

        if ($blocks === []) {
            return null;
        }

        foreach ($blocks as $block) {
            if ($block['type'] !== 'format') {
                continue;
            }
            foreach ($blocks as $outer) {
                if ($outer['type'] !== 'math') {
                    continue;
                }
                $outerEnd = $outer['start'] + strlen($outer['full']);
                $blockEnd = $block['start'] + strlen($block['full']);
                if ($block['start'] >= $outer['start'] && $blockEnd <= $outerEnd) {
                    return $block;
                }
            }
        }

        usort($blocks, fn (array $a, array $b) => $a['start'] <=> $b['start']);

        return $blocks[0];
    }

    /**
     * @param  array{type: string, start: int, full: string, inner: string}  $block
     * @param  array<string, float|null>  $numericVariables
     */
    protected function processBlock(array $block, array $numericVariables, bool $strict = false): string
    {
        return match ($block['type']) {
            'format' => $this->formatNumberBlock(trim($block['inner']), $numericVariables, $strict),
            'math' => $this->evaluateMathBlock(trim($block['inner']), $numericVariables, $strict),
            default => $block['full'],
        };
    }

    /**
     * @param  array<string, float|null>  $numericVariables
     */
    protected function evaluateMathBlock(string $inner, array $numericVariables, bool $strict = false): string
    {
        $expression = $this->normalizeNumericExpression($inner);

        try {
            $value = $this->formula->evaluate($expression, $numericVariables);
            if ($value === null) {
                if ($strict) {
                    throw new InvalidArgumentException("Math expression \"{$inner}\" did not produce a value.");
                }

                return $inner;
            }

            return $this->formatMathResult($value);
        } catch (InvalidArgumentException $e) {
            if ($strict) {
                throw new InvalidArgumentException("Invalid math expression \"{$inner}\": ".$e->getMessage());
            }

            return $inner;
        }
    }

    /**
     * @param  array<string, float|null>  $numericVariables
     */
    protected function formatNumberBlock(string $inner, array $numericVariables, bool $strict = false): string
    {
        $numeric = $this->resolveNumericContent($inner, $numericVariables, $strict);
        if ($numeric === null) {
            if ($strict) {
                throw new InvalidArgumentException("Could not format \"{$inner}\" as a number.");
            }

            return $inner;
        }

        return number_format($numeric, 2, '.', ',');
    }

    /**
     * @param  array<string, float|null>  $numericVariables
     */
    protected function resolveNumericContent(string $inner, array $numericVariables, bool $strict = false): ?float
    {
        $inner = trim($inner);
        if (preg_match('/^<<([^<>]+)>>$/', $inner, $mathWrapper)) {
            $inner = trim($mathWrapper[1]);
        }

        $normalized = $this->normalizeNumericExpression($inner);
        if ($normalized !== '' && is_numeric($normalized)) {
            return (float) $normalized;
        }

        $substituted = preg_replace_callback(
            '/\{\{\s*([a-z0-9_]+)\s*\}\}/i',
            function (array $matches) use ($numericVariables) {
                $key = $matches[1];
                if (! array_key_exists($key, $numericVariables) || $numericVariables[$key] === null) {
                    return '';
                }

                return (string) $numericVariables[$key];
            },
            $inner,
        ) ?? $inner;

        $substituted = trim($this->normalizeNumericExpression($substituted));
        if ($substituted !== '' && is_numeric($substituted)) {
            return (float) $substituted;
        }

        try {
            return $this->formula->evaluate($inner, $numericVariables);
        } catch (InvalidArgumentException $e) {
            if ($strict) {
                throw $e;
            }

            return null;
        }
    }

    protected function normalizeNumericExpression(string $expression): string
    {
        return str_replace(',', '', trim($expression));
    }

    protected function formatMathResult(float $value): string
    {
        if (abs($value - round($value)) < 1e-9) {
            return (string) (int) round($value);
        }

        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }
}
