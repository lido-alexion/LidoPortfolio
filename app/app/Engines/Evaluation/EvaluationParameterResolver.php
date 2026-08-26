<?php

namespace App\Engines\Evaluation;

use App\Services\IndexCatalogService;
use App\Support\TradingOsConfig;

/**
 * V4-FEAT-021 — resolve Strategy catalogue indicator parameters over Evaluation globals.
 *
 * Does not load Strategy rows (no persistence). Callers pass config_json / scoring_model.
 * Does not touch scoring weights.
 */
class EvaluationParameterResolver
{
    public const PERIOD_KEYS = [
        'rsi_period',
        'sma_fast',
        'sma_slow',
        'atr_period',
        'volume_sma_period',
    ];

    public function __construct(
        protected IndexCatalogService $indexes,
    ) {}

    /**
     * Global Evaluation defaults from trading_os.evaluation.
     * lookback_days / benchmark are null here: missing Strategy values keep the
     * pre-FEAT-021 RS path (3-month relative strength vs primary benchmark).
     *
     * @return array<string, mixed>
     */
    public function globals(): array
    {
        $eval = TradingOsConfig::evaluation();

        return [
            'min_bars' => (int) ($eval['min_bars'] ?? 60),
            'weights' => is_array($eval['weights'] ?? null) ? $eval['weights'] : [],
            'rsi_period' => (int) ($eval['rsi_period'] ?? 14),
            'sma_fast' => (int) ($eval['sma_fast'] ?? 20),
            'sma_slow' => (int) ($eval['sma_slow'] ?? 50),
            'atr_period' => (int) ($eval['atr_period'] ?? 14),
            'volume_sma_period' => (int) ($eval['volume_sma_period'] ?? 20),
            'lookback_days' => null,
            'use_lookback_days' => false,
            'benchmark' => null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $strategyConfig  Strategy version config_json
     * @return array<string, mixed>
     */
    public function resolve(?array $strategyConfig): array
    {
        $resolved = $this->globals();
        $extracted = $this->extractParameters($strategyConfig ?? []);

        foreach (self::PERIOD_KEYS as $key) {
            $valid = $this->validPositiveInt($extracted[$key] ?? null);
            if ($valid !== null) {
                $resolved[$key] = $valid;
            }
        }

        $lookback = $this->validPositiveInt($extracted['lookback_days'] ?? null);
        if ($lookback !== null) {
            $resolved['lookback_days'] = $lookback;
            $resolved['use_lookback_days'] = true;
        }

        $benchmark = $this->validBenchmarkSymbol($extracted['benchmark'] ?? null);
        if ($benchmark !== null) {
            $resolved['benchmark'] = $benchmark;
        }

        return $resolved;
    }

    /**
     * Stable fingerprint of indicator overrides (not weights) for grouping evaluation runs.
     *
     * @param  array<string, mixed>  $resolved
     */
    public function fingerprint(array $resolved): string
    {
        return json_encode([
            'rsi_period' => (int) ($resolved['rsi_period'] ?? 14),
            'sma_fast' => (int) ($resolved['sma_fast'] ?? 20),
            'sma_slow' => (int) ($resolved['sma_slow'] ?? 50),
            'atr_period' => (int) ($resolved['atr_period'] ?? 14),
            'volume_sma_period' => (int) ($resolved['volume_sma_period'] ?? 20),
            'lookback_days' => ! empty($resolved['use_lookback_days'])
                ? (int) ($resolved['lookback_days'] ?? 0)
                : null,
            'benchmark' => $resolved['benchmark'] ?? null,
            'min_bars' => (int) ($resolved['min_bars'] ?? 60),
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $strategyConfig
     * @return array<string, mixed>
     */
    protected function extractParameters(array $strategyConfig): array
    {
        $out = [];
        $rows = $strategyConfig['indicators']
            ?? $strategyConfig['scoring_model']
            ?? $strategyConfig['factors']
            ?? [];
        if (! is_array($rows)) {
            return $out;
        }

        $wanted = array_merge(self::PERIOD_KEYS, ['lookback_days', 'benchmark']);
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $params = is_array($row['parameters'] ?? null) ? $row['parameters'] : [];
            foreach ($wanted as $key) {
                if (array_key_exists($key, $params)) {
                    $out[$key] = $params[$key];
                }
            }
        }

        return $out;
    }

    protected function validPositiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || is_bool($value)) {
            return null;
        }
        if (! is_numeric($value)) {
            return null;
        }
        $asFloat = (float) $value;
        $asInt = (int) $value;
        if ($asFloat !== (float) $asInt) {
            return null;
        }
        if ($asInt < 1) {
            return null;
        }

        return $asInt;
    }

    protected function validBenchmarkSymbol(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }
        $symbol = strtoupper(trim((string) $value));
        if ($symbol === '') {
            return null;
        }

        $def = $this->indexes->definitionForSymbol($symbol);
        if ($def !== null && ($def['enabled'] ?? true) === true) {
            return $symbol;
        }

        return null;
    }
}
