<?php

namespace App\Services\Fundamentals;

use App\Models\Stock;
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

        $rating = $positive !== [] || $risk !== [] ? 'medium' : (
            $watch !== [] ? 'low' : 'high'
        );

        $sector = $this->sectorContext->contextFor($stock, $asOf, $price);

        return [
            'summary' => $this->buildSummary($positive, $risk, $watch),
            'positive_signals' => $positive,
            'risk_signals' => $risk,
            'watch_items' => $watch,
            'sector_context' => $sector,
            'follow_up_checks' => $this->followUpChecks(array_merge($risk, $watch)),
            'data_sufficiency' => [
                'rating' => $rating,
                'missing_information' => [],
            ],
            'ai' => [
                'status' => 'not_requested',
            ],
        ];
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

    protected function signal(string $key, string $headline, array $metricValues): array
    {
        return [
            'signal_key' => $key,
            'headline' => $headline,
            'metric_values' => $metricValues,
            'source' => 'deterministic',
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
