<?php

namespace App\Services\Simulation;

use App\Engines\Evaluation\EvaluationParameterResolver;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Services\Backtest\AsOfFactorScorer;
use App\Services\Screener\ScreenerEvaluationService;
use App\Services\StrategyConfigurationService;
use App\Support\TradingCalendar;
use App\Support\TradingOsConfig;
use Carbon\Carbon;

final class ReplayStrategyEvaluator
{
    public function __construct(
        private ScreenerEvaluationService $screeners,
        private AsOfFactorScorer $scorer,
        private StrategyConfigurationService $strategies,
        private EvaluationParameterResolver $parameters,
    ) {}

    /** @return array{state:array<string,mixed>,generated:int,limitations:list<string>} */
    public function evaluate(array $state, array $pinnedWorld, string $session): array
    {
        $pending = is_array($state['pending_recommendations'] ?? null) ? $state['pending_recommendations'] : [];
        $limitations = [];
        $generated = 0;
        $stockIds = StockPrice::query()->whereDate('price_date', $session)->whereNotNull('close_price')
            ->distinct()->pluck('stock_id')->map(fn ($id) => (int) $id)->all();
        $symbols = Stock::query()->whereIn('id', $stockIds)->pluck('symbol', 'id');

        foreach ($pinnedWorld['binding_revisions'] ?? [] as $binding) {
            $strategyId = (int) ($binding['strategy_id'] ?? 0);
            $config = is_array($binding['strategy_definition'] ?? null) ? $binding['strategy_definition'] : [];
            $definitions = collect($binding['dependencies'] ?? [])->filter(
                fn ($dependency): bool => is_array($dependency)
                    && ($dependency['artifact_type'] ?? null) === 'screener'
                    && is_array($dependency['definition'] ?? null)
            )->pluck('definition')->all();
            if ($strategyId < 1 || $definitions === []) {
                $limitations[] = 'pinned_strategy_eligibility_missing';

                continue;
            }

            $strategyState = collect($state['strategies'] ?? [])->firstWhere('strategy_id', $strategyId);
            $availableCapital = max(0.0, (float) ($strategyState['available_capital'] ?? 0));
            $held = collect($state['holdings'] ?? [])->filter(fn ($holding) => (int) ($holding['strategy_id'] ?? 0) === $strategyId)
                ->keyBy(fn ($holding) => (int) $holding['stock_id']);
            $candidates = array_values(array_unique([...$stockIds, ...$held->keys()->map(fn ($id) => (int) $id)->all()]));
            foreach ($candidates as $stockId) {
                $bars = $this->bars($stockId, $session);
                $eligible = false;
                foreach ($definitions as $definition) {
                    $result = $this->screeners->evaluateStock($definition, $bars, $this->entityBars($definition, $session));
                    $eligible = $eligible || ($result['matched'] ?? false);
                }
                $holding = $held->get($stockId);
                if (! $eligible && $holding === null) {
                    continue;
                }

                $evaluation = $this->scorer->score($stockId, $session, $this->parameters->resolve($config));
                if ($evaluation['skipped'] ?? false) {
                    continue;
                }
                $score = (float) $this->strategies->score($evaluation['factor_scores'] ?? [], $config)['overall_score'];
                $thresholds = $config[TradingOsConfig::STRATEGY_THRESHOLDS] ?? [];
                $buyMin = (float) ($thresholds[TradingOsConfig::THRESHOLD_OPEN_POSITION] ?? TradingOsConfig::recommendationBuyScoreMin());
                $sellMax = (float) ($thresholds[TradingOsConfig::THRESHOLD_EXIT_POSITION] ?? TradingOsConfig::recommendationSellScoreMax());
                $side = $holding !== null && $score <= $sellMax ? 'sell' : ($holding === null && $eligible && $score >= $buyMin ? 'buy' : null);
                if ($side === null) {
                    continue;
                }
                $allocation = min(100.0, max(0.0, $this->strategies->allocationPctForScore($score, $config)));
                $key = hash('sha256', implode('|', [$binding['artifact_version_id'] ?? '', $strategyId, $stockId, $session, $side]));
                $pending[] = [
                    'key' => $key, 'stock_id' => $stockId, 'strategy_id' => $strategyId,
                    'symbol' => (string) ($symbols[$stockId] ?? $stockId), 'exchange' => 'NSE',
                    'side' => $side, 'quantity' => $side === 'sell' ? (float) ($holding['quantity'] ?? 0) : null,
                    'target_amount' => $side === 'buy' ? round($availableCapital * ($allocation / 100), 4) : null,
                    'decision_session' => $session,
                    'first_eligible_session' => TradingCalendar::addSessions(Carbon::parse($session), 1)->toDateString(),
                    'status' => 'pending',
                    'evidence' => [
                        'artifact_version_id' => $binding['artifact_version_id'] ?? null,
                        'definition_hash' => $binding['definition_hash'] ?? null,
                        'score' => $score, 'confidence' => $evaluation['confidence'] ?? null,
                        'factor_scores' => $evaluation['factor_scores'] ?? [],
                    ],
                ];
                $generated++;
            }
        }
        $state['pending_recommendations'] = $pending;

        return ['state' => $state, 'generated' => $generated, 'limitations' => array_values(array_unique($limitations))];
    }

    /** @return list<array<string,mixed>> */
    private function bars(int $stockId, string $session): array
    {
        return StockPrice::query()->where('stock_id', $stockId)->whereDate('price_date', '<=', $session)
            ->orderByDesc('price_date')->limit(400)->get()->reverse()->values()->map(fn ($row) => [
                'date' => $row->price_date->toDateString(), 'open' => $row->open_price !== null ? (float) $row->open_price : null,
                'high' => $row->high_price !== null ? (float) $row->high_price : null,
                'low' => $row->low_price !== null ? (float) $row->low_price : null,
                'close' => $row->close_price !== null ? (float) $row->close_price : null,
                'adjusted_close' => $row->adjusted_close_price !== null ? (float) $row->adjusted_close_price : null,
                'volume' => $row->volume !== null ? (float) $row->volume : null,
            ])->all();
    }

    /** @return array<string,list<array<string,mixed>>> */
    private function entityBars(array $definition, string $session): array
    {
        $out = [];
        foreach ($this->screeners->entityLookbacks($definition) as $symbol => $lookback) {
            $stockId = Stock::query()->where('symbol', $symbol)->value('id');
            $out[$symbol] = $stockId ? array_slice($this->bars((int) $stockId, $session), -max(1, $lookback)) : [];
        }

        return $out;
    }
}
