<?php

namespace App\Services\Alerts;

use InvalidArgumentException;

class FormulaEvaluator
{
  /**
   * @param  array<string, float|null>  $variables
   */
    public function evaluate(string $formula, array $variables): ?float
    {
        $expression = trim($formula);
        if ($expression === '') {
            return null;
        }

        $expression = preg_replace_callback(
            '/\{\{\s*([a-z0-9_]+)\s*\}\}/i',
            function (array $matches) use ($variables) {
                $key = $matches[1];
                if (! array_key_exists($key, $variables) || $variables[$key] === null) {
                    throw new InvalidArgumentException("Missing numeric value for column {$key}.");
                }

                return (string) $variables[$key];
            },
            $expression,
        ) ?? $expression;

        $expression = $this->substituteBareColumnIdentifiers($expression, $variables);

        // Use # delimiters — / inside the character class would otherwise end the pattern.
        if (preg_match('#[^0-9+\-*/().\s]#', $expression)) {
            throw new InvalidArgumentException('Formula contains invalid characters.');
        }

        return $this->evaluateExpression($expression);
    }

    public function substituteTags(string $template, array $displayValues): string
    {
        return preg_replace_callback(
            '/\{\{\s*([a-z0-9_]+)\s*\}\}/i',
            function (array $matches) use ($displayValues) {
                $key = $matches[1];

                return (string) ($displayValues[$key] ?? $matches[0]);
            },
            $template,
        ) ?? $template;
    }

    protected function evaluateExpression(string $expression): float
    {
        $tokens = $this->tokenize($expression);
        $position = 0;
        $value = $this->parseExpression($tokens, $position);
        if ($position < count($tokens)) {
            throw new InvalidArgumentException('Invalid formula syntax.');
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    protected function tokenize(string $expression): array
    {
        preg_match_all('#\d+\.\d+|\d+|[()+\-*/]#', $expression, $matches);

        return $matches[0] ?? [];
    }

    /**
     * @param  list<string>  $tokens
     */
    protected function parseExpression(array $tokens, int &$position): float
    {
        $value = $this->parseTerm($tokens, $position);

        while ($position < count($tokens) && in_array($tokens[$position], ['+', '-'], true)) {
            $operator = $tokens[$position++];
            $right = $this->parseTerm($tokens, $position);
            $value = $operator === '+' ? $value + $right : $value - $right;
        }

        return $value;
    }

    /**
     * @param  list<string>  $tokens
     */
    protected function parseTerm(array $tokens, int &$position): float
    {
        $value = $this->parseFactor($tokens, $position);

        while ($position < count($tokens) && in_array($tokens[$position], ['*', '/'], true)) {
            $operator = $tokens[$position++];
            $right = $this->parseFactor($tokens, $position);
            if ($operator === '/' && abs($right) < 1e-12) {
                throw new InvalidArgumentException('Division by zero in formula.');
            }
            $value = $operator === '*' ? $value * $right : $value / $right;
        }

        return $value;
    }

    /**
     * @param  list<string>  $tokens
     */
    protected function parseFactor(array $tokens, int &$position): float
    {
        if ($position >= count($tokens)) {
            throw new InvalidArgumentException('Unexpected end of formula.');
        }

        $token = $tokens[$position];

        if ($token === '(') {
            $position++;
            $value = $this->parseExpression($tokens, $position);
            if (($tokens[$position] ?? null) !== ')') {
                throw new InvalidArgumentException('Missing closing parenthesis.');
            }
            $position++;

            return $value;
        }

        if ($token === '-') {
            $position++;

            return -$this->parseFactor($tokens, $position);
        }

        if ($token === '+') {
            $position++;

            return $this->parseFactor($tokens, $position);
        }

        if (! is_numeric($token)) {
            throw new InvalidArgumentException('Invalid formula token.');
        }

        $position++;

        return (float) $token;
    }

    /**
     * @param  array<string, float|null>  $variables
     */
    protected function substituteBareColumnIdentifiers(string $expression, array $variables): string
    {
        $keys = array_keys($variables);
        usort($keys, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        foreach ($keys as $key) {
            if (! preg_match('/^[a-z0-9_]+$/', $key)) {
                continue;
            }
            if (! array_key_exists($key, $variables) || $variables[$key] === null) {
                continue;
            }

            $expression = preg_replace(
                '/(?<![a-z0-9_])'.preg_quote($key, '/').'(?![a-z0-9_])/i',
                (string) $variables[$key],
                $expression,
            ) ?? $expression;
        }

        return $expression;
    }
}
