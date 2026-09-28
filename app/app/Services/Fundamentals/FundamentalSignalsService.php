<?php

namespace App\Services\Fundamentals;

use App\Models\Stock;
use App\Models\V7\FundamentalFact;
use Carbon\Carbon;

/**
 * V8 FEAT-062 — deterministic fundamental signals (no LLM).
 */
class FundamentalSignalsService
{
    public function __construct(
        protected FundamentalDataService $fundamentals,
        protected FundamentalInvestorSnapshotService $snapshots,
        protected FundamentalSectorContextService $sectorContext,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function deterministicInsights(Stock $stock, ?Carbon $asOf = null): array
    {
        $asOf ??= now();
        $price = $this->snapshots->latestMarketPrice($stock, $asOf);
        $positive = [];
        $risk = [];
        $watch = [];

        $roe = $this->fundamentals->metric($stock, 'roe', 'ttm', $asOf, $price);
        if ($roe['value'] !== null && (float) $roe['value'] >= 15) {
            $positive[] = $this->signal(
                'roe_strong',
                'ROE (TTM) is at or above 15%',
                ['roe_ttm_pct' => (float) $roe['value']],
            );
        } elseif ($roe['value'] !== null && (float) $roe['value'] < 5) {
            $risk[] = $this->signal(
                'roe_weak',
                'ROE (TTM) is below 5%',
                ['roe_ttm_pct' => (float) $roe['value']],
            );
        }

        $de = $this->fundamentals->metric($stock, 'debt_equity', 'ttm', $asOf, $price);
        if ($de['value'] !== null && (float) $de['value'] > 2) {
            $risk[] = $this->signal(
                'leverage_elevated',
                'Debt / equity exceeds 2×',
                ['debt_equity' => (float) $de['value']],
            );
        }

        $revGrowth = $this->fundamentals->growthMetric(
            $stock,
            'revenue',
            FundamentalDataService::CADENCE_QUARTERLY,
            $asOf,
        );
        if ($revGrowth['value'] !== null) {
            $g = (float) $revGrowth['value'];
            if ($g >= 15) {
                $positive[] = $this->signal(
                    'revenue_growth_strong',
                    'Quarterly revenue YoY growth is at or above 15%',
                    ['revenue_yoy_pct' => $g],
                );
            } elseif ($g <= -10) {
                $risk[] = $this->signal(
                    'revenue_decline',
                    'Quarterly revenue YoY change is −10% or worse',
                    ['revenue_yoy_pct' => $g],
                );
            }
        }

        $fcf = $this->fundamentals->metric($stock, 'free_cash_flow', 'ttm', $asOf, $price);
        $ni = $this->fundamentals->metric($stock, 'net_income', 'ttm', $asOf, $price);
        if ($fcf['value'] !== null && $ni['value'] !== null && (float) $ni['value'] > 0 && (float) $fcf['value'] < 0) {
            $risk[] = $this->signal(
                'negative_fcf_with_profit',
                'TTM free cash flow is negative while net income is positive',
                [
                    'free_cash_flow_ttm' => (float) $fcf['value'],
                    'net_income_ttm' => (float) $ni['value'],
                ],
            );
        }

        $this->evaluateWorkingCapitalSignals($stock, $asOf, $risk, $watch);
        $this->evaluateCwipSignals($stock, $asOf, $watch);
        $this->evaluateMarginSignals($stock, $asOf, $positive, $risk, $watch);
        $this->evaluateOperatingProfitDivergence($stock, $asOf, $watch);
        $this->evaluateOtherIncomeAndCapexSignals($stock, $asOf, $watch);
        $this->evaluateReturnOnEquityMovement($stock, $asOf, $positive, $risk, $watch);
        $this->evaluateReturnOnCapitalMovements($stock, $asOf, $positive, $risk, $watch);
        $this->evaluateEarningsCashSignals($stock, $asOf, $risk, $watch);
        $this->evaluateLeverageSignals($stock, $asOf, $positive, $risk, $watch);
        $this->evaluateDilutionSignals($stock, $asOf, $risk, $watch);
        $this->evaluateOwnershipSignals($stock, $asOf, $watch);
        $this->evaluateOwnershipFinancialContext($stock, $asOf, $watch);
        $this->evaluateValuationDivergence($stock, $asOf, $watch);
        $this->evaluateHistoricalValuationRange($stock, $asOf, $watch);
        $this->evaluateWorkingCapitalBalanceSheetSignals($stock, $asOf, $positive, $risk, $watch);
        $this->evaluateGrowthRelationships($stock, $asOf, $positive, $risk, $watch);
        $this->evaluateCashAndNetDebtTrends($stock, $asOf, $positive, $risk, $watch);

        $ocf = $this->fundamentals->metric($stock, 'operating_cash_flow', 'ttm', $asOf, $price);
        if ($ocf['value'] !== null && $ni['value'] !== null && (float) $ni['value'] > 0) {
            $ocfVal = (float) $ocf['value'];
            $niVal = (float) $ni['value'];
            if ($ocfVal < $niVal * 0.5) {
                $risk[] = $this->signal(
                    'weak_ocf_vs_net_income',
                    'TTM operating cash flow is less than half of TTM net income',
                    [
                        'operating_cash_flow_ttm' => $ocfVal,
                        'net_income_ttm' => $niVal,
                    ],
                );
            } elseif ($ocfVal >= $niVal) {
                $positive[] = $this->signal(
                    'strong_ocf_vs_net_income',
                    'TTM operating cash flow meets or exceeds TTM net income',
                    [
                        'operating_cash_flow_ttm' => $ocfVal,
                        'net_income_ttm' => $niVal,
                    ],
                );
            }
        }

        $sector = $this->sectorContext->contextFor($stock, $asOf, $price);
        $this->evaluateSectorComparisons($sector, $positive, $risk, $watch);
        $missing = $this->missingEvidence($stock, $asOf);
        $sufficiency = $this->sufficiencyAssessment($stock, $asOf, $missing);

        return [
            'summary' => $this->buildSummary($positive, $risk, $watch),
            'positive_signals' => $positive,
            'risk_signals' => $risk,
            'watch_items' => $watch,
            'sector_context' => $sector,
            'follow_up_checks' => $this->followUpChecks(array_merge($risk, $watch)),
            'data_sufficiency' => [
                'rating' => $sufficiency['rating'],
                'score' => $sufficiency['score'],
                'missing_information' => $missing,
                'quality_factors' => $sufficiency['quality_factors'],
                'weighting' => $sufficiency['weighting'],
            ],
            'ai' => [
                'status' => 'not_requested',
            ],
        ];
    }

    /** Compare operating-profit and bottom-line growth on the same comparable periods. */
    protected function evaluateOperatingProfitDivergence(Stock $stock, Carbon $asOf, array &$watch): void
    {
        $operating = $this->fundamentals->growthMetric($stock, 'operating_profit', FundamentalDataService::CADENCE_QUARTERLY, $asOf);
        $netIncome = $this->fundamentals->growthMetric($stock, 'net_income', FundamentalDataService::CADENCE_QUARTERLY, $asOf);
        if ($operating['value'] === null || $netIncome['value'] === null) {
            return;
        }

        $delta = (float) $operating['value'] - (float) $netIncome['value'];
        if (abs($delta) < 15.0) {
            return;
        }

        $watch[] = $this->signal(
            'operating_profit_bottom_line_divergence',
            'Operating-profit growth diverged materially from bottom-line growth',
            [
                'operating_profit_yoy_pct' => (float) $operating['value'],
                'net_income_yoy_pct' => (float) $netIncome['value'],
                'delta_pp' => round($delta, 2),
                'basis' => 'quarterly_yoy',
            ],
        );
    }

    protected function evaluateOtherIncomeAndCapexSignals(Stock $stock, Carbon $asOf, array &$watch): void
    {
        $income = $this->comparablePair($stock, 'net_income', $asOf);
        foreach (['other_income' => 'Other income', 'exceptional_items' => 'Exceptional items'] as $factKey => $label) {
            $other = $this->comparablePair($stock, $factKey, $asOf);
            if ($income === null || $other === null
                || $income['current_period'] !== $other['current_period']
                || $income['prior_period'] !== $other['prior_period']
                || $income['current'] <= 0.0 || $income['prior'] <= 0.0) {
                continue;
            }

            $currentShare = abs($other['current'] / $income['current']) * 100;
            $priorShare = abs($other['prior'] / $income['prior']) * 100;
            if ($currentShare >= 25.0 && $currentShare >= $priorShare + 10.0) {
                $watch[] = $this->signal('other_income_exceptional_dependence', $label.' contributes materially to reported net income', [
                    'fact_key' => $factKey,
                    'current_share_of_net_income_pct' => round($currentShare, 2),
                    'prior_share_of_net_income_pct' => round($priorShare, 2),
                    'delta_pp' => round($currentShare - $priorShare, 2),
                    'period' => $income['current_period'],
                    'comparison_period' => $income['prior_period'],
                    'basis' => 'same_period_yoy_share_of_net_income',
                ]);
            }
        }

        $capex = $this->comparablePair($stock, 'capital_expenditure', $asOf);
        $revenue = $this->comparablePair($stock, 'revenue', $asOf);
        if ($capex === null || $revenue === null
            || $capex['current_period'] !== $revenue['current_period']
            || $capex['prior_period'] !== $revenue['prior_period']
            || $revenue['current'] <= 0.0 || $revenue['prior'] <= 0.0) {
            return;
        }

        $currentIntensity = abs($capex['current'] / $revenue['current']) * 100;
        $priorIntensity = abs($capex['prior'] / $revenue['prior']) * 100;
        $delta = $currentIntensity - $priorIntensity;
        if (abs($delta) >= 5.0) {
            $watch[] = $this->signal(
                $delta > 0 ? 'capex_intensity_increasing' : 'capex_intensity_decreasing',
                'Capital-expenditure intensity changed materially year over year',
                [
                    'current_capex_intensity_pct' => round($currentIntensity, 2),
                    'prior_capex_intensity_pct' => round($priorIntensity, 2),
                    'delta_pp' => round($delta, 2),
                    'period' => $capex['current_period'],
                    'comparison_period' => $capex['prior_period'],
                    'basis' => 'same_period_yoy_capex_to_revenue',
                ],
            );
        }
    }

    /** ROA and ROCE are emitted only when their canonical denominators exist and are positive. */
    protected function evaluateReturnOnCapitalMovements(Stock $stock, Carbon $asOf, array &$positive, array &$risk, array &$watch): void
    {
        foreach ([
            ['roa', 'net_income', 'total_assets', 'Return on assets'],
            ['roce', 'operating_profit', 'capital_employed', 'Return on capital employed'],
        ] as [$key, $numeratorKey, $denominatorKey, $label]) {
            $numerator = $this->comparablePair($stock, $numeratorKey, $asOf);
            $denominator = $this->comparablePair($stock, $denominatorKey, $asOf);
            if ($numerator === null || $denominator === null
                || $numerator['current_period'] !== $denominator['current_period']
                || $numerator['prior_period'] !== $denominator['prior_period']
                || $denominator['current'] <= 0.0 || $denominator['prior'] <= 0.0) {
                continue;
            }

            $current = ($numerator['current'] / $denominator['current']) * 100;
            $prior = ($numerator['prior'] / $denominator['prior']) * 100;
            $delta = $current - $prior;
            $evidence = [
                'current_'.$key.'_pct' => round($current, 2),
                'prior_'.$key.'_pct' => round($prior, 2),
                'delta_pp' => round($delta, 2),
                'period' => $numerator['current_period'],
                'comparison_period' => $numerator['prior_period'],
                'basis' => 'same_period_yoy',
            ];

            if ($delta >= 5.0) {
                $positive[] = $this->signal($key.'_movement_expanding', $label.' expanded by at least 5 percentage points year over year', $evidence);
            } elseif ($delta <= -5.0) {
                $risk[] = $this->signal($key.'_movement_contracting', $label.' contracted by at least 5 percentage points year over year', $evidence);
            } elseif (abs($delta) < 1.0) {
                $watch[] = $this->signal($key.'_movement_stable', $label.' was broadly stable year over year', $evidence);
            }
        }
    }

    protected function evaluateWorkingCapitalBalanceSheetSignals(Stock $stock, Carbon $asOf, array &$positive, array &$risk, array &$watch): void
    {
        $currentAssets = $this->comparablePair($stock, 'current_assets', $asOf);
        $currentLiabilities = $this->comparablePair($stock, 'current_liabilities', $asOf);
        if ($currentAssets !== null && $currentLiabilities !== null
            && $currentAssets['current_period'] === $currentLiabilities['current_period']
            && $currentAssets['prior_period'] === $currentLiabilities['prior_period']
            && $currentLiabilities['current'] > 0.0 && $currentLiabilities['prior'] > 0.0) {
            $currentRatio = $currentAssets['current'] / $currentLiabilities['current'];
            $priorRatio = $currentAssets['prior'] / $currentLiabilities['prior'];
            $delta = $currentRatio - $priorRatio;
            if (abs($delta) >= 0.25) {
                $key = $delta < 0 ? 'liquidity_coverage_deteriorating' : 'liquidity_coverage_improving';
                $signal = $this->signal($key, 'Current ratio changed materially year over year', [
                    'current_ratio' => round($currentRatio, 2),
                    'prior_ratio' => round($priorRatio, 2),
                    'delta' => round($delta, 2),
                    'period' => $currentAssets['current_period'],
                    'comparison_period' => $currentAssets['prior_period'],
                    'basis' => 'same_period_yoy',
                ]);
                if ($delta < 0) {
                    $risk[] = $signal;
                } else {
                    $positive[] = $signal;
                }
            }
        }

        $receivables = $this->comparablePair($stock, 'trade_receivables', $asOf);
        $inventory = $this->comparablePair($stock, 'inventory', $asOf);
        if ($receivables === null || $inventory === null || $currentLiabilities === null
            || $receivables['current_period'] !== $inventory['current_period']
            || $receivables['prior_period'] !== $inventory['prior_period']
            || $receivables['current_period'] !== $currentLiabilities['current_period']
            || $receivables['prior_period'] !== $currentLiabilities['prior_period']) {
            return;
        }

        $currentAbsorption = ($receivables['current'] + $inventory['current']) - $currentLiabilities['current'];
        $priorAbsorption = ($receivables['prior'] + $inventory['prior']) - $currentLiabilities['prior'];
        if ($priorAbsorption === 0.0) {
            return;
        }
        $deltaPct = (($currentAbsorption - $priorAbsorption) / abs($priorAbsorption)) * 100;
        if ($deltaPct >= 25.0) {
            $risk[] = $this->signal('working_capital_absorption', 'Working-capital absorption increased materially year over year', [
                'current_absorption' => round($currentAbsorption, 2),
                'prior_absorption' => round($priorAbsorption, 2),
                'delta_pct' => round($deltaPct, 2),
                'basis' => 'receivables_plus_inventory_less_current_liabilities',
                'period' => $receivables['current_period'],
                'comparison_period' => $receivables['prior_period'],
            ]);
        } elseif ($deltaPct <= -25.0) {
            $watch[] = $this->signal('working_capital_release', 'Working-capital absorption reduced materially year over year', [
                'current_absorption' => round($currentAbsorption, 2),
                'prior_absorption' => round($priorAbsorption, 2),
                'delta_pct' => round($deltaPct, 2),
                'basis' => 'receivables_plus_inventory_less_current_liabilities',
                'period' => $receivables['current_period'],
                'comparison_period' => $receivables['prior_period'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $metricValues
     * @return array<string, mixed>
     */
    /**
     * @param  list<array<string, mixed>>  $risk
     * @param  list<array<string, mixed>>  $watch
     */
    protected function evaluateWorkingCapitalSignals(Stock $stock, Carbon $asOf, array &$risk, array &$watch): void
    {
        $revenueGrowth = $this->fundamentals->growthMetric(
            $stock,
            'revenue',
            FundamentalDataService::CADENCE_QUARTERLY,
            $asOf,
        );
        $revYoY = $revenueGrowth['value'] !== null ? (float) $revenueGrowth['value'] : null;

        $receivableGrowth = $this->fundamentals->growthMetric(
            $stock,
            'trade_receivables',
            FundamentalDataService::CADENCE_QUARTERLY,
            $asOf,
        );
        if ($revYoY !== null && $receivableGrowth['value'] !== null) {
            $recvYoY = (float) $receivableGrowth['value'];
            $spread = $recvYoY - $revYoY;
            if ($spread >= 15.0) {
                $risk[] = $this->signal(
                    'receivables_growth_vs_revenue',
                    'Trade receivables YoY growth exceeds revenue YoY growth by 15+ percentage points',
                    [
                        'receivables_yoy_pct' => $recvYoY,
                        'revenue_yoy_pct' => $revYoY,
                        'spread_pp' => round($spread, 2),
                    ],
                );
            }
        }

        $inventoryGrowth = $this->fundamentals->growthMetric(
            $stock,
            'inventory',
            FundamentalDataService::CADENCE_QUARTERLY,
            $asOf,
        );
        if ($revYoY !== null && $inventoryGrowth['value'] !== null) {
            $invYoY = (float) $inventoryGrowth['value'];
            $spread = $invYoY - $revYoY;
            if ($spread >= 15.0) {
                $risk[] = $this->signal(
                    'inventory_growth_vs_revenue',
                    'Inventory YoY growth exceeds revenue YoY growth by 15+ percentage points',
                    [
                        'inventory_yoy_pct' => $invYoY,
                        'revenue_yoy_pct' => $revYoY,
                        'spread_pp' => round($spread, 2),
                    ],
                );
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $watch
     */
    protected function evaluateCwipSignals(Stock $stock, Carbon $asOf, array &$watch): void
    {
        $cwipGrowth = $this->fundamentals->growthMetric(
            $stock,
            'capital_work_in_progress',
            FundamentalDataService::CADENCE_ANNUAL,
            $asOf,
        );
        if ($cwipGrowth['value'] === null) {
            return;
        }

        $growth = (float) $cwipGrowth['value'];
        $facts = $this->fundamentals->factMap($stock, FundamentalDataService::CADENCE_ANNUAL, $asOf);
        $cwip = isset($facts['capital_work_in_progress']) ? (float) $facts['capital_work_in_progress']->value : null;
        $ppe = isset($facts['property_plant_equipment']) ? (float) $facts['property_plant_equipment']->value : null;
        $shareOfPpe = ($cwip !== null && $ppe !== null && $ppe > 0) ? ($cwip / $ppe) * 100 : null;

        if ($growth >= 25.0 || ($shareOfPpe !== null && $shareOfPpe >= 35.0)) {
            $watch[] = $this->signal(
                'cwip_expansion_ambiguous',
                'Capital work-in-progress is rising materially — review capex disclosures and commissioning timelines',
                array_filter([
                    'cwip_yoy_pct' => $growth,
                    'cwip_share_of_ppe_pct' => $shareOfPpe !== null ? round($shareOfPpe, 2) : null,
                ], fn ($v) => $v !== null),
            );
        }
    }

    /** Compare same-period margins only; no incompatible quarter mixing. */
    protected function evaluateMarginSignals(Stock $stock, Carbon $asOf, array &$positive, array &$risk, array &$watch): void
    {
        foreach ([
            ['operating_margin_movement', 'operating_profit', 'Operating margin'],
            ['ebit_margin_movement', 'ebit', 'EBIT margin'],
            ['net_margin_movement', 'net_income', 'Net margin'],
        ] as [$key, $numerator, $label]) {
            $pair = $this->comparablePair($stock, $numerator, $asOf);
            $revenuePair = $this->comparablePair($stock, 'revenue', $asOf);
            if ($pair === null || $revenuePair === null || $pair['current_period'] !== $revenuePair['current_period']
                || $pair['prior_period'] !== $revenuePair['prior_period']) {
                continue;
            }
            if ((float) $revenuePair['current'] === 0.0 || (float) $revenuePair['prior'] === 0.0) continue;
            $current = ((float) $pair['current'] / (float) $revenuePair['current']) * 100;
            $prior = ((float) $pair['prior'] / (float) $revenuePair['prior']) * 100;
            $delta = $current - $prior;
            $evidence = [
                'current_margin_pct' => round($current, 2),
                'prior_margin_pct' => round($prior, 2),
                'delta_pp' => round($delta, 2),
                'period' => $pair['current_period'],
                'comparison_period' => $pair['prior_period'],
                'basis' => 'same_period_yoy',
            ];
            if ($delta >= 5) {
                $positive[] = $this->signal($key.'_expanding', $label.' expanded by at least 5 percentage points year over year', $evidence);
            } elseif ($delta <= -5) {
                $risk[] = $this->signal($key.'_contracting', $label.' contracted by at least 5 percentage points year over year', $evidence);
            } elseif (abs($delta) < 1) {
                $watch[] = $this->signal($key.'_stable', $label.' was broadly stable year over year', $evidence);
            }
        }
    }

    protected function evaluateReturnOnEquityMovement(Stock $stock, Carbon $asOf, array &$positive, array &$risk, array &$watch): void
    {
        $income = $this->comparablePair($stock, 'net_income', $asOf);
        $equity = $this->comparablePair($stock, 'equity', $asOf);
        if ($income === null || $equity === null
            || $income['current_period'] !== $equity['current_period']
            || $income['prior_period'] !== $equity['prior_period']
            || $equity['current'] <= 0.0 || $equity['prior'] <= 0.0) {
            return;
        }

        $current = ($income['current'] / $equity['current']) * 100;
        $prior = ($income['prior'] / $equity['prior']) * 100;
        $delta = $current - $prior;
        $evidence = [
            'current_roe_pct' => round($current, 2),
            'prior_roe_pct' => round($prior, 2),
            'delta_pp' => round($delta, 2),
            'period' => $income['current_period'],
            'comparison_period' => $income['prior_period'],
            'basis' => 'same_period_yoy',
        ];

        if ($delta >= 5.0) {
            $positive[] = $this->signal('roe_movement_expanding', 'ROE expanded by at least 5 percentage points year over year', $evidence);
        } elseif ($delta <= -5.0) {
            $risk[] = $this->signal('roe_movement_contracting', 'ROE contracted by at least 5 percentage points year over year', $evidence);
        } elseif (abs($delta) < 1.0) {
            $watch[] = $this->signal('roe_movement_stable', 'ROE was broadly stable year over year', $evidence);
        }
    }

    protected function evaluateEarningsCashSignals(Stock $stock, Carbon $asOf, array &$risk, array &$watch): void
    {
        $income = $this->fundamentals->growthMetric($stock, 'net_income', FundamentalDataService::CADENCE_QUARTERLY, $asOf);
        $ocf = $this->fundamentals->growthMetric($stock, 'operating_cash_flow', FundamentalDataService::CADENCE_QUARTERLY, $asOf);
        if ($income['value'] !== null && $ocf['value'] !== null && (float) $income['value'] >= 10 && (float) $ocf['value'] <= -10) {
            $risk[] = $this->signal('earnings_cash_divergence', 'Net income growth is positive while operating cash flow growth is negative', [
                'net_income_yoy_pct' => (float) $income['value'],
                'operating_cash_flow_yoy_pct' => (float) $ocf['value'],
                'basis' => 'quarterly_yoy',
            ]);
            $watch[] = $this->signal('cash_quality_follow_up', 'Earnings and operating cash flow moved in opposite directions', [
                'net_income_yoy_pct' => (float) $income['value'],
                'operating_cash_flow_yoy_pct' => (float) $ocf['value'],
                'basis' => 'quarterly_yoy',
            ]);
        }

        $incomeTrend = $this->growthTrend($stock, 'net_income', $asOf);
        $ocfTrend = $this->growthTrend($stock, 'operating_cash_flow', $asOf);
        if ($incomeTrend !== null && $ocfTrend !== null
            && $incomeTrend['latest_yoy_pct'] >= 10.0 && $incomeTrend['prior_comparable_yoy_pct'] >= 10.0
            && $ocfTrend['latest_yoy_pct'] <= -10.0 && $ocfTrend['prior_comparable_yoy_pct'] <= -10.0) {
            $risk[] = $this->signal('earnings_cash_divergence_persistent', 'Positive earnings growth has coincided with repeated operating-cash-flow deterioration', [
                'latest_net_income_yoy_pct' => $incomeTrend['latest_yoy_pct'],
                'prior_net_income_yoy_pct' => $incomeTrend['prior_comparable_yoy_pct'],
                'latest_operating_cash_flow_yoy_pct' => $ocfTrend['latest_yoy_pct'],
                'prior_operating_cash_flow_yoy_pct' => $ocfTrend['prior_comparable_yoy_pct'],
                'period' => $incomeTrend['period'],
                'comparison_period' => $incomeTrend['comparison_period'],
                'basis' => 'two_consecutive_quarterly_yoy_comparisons',
            ]);
        }
    }

    protected function evaluateLeverageSignals(Stock $stock, Carbon $asOf, array &$positive, array &$risk, array &$watch): void
    {
        $debt = $this->fundamentals->growthMetric($stock, 'debt', FundamentalDataService::CADENCE_QUARTERLY, $asOf);
        if ($debt['value'] !== null) {
            $evidence = ['debt_yoy_pct' => (float) $debt['value'], 'basis' => 'quarterly_yoy'];
            if ((float) $debt['value'] >= 15) {
                $risk[] = $this->signal('debt_increasing', 'Debt increased materially year over year', $evidence);
            } elseif ((float) $debt['value'] <= -15) {
                $positive[] = $this->signal('debt_decreasing', 'Debt decreased materially year over year', $evidence);
            }
        }

        $debtToEbitda = $this->fundamentals->metric($stock, 'debt_to_ebitda', 'ttm', $asOf)['value'];
        if ($debtToEbitda !== null && (float) $debtToEbitda >= 4.0) {
            $watch[] = $this->signal('debt_to_ebitda_elevated', 'Debt / EBITDA is at or above 4×', [
                'debt_to_ebitda' => (float) $debtToEbitda,
                'basis' => 'ttm',
            ]);
        }
        $interestCoverage = $this->fundamentals->metric($stock, 'interest_coverage', 'ttm', $asOf)['value'];
        if ($interestCoverage !== null && (float) $interestCoverage < 2.0) {
            $risk[] = $this->signal('interest_coverage_thin', 'Interest coverage is below 2×', [
                'interest_coverage' => (float) $interestCoverage,
                'basis' => 'ttm',
            ]);
        }
    }

    protected function evaluateDilutionSignals(Stock $stock, Carbon $asOf, array &$risk, array &$watch): void
    {
        $shares = $this->fundamentals->growthMetric($stock, 'shares_outstanding', FundamentalDataService::CADENCE_QUARTERLY, $asOf);
        if ($shares['value'] !== null && (float) $shares['value'] >= 5) {
            $risk[] = $this->signal('share_count_dilution', 'Shares outstanding increased materially year over year', [
                'shares_yoy_pct' => (float) $shares['value'],
                'basis' => 'quarterly_yoy',
            ]);
            $watch[] = $this->signal('dilution_follow_up', 'Share count increased materially', [
                'shares_yoy_pct' => (float) $shares['value'],
                'basis' => 'quarterly_yoy',
            ]);
        }
    }

    /** Ownership movement is factual context, not an automatic bullish/bearish verdict. */
    protected function evaluateOwnershipSignals(Stock $stock, Carbon $asOf, array &$watch): void
    {
        foreach ([
            'promoter_holding' => 'Promoter holding',
            'fii_holding' => 'FII/FPI holding',
            'dii_holding' => 'DII holding',
            'public_holding' => 'Public holding',
            'promoter_pledge' => 'Promoter pledge',
        ] as $factKey => $label) {
            $pair = $this->comparablePair($stock, $factKey, $asOf);
            if ($pair === null) continue;
            $delta = $pair['current'] - $pair['prior'];
            if (abs($delta) < 1.0) continue;
            $watch[] = $this->signal('ownership_'.$factKey.'_movement', $label.' changed versus the comparable period', [
                'current_pct' => round($pair['current'], 2),
                'prior_pct' => round($pair['prior'], 2),
                'delta_pp' => round($delta, 2),
                'period' => $pair['current_period'],
                'comparison_period' => $pair['prior_period'],
                'basis' => 'same_period_yoy',
            ]);
        }
    }

    /** Ownership movement is contextualized only when a comparable financial movement exists. */
    protected function evaluateOwnershipFinancialContext(Stock $stock, Carbon $asOf, array &$watch): void
    {
        $promoter = $this->comparablePair($stock, 'promoter_holding', $asOf);
        $pledge = $this->comparablePair($stock, 'promoter_pledge', $asOf);
        $debt = $this->comparablePair($stock, 'debt', $asOf);
        if ($debt === null || ($promoter === null && $pledge === null)) {
            return;
        }

        $debtBase = abs($debt['prior']);
        $debtGrowth = $debtBase > 0.0 ? (($debt['current'] - $debt['prior']) / $debtBase) * 100 : null;
        if ($debtGrowth === null || $debtGrowth < 15.0) {
            return;
        }

        $ownershipEvidence = [];
        $contextFound = false;
        if ($promoter !== null
            && $promoter['current_period'] === $debt['current_period']
            && $promoter['prior_period'] === $debt['prior_period']
            && ($promoter['current'] - $promoter['prior']) <= -2.0) {
            $ownershipEvidence['promoter_holding_delta_pp'] = round($promoter['current'] - $promoter['prior'], 2);
            $contextFound = true;
        }
        if ($pledge !== null
            && $pledge['current_period'] === $debt['current_period']
            && $pledge['prior_period'] === $debt['prior_period']
            && ($pledge['current'] - $pledge['prior']) >= 2.0) {
            $ownershipEvidence['promoter_pledge_delta_pp'] = round($pledge['current'] - $pledge['prior'], 2);
            $contextFound = true;
        }
        if (! $contextFound) {
            return;
        }

        $watch[] = $this->signal('ownership_financial_context', 'Ownership movement coincided with a material increase in debt', array_merge($ownershipEvidence, [
            'debt_yoy_pct' => round($debtGrowth, 2),
            'period' => $debt['current_period'],
            'comparison_period' => $debt['prior_period'],
            'basis' => 'same_period_yoy_ownership_and_debt',
        ]));
    }

    protected function evaluateValuationDivergence(Stock $stock, Carbon $asOf, array &$watch): void
    {
        $price = $this->snapshots->latestMarketPrice($stock, $asOf);
        $pe = $this->fundamentals->metric($stock, 'pe', 'ttm', $asOf, $price)['value'];
        if ($pe === null || (float) $pe <= 0.0) {
            return;
        }

        $earnings = $this->fundamentals->growthMetric($stock, 'net_income', FundamentalDataService::CADENCE_QUARTERLY, $asOf);
        $cash = $this->fundamentals->growthMetric($stock, 'operating_cash_flow', FundamentalDataService::CADENCE_QUARTERLY, $asOf);
        $weakEarnings = $earnings['value'] !== null && (float) $earnings['value'] <= -10.0;
        $weakCash = $cash['value'] !== null && (float) $cash['value'] <= -10.0;
        if (! $weakEarnings && ! $weakCash) {
            return;
        }

        $watch[] = $this->signal('valuation_earnings_cash_divergence', 'Positive P/E coexists with weakening earnings or operating cash flow', [
            'pe_ttm' => (float) $pe,
            'net_income_yoy_pct' => $earnings['value'] !== null ? (float) $earnings['value'] : null,
            'operating_cash_flow_yoy_pct' => $cash['value'] !== null ? (float) $cash['value'] : null,
            'basis' => 'latest_valuation_with_quarterly_yoy_fundamental_trends',
        ]);
    }

    /** Use only a sufficiently populated PIT valuation history; no synthetic range is emitted. */
    protected function evaluateHistoricalValuationRange(Stock $stock, Carbon $asOf, array &$watch): void
    {
        $history = $this->snapshots->metricHistory($stock, 'pe', $asOf, '5y', 'monthly');
        $values = collect($history['points'] ?? [])
            ->pluck('value')
            ->filter(fn ($value): bool => is_numeric($value) && (float) $value > 0.0)
            ->map(fn ($value): float => (float) $value)
            ->values();
        if ($values->count() < 4) {
            return;
        }

        $latest = (float) $values->last();
        $sorted = $values->sort()->values();
        $lower = (float) $sorted[(int) floor(($sorted->count() - 1) * 0.25)];
        $upper = (float) $sorted[(int) floor(($sorted->count() - 1) * 0.75)];
        $buffer = 1.20;
        if ($latest > $upper * $buffer) {
            $watch[] = $this->signal('historical_pe_above_range', 'P/E is materially above the available historical valuation range', [
                'latest_pe' => round($latest, 2),
                'historical_p25_pe' => round($lower, 2),
                'historical_p75_pe' => round($upper, 2),
                'observations' => $values->count(),
                'basis' => 'five_year_pit_monthly_pe_range_with_20pct_buffer',
            ]);
        } elseif ($latest < $lower / $buffer) {
            $watch[] = $this->signal('historical_pe_below_range', 'P/E is materially below the available historical valuation range', [
                'latest_pe' => round($latest, 2),
                'historical_p25_pe' => round($lower, 2),
                'historical_p75_pe' => round($upper, 2),
                'observations' => $values->count(),
                'basis' => 'five_year_pit_monthly_pe_range_with_20pct_buffer',
            ]);
        }
    }

    protected function evaluateGrowthRelationships(Stock $stock, Carbon $asOf, array &$positive, array &$risk, array &$watch): void
    {
        $revenueTrend = $this->growthTrend($stock, 'revenue', $asOf);
        if ($revenueTrend !== null && abs($revenueTrend['delta_pp']) >= 10) {
            $key = $revenueTrend['delta_pp'] > 0 ? 'revenue_growth_accelerating' : 'revenue_growth_decelerating';
            $signal = $this->signal($key, 'Revenue growth changed materially versus the prior comparable quarter', $revenueTrend);
            if ($revenueTrend['delta_pp'] > 0) $positive[] = $signal;
            else $risk[] = $signal;
        }

        $revenue = $this->fundamentals->growthMetric($stock, 'revenue', FundamentalDataService::CADENCE_QUARTERLY, $asOf);
        $income = $this->fundamentals->growthMetric($stock, 'net_income', FundamentalDataService::CADENCE_QUARTERLY, $asOf);
        if ($revenue['value'] !== null && $income['value'] !== null && abs((float) $income['value'] - (float) $revenue['value']) >= 15) {
            $watch[] = $this->signal('earnings_revenue_divergence', 'Net income growth diverged materially from revenue growth', [
                'net_income_yoy_pct' => (float) $income['value'],
                'revenue_yoy_pct' => (float) $revenue['value'],
                'delta_pp' => round((float) $income['value'] - (float) $revenue['value'], 2),
                'basis' => 'quarterly_yoy',
            ]);
        }

        $debt = $this->fundamentals->growthMetric($stock, 'debt', FundamentalDataService::CADENCE_QUARTERLY, $asOf);
        $ocf = $this->fundamentals->growthMetric($stock, 'operating_cash_flow', FundamentalDataService::CADENCE_QUARTERLY, $asOf);
        if ($debt['value'] !== null && $ocf['value'] !== null && (float) $debt['value'] >= 15 && (float) $ocf['value'] <= 0) {
            $risk[] = $this->signal('debt_cash_divergence', 'Debt increased while operating cash flow did not improve', [
                'debt_yoy_pct' => (float) $debt['value'],
                'operating_cash_flow_yoy_pct' => (float) $ocf['value'],
                'basis' => 'quarterly_yoy',
            ]);
        }
    }

    protected function evaluateCashAndNetDebtTrends(Stock $stock, Carbon $asOf, array &$positive, array &$risk, array &$watch): void
    {
        $debtPair = $this->comparablePair($stock, 'debt', $asOf);
        $cashPair = $this->comparablePair($stock, 'cash_and_equivalents', $asOf);
        if ($debtPair !== null && $cashPair !== null
            && $debtPair['current_period'] === $cashPair['current_period']
            && $debtPair['prior_period'] === $cashPair['prior_period']) {
            $currentNetDebt = $debtPair['current'] - $cashPair['current'];
            $priorNetDebt = $debtPair['prior'] - $cashPair['prior'];
            $netDebt = $priorNetDebt !== 0.0
                ? (($currentNetDebt - $priorNetDebt) / abs($priorNetDebt)) * 100
                : null;
        } else {
            $netDebt = null;
        }
        if ($netDebt !== null) {
            $evidence = [
                'net_debt_yoy_pct' => (float) $netDebt,
                'basis' => 'quarterly_yoy',
            ];
            if ((float) $netDebt >= 15) {
                $risk[] = $this->signal('net_debt_increasing', 'Net debt increased materially year over year', $evidence);
            } elseif ((float) $netDebt <= -15) {
                $positive[] = $this->signal('net_debt_decreasing', 'Net debt decreased materially year over year', $evidence);
            }
        }

        $fcfTrend = $this->growthTrend($stock, 'free_cash_flow', $asOf);
        if ($fcfTrend === null) {
            return;
        }

        if ($fcfTrend['latest_yoy_pct'] <= -10 && $fcfTrend['prior_comparable_yoy_pct'] <= -10) {
            $risk[] = $this->signal('fcf_deteriorating', 'Free cash flow has deteriorated across consecutive comparable periods', $fcfTrend);
        } elseif ($fcfTrend['latest_yoy_pct'] >= 10 && $fcfTrend['prior_comparable_yoy_pct'] >= 10) {
            $positive[] = $this->signal('fcf_improving', 'Free cash flow has improved across consecutive comparable periods', $fcfTrend);
        }
    }

    /** @return array<string,mixed>|null */
    protected function growthTrend(Stock $stock, string $factKey, Carbon $asOf): ?array
    {
        $rows = FundamentalFact::query()->where('stock_id', $stock->id)->where('fact_key', $factKey)
            ->where('cadence', FundamentalDataService::CADENCE_QUARTERLY)
            ->whereDate('availability_date', '<=', $asOf->toDateString())
            ->orderByDesc('period_end')->orderByDesc('revision_number')->get()->unique('period_end')->values();
        if ($rows->count() < 5) return null;
        $growth = function ($current) use ($rows): ?float {
            $priorDate = $current->period_end?->copy()->subYear()->toDateString();
            $prior = $rows->first(fn (FundamentalFact $row) => $row->period_end?->toDateString() === $priorDate);
            if ($prior === null || $prior->value === null || (float) $prior->value === 0.0 || $current->value === null) return null;
            return (((float) $current->value - (float) $prior->value) / abs((float) $prior->value)) * 100;
        };
        $latest = $growth($rows[0]);
        $previous = $growth($rows[1]);
        if ($latest === null || $previous === null) return null;
        return [
            'latest_yoy_pct' => round($latest, 2),
            'prior_comparable_yoy_pct' => round($previous, 2),
            'delta_pp' => round($latest - $previous, 2),
            'period' => $rows[0]->period_end->toDateString(),
            'comparison_period' => $rows[1]->period_end->toDateString(),
            'basis' => 'quarterly_yoy_trend',
        ];
    }

    /** @return array{current:float,prior:float,current_period:string,prior_period:string}|null */
    protected function comparablePair(Stock $stock, string $factKey, Carbon $asOf): ?array
    {
        $rows = FundamentalFact::query()
            ->where('stock_id', $stock->id)
            ->where('fact_key', $factKey)
            ->where('cadence', FundamentalDataService::CADENCE_QUARTERLY)
            ->whereDate('availability_date', '<=', $asOf->toDateString())
            ->orderByDesc('period_end')->orderByDesc('revision_number')->get()
            ->unique('period_end')->values();
        $current = $rows->first();
        if ($current === null || $current->value === null) return null;
        $priorDate = $current->period_end?->copy()->subYear()->toDateString();
        $prior = $rows->first(fn (FundamentalFact $row) => $row->period_end?->toDateString() === $priorDate);
        if ($prior === null || $prior->value === null) return null;
        return ['current' => (float) $current->value, 'prior' => (float) $prior->value, 'current_period' => $current->period_end->toDateString(), 'prior_period' => $prior->period_end->toDateString()];
    }

    /** @return list<string> */
    protected function missingEvidence(Stock $stock, Carbon $asOf): array
    {
        $facts = $this->fundamentals->factMap($stock, FundamentalDataService::CADENCE_QUARTERLY, $asOf);
        $missing = [];
        foreach (['revenue' => 'revenue history', 'net_income' => 'earnings history', 'operating_cash_flow' => 'cash-flow history', 'debt' => 'debt history', 'shares_outstanding' => 'share-count history'] as $key => $label) {
            if (! isset($facts[$key])) $missing[] = $label;
        }
        return $missing;
    }

    protected function signal(string $key, string $headline, array $metricValues): array
    {
        return [
            'signal_key' => $key,
            'category' => $this->categoryFor($key),
            'direction' => str_contains($key, 'decreasing') || str_contains($key, 'strong') || str_contains($key, 'expanding') || str_contains($key, 'improving') ? 'positive' : (str_contains($key, 'stable') || str_contains($key, 'follow_up') || str_contains($key, 'ownership_') || str_contains($key, 'release') ? 'watch' : 'risk'),
            'title' => $headline,
            'headline' => $headline,
            'summary' => $headline,
            'metric_values' => $metricValues,
            'evidence' => $metricValues,
            'basis' => $metricValues['basis'] ?? 'deterministic',
            'period' => $metricValues['period'] ?? null,
            'severity' => str_contains($key, 'stable') ? 'informational' : (str_contains($key, 'follow_up') || str_contains($key, 'cwip') || str_contains($key, 'ownership_') ? 'watch' : 'material'),
            'confidence' => 'deterministic',
            'source' => 'deterministic',
            'provenance' => ['source' => 'StoX deterministic calculation'],
        ];
    }

    protected function categoryFor(string $key): string
    {
        foreach (['margin' => 'profitability', 'cash' => 'cash_flow', 'ocf' => 'cash_flow', 'fcf' => 'cash_flow', 'debt' => 'leverage', 'leverage' => 'leverage', 'dilution' => 'capital_structure', 'share_count' => 'capital_structure', 'ownership' => 'ownership', 'receivable' => 'working_capital', 'inventory' => 'working_capital', 'cwip' => 'capital_projects'] as $needle => $category) {
            if (str_contains($key, $needle)) return $category;
        }
        return 'fundamentals';
    }

    /** @param array<string,mixed>|null $sector */
    protected function evaluateSectorComparisons(?array $sector, array &$positive, array &$risk, array &$watch): void
    {
        foreach (($sector['peer_percentiles'] ?? []) as $row) {
            if (! is_array($row) || ! is_numeric($row['value'] ?? null) || ! is_numeric($row['peer_median'] ?? null)) continue;
            $percentile = isset($row['percentile']) && is_numeric($row['percentile']) ? (float) $row['percentile'] : null;
            if ($percentile === null || ($percentile > 0.25 && $percentile < 0.75)) continue;
            $key = (string) ($row['metric_key'] ?? 'metric');
            $evidence = [
                'subject_value' => (float) $row['value'],
                'comparison_value' => (float) $row['peer_median'],
                'comparison_entity' => (string) ($sector['sector'] ?? 'sector peers'),
                'comparison_basis' => 'active_sector_peer_median',
                'comparison_period' => 'latest_available_ttm',
                'delta' => round((float) $row['value'] - (float) $row['peer_median'], 4),
                'delta_unit' => str_contains($key, 'pe') || str_contains($key, 'debt') ? 'ratio' : 'percentage_points',
                'percentile' => $percentile,
                'basis' => 'sector_comparison',
            ];
            if ($key === 'roe_ttm' && $percentile >= 0.75) {
                $positive[] = $this->signal('sector_roe_above_peers', 'ROE is above the active sector peer distribution', $evidence);
            } elseif ($key === 'roe_ttm' && $percentile <= 0.25) {
                $risk[] = $this->signal('sector_roe_below_peers', 'ROE is below the active sector peer distribution', $evidence);
            } elseif ($key === 'debt_equity' && $percentile >= 0.75) {
                $watch[] = $this->signal('sector_leverage_above_peers', 'Debt / equity is above the active sector peer distribution', $evidence);
            } elseif ($key === 'pe_ratio' && $percentile >= 0.75) {
                $watch[] = $this->signal('sector_pe_above_peers', 'P/E is above the active sector peer distribution', $evidence);
            }
        }
    }

    /** @return list<string> */
    protected function qualityFactors(Stock $stock, Carbon $asOf): array
    {
        $factors = [];
        foreach (['revenue', 'net_income', 'operating_cash_flow'] as $metric) {
            $row = $this->fundamentals->metric($stock, $metric, 'ttm', $asOf);
            if (($row['freshness']['status'] ?? null) === 'stale') $factors[] = 'stale_'.$metric;
            if (($row['provenance']['source_label'] ?? null) === 'yahoo') $factors[] = 'fallback_provider_'.$metric;
            if (($row['provenance']['source_label'] ?? null) === 'Multiple sources') $factors[] = 'mixed_provider_'.$metric;
        }
        return array_values(array_unique($factors));
    }

    /**
     * Score evidence quality separately from the existence of a calculated
     * value. Stale or fallback facts remain factual, but they reduce the
     * confidence available to the interpretation layer.
     *
     * @param list<string> $missing
     * @return array{rating:string,score:float,quality_factors:list<string>,weighting:array<string,float>}
     */
    protected function sufficiencyAssessment(Stock $stock, Carbon $asOf, array $missing): array
    {
        $factors = $this->qualityFactors($stock, $asOf);
        $quarterlyPeriods = FundamentalFact::query()
            ->where('stock_id', $stock->id)
            ->where('cadence', FundamentalDataService::CADENCE_QUARTERLY)
            ->whereDate('availability_date', '<=', $asOf->toDateString())
            ->distinct('period_end')
            ->count('period_end');
        if ($quarterlyPeriods < 4) {
            $factors[] = 'sparse_quarterly_history';
        }
        $factors = array_values(array_unique($factors));

        $weighting = [];
        foreach ($factors as $factor) {
            $weighting[$factor] = match (true) {
                str_starts_with($factor, 'stale_') => 0.20,
                str_starts_with($factor, 'fallback_provider_') => 0.10,
                str_starts_with($factor, 'mixed_provider_') => 0.15,
                $factor === 'sparse_quarterly_history' => 0.20,
                default => 0.15,
            };
        }
        foreach ($missing as $item) {
            $weighting['missing:'.$item] = 0.15;
        }

        $score = round(max(0.0, min(1.0, 1.0 - array_sum($weighting))), 2);

        return [
            'rating' => $score >= 0.75 ? 'high' : ($score >= 0.45 ? 'medium' : 'low'),
            'score' => $score,
            'quality_factors' => $factors,
            'weighting' => $weighting,
        ];
    }

    /** @param list<array<string, mixed>> $watch */
    protected function followUpChecks(array $watch): array
    {
        $checks = [];
        foreach ($watch as $item) {
            $key = $item['signal_key'] ?? null;
            if ($key === 'cwip_expansion_ambiguous') {
                $checks[] = 'Review annual-report CWIP notes, capex disclosures, commissioning timelines, investor presentations and auditor remarks.';
            } elseif ($key === 'receivables_growth_vs_revenue') {
                $checks[] = 'Review receivables ageing, customer concentration, bad-debt provisions and related-party notes.';
            } elseif ($key === 'inventory_growth_vs_revenue') {
                $checks[] = 'Review inventory composition, obsolescence provisions and management commentary on channel inventory.';
            } elseif ($key === 'cash_quality_follow_up' || $key === 'earnings_cash_divergence') {
                $checks[] = 'Review annual-report cash-flow notes, working-capital commentary, investor presentations and earnings-call disclosures.';
            } elseif ($key === 'debt_increasing') {
                $checks[] = 'Review the debt maturity schedule, capex plan, refinancing commentary and finance-cost notes.';
            } elseif ($key === 'dilution_follow_up' || $key === 'share_count_dilution') {
                $checks[] = 'Review preferential, QIP, rights issue, ESOP, acquisition-funding and corporate-action disclosures.';
            } elseif (str_starts_with((string) $key, 'ownership_')) {
                $checks[] = 'Review the relevant shareholding, promoter transaction and pledge disclosure for the comparable period.';
            }
        }
        return array_values(array_unique($checks));
    }

    /**
     * @param  list<array<string, mixed>>  $positive
     * @param  list<array<string, mixed>>  $risk
     * @param  list<array<string, mixed>>  $watch
     */
    protected function buildSummary(array $positive, array $risk, array $watch): string
    {
        if ($positive === [] && $risk === [] && $watch === []) {
            return 'No material deterministic signals at current coverage.';
        }
        $parts = [];
        if ($risk !== []) {
            $parts[] = count($risk).' risk signal'.(count($risk) === 1 ? '' : 's');
        }
        if ($positive !== []) {
            $parts[] = count($positive).' positive signal'.(count($positive) === 1 ? '' : 's');
        }
        if ($watch !== []) {
            $parts[] = count($watch).' watch item'.(count($watch) === 1 ? '' : 's');
        }

        return 'Deterministic scan: '.implode(', ', $parts).'.';
    }
}
