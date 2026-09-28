const DEFINITIONS = {
    revenue: 'Trailing twelve-month revenue, summed from four comparable quarterly periods.',
    net_income: 'Trailing twelve-month net income, summed from four comparable quarterly periods.',
    free_cash_flow: 'Operating cash flow minus the absolute value of capital expenditure.',
    roe: 'Return on equity: trailing twelve-month net income divided by latest positive equity.',
    debt_equity: 'Latest total debt divided by latest positive equity.',
    net_debt: 'Latest total debt minus cash and cash equivalents.',
    pe: 'Price-to-earnings multiple using the evaluation-date price and positive trailing EPS.',
    pb: 'Price-to-book multiple using the evaluation-date price and the latest eligible book value.',
    revenue_growth_yoy: 'Quarterly revenue growth versus the same fiscal quarter one year earlier.',
    market_cap: 'Current evaluation-date price multiplied by eligible shares outstanding.',
    enterprise_value: 'Market capitalization plus debt minus cash and cash equivalents.',
    operating_margin: 'Trailing operating profit divided by trailing revenue.',
    net_margin: 'Trailing net income divided by trailing revenue.',
    fcf_yield: 'Trailing free cash flow divided by current market capitalization.',
    dividend_yield: 'Trailing dividends per share divided by the evaluation-date share price.',
    payout_ratio: 'Trailing dividends paid divided by positive trailing net income.',
    eps: 'Trailing earnings per share from the eligible fundamental facts.',
};

export function fundamentalDefinition(metricId, basis = null) {
    const definition = DEFINITIONS[metricId] || 'Canonical fundamental value or StoX-derived metric.';
    return basis && !definition.toLowerCase().includes(basis.toLowerCase())
        ? `${definition} Basis: ${basis}.`
        : definition;
}
