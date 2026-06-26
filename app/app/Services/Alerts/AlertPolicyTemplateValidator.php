<?php

namespace App\Services\Alerts;

use App\Models\Holding;
use App\Models\PortfolioProfile;
use App\Services\HoldingsCalculationService;
use InvalidArgumentException;
use Illuminate\Validation\ValidationException;

class AlertPolicyTemplateValidator
{
    public function __construct(
        protected HoldingFieldRegistry $fields,
        protected AlertMessageRenderer $messageRenderer,
        protected FormulaEvaluator $formula,
        protected HoldingsCalculationService $holdings,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public function validateForProfile(PortfolioProfile $profile, array $validated): void
    {
        $allowedKeys = $this->fields->allowedColumnKeys();
        $errors = [];

        try {
            $this->validateMessageTemplate($validated['message_template'], $allowedKeys);
        } catch (InvalidArgumentException $e) {
            $errors['message_template'][] = $e->getMessage();
        }

        if (($validated['compare_type'] ?? '') === 'derived') {
            try {
                $this->validateDerivedFormula($validated['compare_formula'] ?? '', $allowedKeys);
            } catch (InvalidArgumentException $e) {
                $errors['compare_formula'][] = $e->getMessage();
            }
        }

        $sample = $this->resolveSampleContext($profile);
        if ($sample === null) {
            if ($errors === []) {
                $errors['message_template'][] = 'Add at least one open holding to verify message and formula resolve correctly.';
            }
            throw ValidationException::withMessages($errors);
        }

        $symbol = $sample['symbol'];
        $prefix = "Dry-run using {$symbol} failed: ";

        if (! isset($errors['message_template'])) {
            try {
                $this->messageRenderer->assertResolvable(
                    $validated['message_template'],
                    $sample['numeric'],
                    $sample['display'],
                );
            } catch (InvalidArgumentException $e) {
                $errors['message_template'][] = $prefix.$e->getMessage();
            }
        }

        if (($validated['compare_type'] ?? '') === 'derived' && ! isset($errors['compare_formula'])) {
            try {
                $this->assertFormulaResolvable($validated['compare_formula'] ?? '', $sample['numeric']);
            } catch (InvalidArgumentException $e) {
                $errors['compare_formula'][] = $prefix.$e->getMessage();
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  list<string>  $allowedKeys
     */
    public function validateMessageTemplate(string $template, array $allowedKeys): void
    {
        $template = trim($template);
        if ($template === '') {
            throw new InvalidArgumentException('Alert message is required.');
        }

        $this->assertBalancedDelimiters($template, '{{', '}}', 'column tag');
        $this->assertBalancedDelimiters($template, '[[', ']]', 'number format');
        $this->assertBalancedDelimiters($template, '<<', '>>', 'math');

        if (preg_match('/\{\{\s*\}\}/', $template)) {
            throw new InvalidArgumentException('Empty column tag {{}} is not allowed.');
        }
        if (preg_match('/\[\[\s*\]\]/', $template)) {
            throw new InvalidArgumentException('Empty number format block [[]] is not allowed.');
        }
        if (preg_match('/<<\s*>>/', $template)) {
            throw new InvalidArgumentException('Empty math block <<>> is not allowed.');
        }

        $this->assertKnownColumnTags($template, $allowedKeys, 'message');
    }

    /**
     * @param  list<string>  $allowedKeys
     */
    public function validateDerivedFormula(string $formula, array $allowedKeys): void
    {
        $formula = trim($formula);
        if ($formula === '') {
            throw new InvalidArgumentException('Derived formula is required.');
        }

        $this->assertKnownColumnTags($formula, $allowedKeys, 'formula');
    }

    /**
     * @param  array<string, float|null>  $numericVariables
     */
    public function assertFormulaResolvable(string $formula, array $numericVariables): void
    {
        $formula = trim($formula);
        if ($formula === '') {
            throw new InvalidArgumentException('Derived formula is required.');
        }

        try {
            $value = $this->formula->evaluate($formula, $numericVariables);
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException($e->getMessage());
        }

        if ($value === null) {
            throw new InvalidArgumentException('Derived formula did not produce a numeric value.');
        }
    }

    /**
     * @return array{
     *     symbol: string,
     *     numeric: array<string, float|null>,
     *     display: array<string, string>
     * }|null
     */
    protected function resolveSampleContext(PortfolioProfile $profile): ?array
    {
        $this->holdings->recalculateForProfile($profile);

        /** @var Holding|null $holding */
        $holding = $profile->holdings()
            ->with('stock')
            ->where('quantity', '>', 0)
            ->orderBy('id')
            ->first();

        if ($holding === null) {
            return null;
        }

        $flat = $this->fields->flattenHolding($profile, $holding);
        $numeric = [];
        foreach ($this->fields->allowedColumnKeys() as $key) {
            $numeric[$key] = $this->fields->resolveNumericValue($key, $flat);
        }

        $display = [];
        foreach ($flat as $key => $value) {
            $display[$key] = $this->fields->formatValueForDisplay($key, $value);
        }

        return [
            'symbol' => (string) ($holding->stock?->symbol ?? '?'),
            'numeric' => $numeric,
            'display' => $display,
        ];
    }

    /**
     * @param  list<string>  $allowedKeys
     */
    protected function assertKnownColumnTags(string $template, array $allowedKeys, string $context): void
    {
        if (! preg_match_all('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', $template, $matches)) {
            return;
        }

        foreach ($matches[1] as $key) {
            if (! in_array($key, $allowedKeys, true)) {
                throw new InvalidArgumentException("Unknown column \"{$key}\" in {$context}.");
            }
        }
    }

    protected function assertBalancedDelimiters(string $template, string $open, string $close, string $label): void
    {
        $depth = 0;
        $openLen = strlen($open);
        $closeLen = strlen($close);
        $length = strlen($template);

        for ($index = 0; $index < $length; $index++) {
            if (substr($template, $index, $openLen) === $open) {
                $depth++;
                $index += $openLen - 1;

                continue;
            }

            if (substr($template, $index, $closeLen) === $close) {
                $depth--;
                if ($depth < 0) {
                    throw new InvalidArgumentException("Unmatched closing delimiter for {$label} block.");
                }
                $index += $closeLen - 1;
            }
        }

        if ($depth !== 0) {
            throw new InvalidArgumentException("Unclosed {$label} block.");
        }
    }
}
