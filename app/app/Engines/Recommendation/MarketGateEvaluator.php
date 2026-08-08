<?php

namespace App\Engines\Recommendation;

/**
 * Evaluate Market Analysis outputs against optional Strategy market_gates (SD-032 / F098).
 *
 * Market Analysis supplies base entry permission and allocation multiplier.
 * When strategy gates are enabled, sentiment / phase / risk thresholds may block
 * new entries (OPEN / INCREASE demotion happens in the generation pipeline).
 */
final class MarketGateEvaluator
{
    /**
     * @param  array<string, mixed>  $market  Latest Market Analysis payload
     * @param  array<string, mixed>  $marketGatesConfig  Strategy `market_gates` section
     * @return array{
     *     allows_entry: bool,
     *     allocation_multiplier: float,
     *     market_analysis: array<string, mixed>,
     *     strategy_gates: array<string, mixed>
     * }
     */
    public static function evaluate(array $market, array $marketGatesConfig): array
    {
        $baseMult = (float) ($market['allocation_multiplier'] ?? 1.0);
        $baseAllowsEntry = (bool) ($market['new_entry_allowed'] ?? true);
        $sentimentScore = isset($market['sentiment']['score']) && is_numeric($market['sentiment']['score'])
            ? (float) $market['sentiment']['score']
            : 50.0;
        $phase = (string) ($market['market_phase'] ?? '');
        $riskRaw = isset($market['risk']['raw_risk']) && is_numeric($market['risk']['raw_risk'])
            ? (float) $market['risk']['raw_risk']
            : null;

        $marketAnalysis = [
            'new_entry_allowed' => $baseAllowsEntry,
            'allocation_multiplier' => round($baseMult, 4),
            'market_phase' => $phase !== '' ? $phase : null,
            'sentiment_score' => $sentimentScore,
            'sentiment_label' => $market['sentiment']['label'] ?? null,
            'risk_label' => $market['risk']['label'] ?? null,
            'risk_raw' => $riskRaw,
        ];

        $enabled = ($marketGatesConfig['enabled'] ?? false) === true;
        $checks = [];
        $blockReasons = [];
        $effectiveMult = $baseMult;
        $effectiveAllowsEntry = $baseAllowsEntry;

        if ($enabled) {
            $minSentiment = $marketGatesConfig['min_sentiment'] ?? null;
            if ($minSentiment !== null) {
                $threshold = (float) $minSentiment;
                $passed = $sentimentScore >= $threshold;
                $checks[] = [
                    'key' => 'min_sentiment',
                    'passed' => $passed,
                    'threshold' => $threshold,
                    'actual' => $sentimentScore,
                    'detail' => sprintf(
                        'Sentiment %.2f %s minimum %.2f',
                        $sentimentScore,
                        $passed ? '>=' : '<',
                        $threshold
                    ),
                ];
                if (! $passed) {
                    $effectiveAllowsEntry = false;
                    $blockReasons[] = 'Sentiment below strategy minimum';
                }
            }

            $allowedPhases = $marketGatesConfig['allowed_phases'] ?? null;
            if (is_array($allowedPhases) && $allowedPhases !== []) {
                $passed = in_array($phase, $allowedPhases, true);
                $checks[] = [
                    'key' => 'allowed_phases',
                    'passed' => $passed,
                    'allowed' => array_values($allowedPhases),
                    'actual' => $phase,
                    'detail' => $passed
                        ? "Phase \"{$phase}\" is allowed"
                        : "Phase \"{$phase}\" not in allowed phases",
                ];
                if (! $passed) {
                    $effectiveAllowsEntry = false;
                    $blockReasons[] = 'Market phase not in allowed phases';
                }
            }

            if (isset($marketGatesConfig['max_risk_raw']) && $riskRaw !== null) {
                $threshold = (float) $marketGatesConfig['max_risk_raw'];
                $passed = $riskRaw <= $threshold;
                $checks[] = [
                    'key' => 'max_risk_raw',
                    'passed' => $passed,
                    'threshold' => $threshold,
                    'actual' => $riskRaw,
                    'detail' => sprintf(
                        'Risk raw %.2f %s max %.2f',
                        $riskRaw,
                        $passed ? '<=' : '>',
                        $threshold
                    ),
                ];
                if (! $passed) {
                    $effectiveAllowsEntry = false;
                    $effectiveMult = min($effectiveMult, 0.5);
                    $blockReasons[] = 'Market risk exceeds strategy maximum';
                }
            }
        }

        if (! $baseAllowsEntry) {
            $riskLabel = (string) ($market['risk']['label'] ?? '');
            if (in_array($phase, ['Bear', 'Capitulation'], true)) {
                $blockReasons[] = "Market phase \"{$phase}\" disallows new entries";
            } elseif ($riskLabel === 'Extreme') {
                $blockReasons[] = 'Extreme market risk disallows new entries';
            } else {
                $blockReasons[] = 'Market Analysis disallows new entries';
            }
        }

        $blockReasons = array_values(array_unique($blockReasons));

        return [
            'allows_entry' => $effectiveAllowsEntry,
            'allocation_multiplier' => round($effectiveMult, 4),
            'market_analysis' => $marketAnalysis,
            'strategy_gates' => [
                'enabled' => $enabled,
                'config' => $enabled ? [
                    'min_sentiment' => $marketGatesConfig['min_sentiment'] ?? null,
                    'allowed_phases' => $marketGatesConfig['allowed_phases'] ?? null,
                    'max_risk_raw' => $marketGatesConfig['max_risk_raw'] ?? null,
                ] : [],
                'checks' => $checks,
                'blocked' => ! $effectiveAllowsEntry,
                'block_reasons' => $blockReasons,
            ],
        ];
    }
}
