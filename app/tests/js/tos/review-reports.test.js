import { describe, expect, it } from 'vitest';
import {
    METRIC_LABELS,
    generateQueryParams,
    methodologyEntries,
    remainingMetricKeys,
    formatMetricValue,
} from '../../../resources/js/src/utils/reviewReports.js';
import { SAMPLE_REVIEW_REPORT } from './fixtures/tosApi.js';

describe('reviewReports helpers', () => {
    it('labels recommendation_accepted as Accepted (not executed)', () => {
        expect(METRIC_LABELS.recommendation_accepted).toBe('Accepted (not executed)');
    });

    it('maps remaining persisted keys to frozen human labels', () => {
        const keys = remainingMetricKeys(SAMPLE_REVIEW_REPORT);
        expect(keys).toEqual([
            'actionable_recommendation_count',
            'informational_published',
            'informational_recommendation_count',
            'recommendation_acceptance_rate_pct',
            'recommendation_count',
            'recommendation_deferred',
            'recommendation_pending_review',
            'recommendation_rejected',
            'sells_closed',
        ]);
        expect(METRIC_LABELS.sells_closed).toBe('Closed sells');
        expect(METRIC_LABELS.recommendation_count).toBe('Recommendations (period)');
        expect(METRIC_LABELS.actionable_recommendation_count).toBe('Actionable recommendations');
        expect(METRIC_LABELS.informational_recommendation_count).toBe('Insight recommendations');
        expect(METRIC_LABELS.recommendation_rejected).toBe('Rejected');
        expect(METRIC_LABELS.recommendation_deferred).toBe('Deferred');
        expect(METRIC_LABELS.recommendation_pending_review).toBe('Pending review');
        expect(METRIC_LABELS.informational_published).toBe('Insights published');
        expect(METRIC_LABELS.recommendation_acceptance_rate_pct).toBe('Acceptance rate');
    });

    it('preserves stored methodology strings', () => {
        expect(methodologyEntries(SAMPLE_REVIEW_REPORT)).toEqual([
            { key: 'win_rate', text: 'Share of sell transactions with realized_pl > 0 in period' },
            { key: 'profit_factor', text: 'Sum gains / sum abs losses on sells' },
            { key: 'expectancy', text: 'Net realized P/L / closed sells' },
            { key: 'acceptance_rate', text: '(Accepted + Executed) / decided recommendations in period' },
        ]);
    });

    it('omits date query params when both dates are empty', () => {
        expect(generateQueryParams('', '')).toEqual({});
        expect(generateQueryParams('  ', null)).toEqual({});
    });

    it('passes period_start and period_end as query params when dates are set', () => {
        expect(generateQueryParams('2026-01-01', '2026-03-31')).toEqual({
            period_start: '2026-01-01',
            period_end: '2026-03-31',
        });
    });

    it('formats XIRR as a percent the same way as the live dashboard (value * 100)', () => {
        const formatters = {
            fmtPct: (v) => `${Number(v).toFixed(2)}%`,
            fmtNum: (v) => Number(v).toFixed(2),
            formatMoney: (v) => String(v),
            formatCount: (v) => String(v),
        };
        expect(formatMetricValue('xirr', 0.1234, formatters)).toBe('12.34%');
        expect(formatMetricValue('win_rate_pct', 55.5, formatters)).toBe('55.50%');
    });
});
