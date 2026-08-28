/**
 * V4-FEAT-009 — Review report display helpers.
 * Values come from persisted ReviewEngine metrics. Do not recompute formulas here.
 */

export const REVIEW_REPORTS_PAGE_SIZE = 20;

export const CARD_METRIC_KEYS = [
    'portfolio_value',
    'invested_value',
    'unrealized_profit',
    'realized_profit',
    'xirr',
    'win_rate_pct',
    'average_gain',
    'average_loss',
    'profit_factor',
    'expectancy',
    'recommendation_accepted',
    'recommendation_executed',
];

export const SNAPSHOT_METRIC_KEYS = [
    'portfolio_value',
    'invested_value',
    'unrealized_profit',
    'realized_profit',
    'xirr',
];

export const NAMED_CARD_METRIC_KEYS = [
    'win_rate_pct',
    'average_gain',
    'average_loss',
    'profit_factor',
    'expectancy',
    'recommendation_accepted',
    'recommendation_executed',
];

export const METRIC_LABELS = {
    portfolio_value: 'Portfolio value (report)',
    invested_value: 'Invested value (report)',
    unrealized_profit: 'Unrealized P/L (report)',
    realized_profit: 'Realized P/L (report)',
    xirr: 'XIRR (report)',
    win_rate_pct: 'Win rate',
    average_gain: 'Average gain',
    average_loss: 'Average loss',
    profit_factor: 'Profit factor',
    expectancy: 'Expectancy',
    recommendation_accepted: 'Accepted (not executed)',
    recommendation_executed: 'Executed',
    sells_closed: 'Closed sells',
    recommendation_count: 'Recommendations (period)',
    actionable_recommendation_count: 'Actionable recommendations',
    informational_recommendation_count: 'Insight recommendations',
    recommendation_rejected: 'Rejected',
    recommendation_deferred: 'Deferred',
    recommendation_pending_review: 'Pending review',
    informational_published: 'Insights published',
    recommendation_acceptance_rate_pct: 'Acceptance rate',
};

const MONEY_KEYS = new Set([
    'portfolio_value',
    'invested_value',
    'unrealized_profit',
    'realized_profit',
    'average_gain',
    'average_loss',
    'expectancy',
]);

const COUNT_KEYS = new Set([
    'recommendation_accepted',
    'recommendation_executed',
    'sells_closed',
    'recommendation_count',
    'actionable_recommendation_count',
    'informational_recommendation_count',
    'recommendation_rejected',
    'recommendation_deferred',
    'recommendation_pending_review',
    'informational_published',
]);

const ALREADY_PERCENT_KEYS = new Set([
    'win_rate_pct',
    'recommendation_acceptance_rate_pct',
]);

export function metricsByName(report) {
    const map = {};
    const rows = Array.isArray(report?.metrics) ? report.metrics : [];
    for (const row of rows) {
        if (row?.metric_name) {
            map[row.metric_name] = row.metric_value;
        }
    }
    return map;
}

export function metricLabel(key) {
    return METRIC_LABELS[key] || key;
}

export function remainingMetricKeys(report) {
    const map = metricsByName(report);
    const card = new Set(CARD_METRIC_KEYS);
    return Object.keys(map)
        .filter((key) => !card.has(key))
        .sort();
}

export function methodologyEntries(report) {
    const methodology = report?.summary_json?.methodology;
    if (!methodology || typeof methodology !== 'object' || Array.isArray(methodology)) {
        return [];
    }
    return Object.entries(methodology).map(([key, text]) => ({
        key,
        text: text == null ? '' : String(text),
    }));
}

export function reportPeriodLabel(report) {
    const start = isoDatePart(report?.period_start);
    const end = isoDatePart(report?.period_end);
    if (!start && !end) {
        return '—';
    }
    if (start && end) {
        return `${start} → ${end}`;
    }
    return start || end;
}

export function isoDatePart(value) {
    if (value == null || value === '') {
        return '';
    }
    const s = String(value);
    const match = s.match(/^(\d{4}-\d{2}-\d{2})/);
    return match ? match[1] : s;
}

export function generateQueryParams(fromDate, toDate) {
    const from = String(fromDate || '').trim();
    const to = String(toDate || '').trim();
    const params = {};
    if (from) {
        params.period_start = from;
    }
    if (to) {
        params.period_end = to;
    }
    return params;
}

export function tosListMetaToTablePagination(meta) {
    const page = Number(meta?.page) || 1;
    const pageSize = Number(meta?.pageSize) || REVIEW_REPORTS_PAGE_SIZE;
    const total = Number(meta?.total) || 0;
    const lastPage = Number(meta?.lastPage) || 1;
    const from = total === 0 ? 0 : ((page - 1) * pageSize) + 1;
    const to = total === 0 ? 0 : Math.min(page * pageSize, total);
    return {
        current_page: page,
        last_page: lastPage,
        from,
        to,
        total,
    };
}

export function formatMetricValue(key, raw, formatters) {
    if (raw == null || raw === '') {
        return '—';
    }
    const n = Number(raw);
    if (Number.isNaN(n)) {
        return String(raw);
    }
    if (key === 'xirr') {
        return formatters.fmtPct(n * 100);
    }
    if (ALREADY_PERCENT_KEYS.has(key)) {
        return formatters.fmtPct(n);
    }
    if (MONEY_KEYS.has(key)) {
        return formatters.formatMoney(n);
    }
    if (COUNT_KEYS.has(key)) {
        return formatters.formatCount(n);
    }
    return formatters.fmtNum(n);
}
