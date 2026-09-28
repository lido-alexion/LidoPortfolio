<?php

namespace App\Services\Strategy;

use App\Engines\Strategy\SupportedIndicators;
use App\Models\TradingStrategy;
use App\Models\TradingStrategyVersion;
use Illuminate\Validation\ValidationException;

/**
 * FEAT-064 WP-08 — separate persistence from readiness / enablement.
 */
class StrategyReadinessService
{
    /**
     * @return array{
     *     ready: bool,
     *     status: 'ready'|'setup_required',
     *     requirements: list<array{code:string,message:string,action:string}>
     * }
     */
    public function assess(TradingStrategy $strategy, TradingStrategyVersion $version): array
    {
        $config = is_array($version->config_json) ? $version->config_json : [];
        $requirements = [];

        $sources = is_array($config['eligibility_sources'] ?? null) ? $config['eligibility_sources'] : [];
        $enabledSources = array_values(array_filter(
            $sources,
            fn (array $row) => (bool) ($row['enabled'] ?? true) && (int) ($row['screener_id'] ?? 0) > 0
        ));
        if ($enabledSources === []) {
            $requirements[] = [
                'code' => 'eligibility_missing',
                'message' => 'Add at least one enabled screener for eligibility.',
                'action' => 'assign_screeners',
            ];
        }

        $weightSum = 0.0;
        $enabledIndicators = 0;
        foreach ($config['indicators'] ?? [] as $indicator) {
            if (! ($indicator['enabled'] ?? false)) {
                continue;
            }
            $enabledIndicators++;
            $weightSum += (float) ($indicator['weight'] ?? 0);
        }
        if ($enabledIndicators === 0) {
            $requirements[] = [
                'code' => 'indicators_disabled',
                'message' => 'Enable at least one scoring indicator with a positive weight.',
                'action' => 'configure_indicators',
            ];
        } elseif (abs($weightSum - 100.0) > 0.01) {
            $requirements[] = [
                'code' => 'indicator_weights_invalid',
                'message' => 'Enabled indicator weights must sum to 100%.',
                'action' => 'configure_indicators',
            ];
        }

        foreach (SupportedIndicators::keys() as $requiredKey) {
            $found = false;
            foreach ($config['indicators'] ?? [] as $indicator) {
                if ((string) ($indicator['key'] ?? '') === $requiredKey) {
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                $requirements[] = [
                    'code' => 'indicator_catalogue_incomplete',
                    'message' => 'Strategy configuration is missing required catalogue indicators.',
                    'action' => 'configure_indicators',
                ];
                break;
            }
        }

        $ready = $requirements === [];

        return [
            'ready' => $ready,
            'status' => $ready ? 'ready' : 'setup_required',
            'requirements' => $requirements,
        ];
    }

    public function assertReadyForEnable(TradingStrategy $strategy, TradingStrategyVersion $version): void
    {
        $assessment = $this->assess($strategy, $version);
        if ($assessment['ready']) {
            return;
        }

        throw ValidationException::withMessages([
            'readiness' => array_map(
                fn (array $row) => $row['message'],
                $assessment['requirements'],
            ),
            'requirements' => $assessment['requirements'],
        ]);
    }
}
