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
        $this->evaluateEarningsCashSignals($stock, $asOf, $risk, $watch);
        $this->evaluateLeverageSignals($stock, $asOf, $positive, $risk, $watch);
        $this->evaluateDilutionSignals($stock, $asOf, $risk, $watch);
        $this->evaluateOwnershipSignals($stock, $asOf, $watch);

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
        $rating = count($missing) >= 4 ? 'low' : (($positive !== [] || $risk !== [] || $watch !== []) ? 'medium' : 'high');

        return [
            'summary' => $this->buildSummary($positive, $risk, $watch),
            'positive_signals' => $positive,
            'risk_signals' => $risk,
            'watch_items' => $watch,
            'sector_context' => $sector,
            'follow_up_checks' => $this->followUpChecks(array_merge($risk, $watch)),
            'data_sufficiency' => [
                'rating' => $rating,
                'missing_information' => $missing,
                'quality_factors' => $this->qualityFactors($stock, $asOf),
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
    }

    protected function evaluateLeverageSignals(Stock $stock, Carbon $asOf, array &$positive, array &$risk, array &$watch): void
    {
        $debt = $this->fundamentals->growthMetric($stock, 'debt', FundamentalDataService::CADENCE_QUARTERLY, $asOf);
        if ($debt['value'] === null) return;
        $evidence = ['debt_yoy_pct' => (float) $debt['value'], 'basis' => 'quarterly_yoy'];
        if ((float) $debt['value'] >= 15) {
            $risk[] = $this->signal('debt_increasing', 'Debt increased materially year over year', $evidence);
        } elseif ((float) $debt['value'] <= -15) {
            $positive[] = $this->signal('debt_decreasing', 'Debt decreased materially year over year', $evidence);
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
            'direction' => str_contains($key, 'decreasing') || str_contains($key, 'strong') || str_contains($key, 'expanding') ? 'positive' : (str_contains($key, 'stable') || str_contains($key, 'follow_up') || str_contains($key, 'ownership_') ? 'watch' : 'risk'),
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
