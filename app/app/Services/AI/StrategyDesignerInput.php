<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class StrategyDesignerInput
{
    public static function normalize(array $input): array
    {
        $choices = ['investmentStyle', 'riskProfile', 'holdingPeriod', 'targetMarket', 'universe', 'capitalAllocation', 'preferredExitStyle', 'strategyComplexity', 'explainabilityLevel'];
        $custom = ['investmentStyle' => 'customInvestmentStyle', 'holdingPeriod' => 'customHoldingPeriod', 'targetMarket' => 'customTargetMarket', 'universe' => 'customUniverse', 'capitalAllocation' => 'customCapitalAllocation', 'preferredExitStyle' => 'customPreferredExitStyle'];
        $rules = array_fill_keys($choices, 'required|string|max:120');
        foreach ($custom as $field) {
            $rules[$field] = 'nullable|string|max:2000';
        }
        $rules += ['maximumPositions' => 'required|integer|min:1|max:1000', 'marketPreferences' => 'present|array|max:10', 'marketPreferences.*' => 'string|max:100', 'optimizationPriorities' => 'present|array|max:20', 'optimizationPriorities.*' => 'string|max:100', 'additionalConstraints' => 'nullable|string|max:4000'];
        if (array_diff(array_keys($input), array_keys($rules))) {
            throw ValidationException::withMessages(['inputs' => 'Unknown strategy input']);
        }
        $input = EmbeddedAiContract::normalize(Validator::make($input, $rules)->validate());
        $input['maximumPositions'] = (int) $input['maximumPositions'];
        foreach ($custom as $choice => $field) {
            $input[$field] = $input[$choice] === 'Custom' ? ($input[$field] ?? '') : '';
        }
        foreach (['marketPreferences', 'optimizationPriorities'] as $field) {
            $input[$field] = array_values(array_unique($input[$field]));
            sort($input[$field]);
        }
        $input['additionalConstraints'] ??= '';

        return EmbeddedAiContract::normalize($input);
    }

    /** Material model input only: generated-at timestamps and unrelated guide sections are excluded. */
    public static function authoringEvidence(string $guide): string
    {
        $sections = [];
        foreach ([['## 2. AI Authoring Contract', '## 4. Authoring Workflow'], ['## Catalogue C', '## Catalogue D'], ['## 7. Strategy Registry', '## 8. Trading Cookbook']] as [$start, $end]) {
            $from = strpos($guide, $start);
            $to = strpos($guide, $end, $from ?: 0);
            if ($from === false || $to === false) {
                throw new \RuntimeException('Authoring contract unavailable');
            }
            $sections[] = substr($guide, $from, $to - $from);
        }

        return implode("\n\n", $sections);
    }
}
