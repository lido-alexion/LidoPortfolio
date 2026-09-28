<?php

/**
 * Investor statement table ordering (V8 FEAT-054 §20).
 *
 * @var list<string> $basic
 * @var list<string> $statement_order
 */
return [
    'basic_fact_keys' => [
        'revenue',
        'net_income',
        'operating_profit',
        'ebit',
        'ebitda',
        'eps',
        'eps_diluted',
        'operating_cash_flow',
        'capital_expenditure',
        'investing_cash_flow',
        'financing_cash_flow',
        'equity',
        'debt',
        'long_term_debt',
        'cash_and_equivalents',
        'total_assets',
        'total_liabilities',
    ],
    'statement_fact_order' => [
        'revenue',
        'operating_profit',
        'ebit',
        'ebitda',
        'net_income',
        'eps',
        'eps_diluted',
        'operating_cash_flow',
        'capital_expenditure',
        'investing_cash_flow',
        'financing_cash_flow',
        'dividends_paid',
        'total_assets',
        'total_liabilities',
        'equity',
        'debt',
        'long_term_debt',
        'cash_and_equivalents',
        'shares_outstanding',
        'interest_income',
        'interest_expense',
        'net_interest_income',
        'gross_npa',
        'gross_npa_ratio',
        'net_npa',
        'net_npa_ratio',
        'capital_adequacy_ratio',
        'provisions',
        'net_interest_margin',
    ],
];
