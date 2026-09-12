<?php

namespace App\Services\Fundamentals;

use Carbon\Carbon;

class YahooFundamentalNormalizer
{
    /** @var array<string,string> */
    private const FACT_MAP = [
        'totalRevenue' => 'revenue',
        'operatingIncome' => 'operating_profit',
        'ebit' => 'ebit',
        'ebitda' => 'ebitda',
        'netIncome' => 'net_income',
        'basicEPS' => 'eps',
        'dilutedEPS' => 'eps_diluted',
        'totalAssets' => 'total_assets',
        'totalLiab' => 'total_liabilities',
        'totalStockholderEquity' => 'equity',
        'shortLongTermDebtTotal' => 'debt',
        'longTermDebt' => 'long_term_debt',
        'cash' => 'cash_and_equivalents',
        'totalCashFromOperatingActivities' => 'operating_cash_flow',
        'totalCashflowsFromInvestingActivities' => 'investing_cash_flow',
        'totalCashFromFinancingActivities' => 'financing_cash_flow',
        'capitalExpenditures' => 'capital_expenditure',
        'commonStock' => 'shares_outstanding',
        'dividendsPaid' => 'dividends_paid',
    ];

    /**
     * @return list<array<string,mixed>>
     */
    public function normalize(array $payload, string $cadence): array
    {
        $containers = [
            'income_statement' => $cadence === FundamentalDataService::CADENCE_ANNUAL
                ? 'incomeStatementHistory.incomeStatementHistory'
                : 'incomeStatementHistoryQuarterly.incomeStatementHistory',
            'balance_sheet' => $cadence === FundamentalDataService::CADENCE_ANNUAL
                ? 'balanceSheetHistory.balanceSheetStatements'
                : 'balanceSheetHistoryQuarterly.balanceSheetStatements',
            'cash_flow' => $cadence === FundamentalDataService::CADENCE_ANNUAL
                ? 'cashflowStatementHistory.cashflowStatements'
                : 'cashflowStatementHistoryQuarterly.cashflowStatements',
        ];

        $rows = [];
        foreach ($containers as $statementType => $path) {
            foreach ($this->getPath($payload, $path) as $statement) {
                if (! is_array($statement)) {
                    continue;
                }
                $periodEnd = $this->dateFromYahoo($statement['endDate'] ?? null);
                if ($periodEnd === null) {
                    continue;
                }
                foreach (self::FACT_MAP as $providerKey => $factKey) {
                    if (! array_key_exists($providerKey, $statement)) {
                        continue;
                    }
                    $value = $this->rawValue($statement[$providerKey]);
                    if ($value === null) {
                        continue;
                    }
                    $rows[] = [
                        'provider' => 'yahoo',
                        'statement_type' => $statementType,
                        'cadence' => $cadence,
                        'statement_basis' => 'consolidated',
                        'fact_key' => $factKey,
                        'period_start' => null,
                        'period_end' => $periodEnd,
                        'reported_period' => $periodEnd,
                        'value' => $value,
                        'currency' => null,
                        'availability_date' => null,
                        'source_meta' => [
                            'provider_key' => $providerKey,
                            'availability_source' => 'first_fetch_fallback',
                        ],
                    ];
                }
            }
        }

        return $rows;
    }

    private function getPath(array $payload, string $path): array
    {
        $node = $payload;
        foreach (explode('.', $path) as $part) {
            if (! is_array($node) || ! array_key_exists($part, $node)) {
                return [];
            }
            $node = $node[$part];
        }

        return is_array($node) ? $node : [];
    }

    private function rawValue(mixed $node): ?float
    {
        $value = is_array($node) ? ($node['raw'] ?? null) : $node;
        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function dateFromYahoo(mixed $node): ?string
    {
        $raw = is_array($node) ? ($node['raw'] ?? null) : $node;
        if (! is_numeric($raw)) {
            return null;
        }

        return Carbon::createFromTimestamp((int) $raw, 'UTC')->toDateString();
    }
}
