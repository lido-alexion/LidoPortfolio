const PERCENT_METRICS = new Set([
    'roe',
    'revenue_growth_yoy',
    'gross_npa_ratio',
    'net_npa_ratio',
    'capital_adequacy_ratio',
    'net_interest_margin',
]);

const MULTIPLE_METRICS = new Set(['pe', 'pb', 'debt_equity']);

const INR_METRICS = new Set(['revenue', 'net_income', 'free_cash_flow', 'net_debt', 'market_cap', 'enterprise_value']);

export function formatFundamentalMetric(value, metricId) {
    if (value == null || value === '') return '—';
    const number = Number(value);
    if (!Number.isFinite(number)) return String(value);
    if (PERCENT_METRICS.has(metricId)) return `${number.toFixed(1)}%`;
    if (MULTIPLE_METRICS.has(metricId)) return `${number.toFixed(1)}x`;
    if (INR_METRICS.has(metricId)) return `₹${(number / 10000000).toLocaleString(undefined, { maximumFractionDigits: 1 })} Cr`;
    return number.toLocaleString(undefined, { maximumFractionDigits: 2 });
}

export function formatCoveragePeriods(coverage, cadence) {
    const count = coverage?.[cadence]?.period_count;
    return Number.isFinite(Number(count)) ? `${Number(count)} periods` : '—';
}
