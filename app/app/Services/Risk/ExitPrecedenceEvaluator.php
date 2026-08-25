<?php

namespace App\Services\Risk;

use App\Engines\Strategy\ExitStrategyEvaluator;
use App\Models\Holding;
use App\Models\PortfolioProfile;
use App\Models\Stock;
use App\Services\ProfileSettingsService;
use Carbon\Carbon;
use InvalidArgumentException;

/**
 * V3 §13.2 exit precedence evaluator for live recommendation generation.
 *
 * Precedence (frozen): strategy_exit > stop_loss > trailing_stop > horizon_expiry.
 *
 * Portfolio SL / trailing use Phase 1 calculators (OD-13 / OD-14 / OD-22).
 * Strategy-specific ExitStrategyEvaluator remains the strategy_exit mechanism only
 * (including any V1 strategy JSON trailing_stop proxy — that is not portfolio trailing).
 */
final class ExitPrecedenceEvaluator
{
    public function __construct(
        protected ProfileSettingsService $profileSettings,
        protected OwnershipEpisodeService $ownershipEpisodes,
        protected PortfolioStopLossCalculator $stopLossCalculator,
        protected PortfolioTrailingStopCalculator $trailingStopCalculator,
    ) {}

    /**
     * @param  array<string, mixed>  $exitStrategyConfig  Strategy exit_strategy JSON
     * @param  array<string, mixed>  $strategyExitContext  Context for ExitStrategyEvaluator
     * @param  array<string, mixed>  $strategyConfig       Full strategy config (horizon lookup)
     * @return array{
     *   triggered: bool,
     *   primary_reason: ?string,
     *   also_true: list<string>,
     *   mechanisms: array<string, array<string, mixed>>,
     *   strategy_exit_eval: array{triggered: bool, matched: list<array<string, mixed>>, status: string},
     *   status: string
     * }
     */
    public function evaluate(
        PortfolioProfile $profile,
        Holding $holding,
        Stock $stock,
        array $exitStrategyConfig,
        array $strategyExitContext,
        array $strategyConfig = [],
        ?Carbon $asOf = null,
    ): array {
        $asOf ??= Carbon::now();

        $strategyExitEval = ExitStrategyEvaluator::evaluate($exitStrategyConfig, $strategyExitContext);
        $mechanisms = [];

        $mechanisms[ExitAttribution::STRATEGY_EXIT] = [
            'triggered' => (bool) ($strategyExitEval['triggered'] ?? false),
            'detail' => $strategyExitEval,
        ];

        $mechanisms[ExitAttribution::STOP_LOSS] = $this->evaluatePortfolioStopLoss($profile, $holding, $stock);
        $mechanisms[ExitAttribution::TRAILING_STOP] = $this->evaluatePortfolioTrailing($profile, $holding, $stock);
        $mechanisms[ExitAttribution::HORIZON_EXPIRY] = $this->evaluateHorizon(
            $profile,
            $holding,
            $stock,
            $strategyConfig,
            $asOf,
        );

        $true = [];
        foreach (ExitAttribution::PRECEDENCE as $reason) {
            if (! empty($mechanisms[$reason]['triggered'])) {
                $true[] = $reason;
            }
        }

        $primary = $true[0] ?? null;
        $alsoTrue = $primary !== null ? array_values(array_slice($true, 1)) : [];

        return [
            'triggered' => $primary !== null,
            'primary_reason' => $primary,
            'also_true' => $alsoTrue,
            'mechanisms' => $mechanisms,
            'strategy_exit_eval' => $strategyExitEval,
            'status' => $primary !== null ? 'Triggered' : 'Not Triggered',
        ];
    }

    /**
     * Resolve optional strategy horizon in calendar days. Returns null when unset.
     * Does not invent a default horizon.
     *
     * @param  array<string, mixed>  $strategyConfig
     */
    public function horizonCalendarDays(array $strategyConfig): ?int
    {
        $candidates = [
            data_get($strategyConfig, 'portfolio_rules.horizon_calendar_days'),
            data_get($strategyConfig, 'horizon_calendar_days'),
            data_get($strategyConfig, 'exit_strategy.horizon_calendar_days'),
        ];

        foreach ($candidates as $value) {
            if ($value === null || $value === '') {
                continue;
            }
            if (! is_numeric($value)) {
                continue;
            }
            $days = (int) $value;
            if ($days > 0) {
                return $days;
            }
        }

        return null;
    }

    /**
     * @return array{triggered: bool, detail: array<string, mixed>}
     */
    protected function evaluatePortfolioStopLoss(
        PortfolioProfile $profile,
        Holding $holding,
        Stock $stock,
    ): array {
        $entry = $this->ownershipEpisodes->firstBuyDateForHolding($profile, $holding, $stock);
        $fills = $this->ownershipEpisodes->fillsForCurrentEpisode($profile, $holding, $stock);
        $latestRaw = $entry !== null
            ? $this->ownershipEpisodes->latestRawCloseSinceEntry($stock, $entry)
            : null;

        $stoplossPercent = (float) $this->profileSettings->get($profile, 'default_stoploss_percent', '10');
        $detail = [
            'stoploss_percent' => $stoplossPercent,
            'entry_date' => $entry?->toDateString(),
            'latest_raw_close' => $latestRaw,
            'weighted_average_fill_cost' => null,
            'stop_price' => null,
        ];

        if ($entry === null || $fills === [] || $latestRaw === null) {
            return ['triggered' => false, 'detail' => $detail];
        }

        try {
            $avgCost = $this->stopLossCalculator->weightedAverageFillCost($fills);
            $stopPrice = $this->stopLossCalculator->stopPrice($avgCost, $stoplossPercent);
        } catch (InvalidArgumentException) {
            return ['triggered' => false, 'detail' => $detail];
        }

        $detail['weighted_average_fill_cost'] = $avgCost;
        $detail['stop_price'] = $stopPrice;
        $hit = $this->stopLossCalculator->isHitByRawClose($latestRaw, $stopPrice);

        return ['triggered' => $hit, 'detail' => $detail];
    }

    /**
     * @return array{triggered: bool, detail: array<string, mixed>}
     */
    protected function evaluatePortfolioTrailing(
        PortfolioProfile $profile,
        Holding $holding,
        Stock $stock,
    ): array {
        $entry = $this->ownershipEpisodes->firstBuyDateForHolding($profile, $holding, $stock);
        $trailingPercent = (float) $this->profileSettings->get($profile, 'portfolio_trailing_percent', '15');
        $peak = $entry !== null
            ? $this->ownershipEpisodes->peakRawCloseSinceEntry($stock, $entry)
            : null;
        $latestRaw = $entry !== null
            ? $this->ownershipEpisodes->latestRawCloseSinceEntry($stock, $entry)
            : null;

        $trailingStop = $this->trailingStopCalculator->trailingStopPrice($peak, $trailingPercent);

        $detail = [
            'portfolio_trailing_percent' => $trailingPercent,
            'entry_date' => $entry?->toDateString(),
            'peak_raw_close' => $peak,
            'trailing_stop_price' => $trailingStop,
            'latest_raw_close' => $latestRaw,
        ];

        if ($trailingStop === null || $latestRaw === null) {
            return ['triggered' => false, 'detail' => $detail];
        }

        // Hit when latest raw close is at/below trailing stop (same comparison style as SL).
        $hit = $latestRaw <= $trailingStop;

        return ['triggered' => $hit, 'detail' => $detail];
    }

    /**
     * @param  array<string, mixed>  $strategyConfig
     * @return array{triggered: bool, detail: array<string, mixed>}
     */
    protected function evaluateHorizon(
        PortfolioProfile $profile,
        Holding $holding,
        Stock $stock,
        array $strategyConfig,
        Carbon $asOf,
    ): array {
        $horizonDays = $this->horizonCalendarDays($strategyConfig);
        $entry = $this->ownershipEpisodes->firstBuyDateForHolding($profile, $holding, $stock);

        $detail = [
            'horizon_calendar_days' => $horizonDays,
            'entry_date' => $entry?->toDateString(),
            'as_of' => $asOf->toDateString(),
            'calendar_days_held' => null,
        ];

        if ($horizonDays === null || $entry === null) {
            return ['triggered' => false, 'detail' => $detail];
        }

        $daysHeld = (int) $entry->copy()->startOfDay()->diffInDays($asOf->copy()->startOfDay());
        $detail['calendar_days_held'] = $daysHeld;
        // OD-02: eligible when calendar days since entry ≥ T
        $hit = $daysHeld >= $horizonDays;

        return ['triggered' => $hit, 'detail' => $detail];
    }
}
