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

    /** @var array<string,string> */
    private const YFINANCE_FACT_MAP = [
        'Total Revenue' => 'revenue',
        'Operating Income' => 'operating_profit',
        'EBIT' => 'ebit',
        'EBITDA' => 'ebitda',
        'Net Income' => 'net_income',
        'Basic EPS' => 'eps',
        'Diluted EPS' => 'eps_diluted',
        'Total Assets' => 'total_assets',
        'Total Liabilities Net Minority Interest' => 'total_liabilities',
        'Total Liab' => 'total_liabilities',
        'Stockholders Equity' => 'equity',
        'Total Equity Gross Minority Interest' => 'equity',
        'Total Debt' => 'debt',
        'Long Term Debt' => 'long_term_debt',
        'Cash Cash Equivalents And Short Term Investments' => 'cash_and_equivalents',
        'Cash And Cash Equivalents' => 'cash_and_equivalents',
        'Operating Cash Flow' => 'operating_cash_flow',
        'Investing Cash Flow' => 'investing_cash_flow',
        'Financing Cash Flow' => 'financing_cash_flow',
        'Capital Expenditure' => 'capital_expenditure',
        'Repurchase Of Capital Stock' => 'shares_outstanding',
        'Ordinary Shares Number' => 'shares_outstanding',
        'Cash Dividends Paid' => 'dividends_paid',
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

    /**
     * Normalize the plain JSON contract emitted by scripts/yahoo_fundamentals.py.
     *
     * @return list<array<string,mixed>>
     */
    public function normalizeYfinance(array $payload, string $cadence): array
    {
        $rows = [];
        foreach (['income_statement', 'balance_sheet', 'cash_flow'] as $statementType) {
            foreach (($payload['statements'][$statementType] ?? []) as $statement) {
                if (! is_array($statement) || ! is_string($statement['period_end'] ?? null)) {
                    continue;
                }
                foreach (($statement['facts'] ?? []) as $sourceName => $value) {
                    $factKey = self::YFINANCE_FACT_MAP[$sourceName] ?? null;
                    if ($factKey === null || ! is_numeric($value)) {
                        continue;
                    }
                    $rows[] = [
                        'provider' => 'yahoo',
                        'statement_type' => $statementType,
                        'cadence' => $cadence,
                        'statement_basis' => 'consolidated',
                        'fact_key' => $factKey,
                        'period_start' => null,
                        'period_end' => $statement['period_end'],
                        'reported_period' => $statement['period_end'],
                        'value' => (float) $value,
                        'currency' => null,
                        'availability_date' => null,
                        'source_meta' => [
                            'provider_key' => (string) $sourceName,
                            'transport' => 'yfinance',
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
