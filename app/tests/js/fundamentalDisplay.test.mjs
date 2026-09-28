import test from 'node:test';
import assert from 'node:assert/strict';
import { formatCoveragePeriods, formatFundamentalMetric } from '../../resources/js/src/utils/fundamentalDisplay.js';

test('fundamental display uses investor-friendly units without fabricating missing values', () => {
    assert.equal(formatFundamentalMetric(12340000000, 'revenue'), '₹1,234 Cr');
    assert.equal(formatFundamentalMetric(18.64, 'roe'), '18.6%');
    assert.equal(formatFundamentalMetric(24.2, 'pe'), '24.2x');
    assert.equal(formatFundamentalMetric(null, 'revenue'), '—');
});

test('fundamental coverage exposes cadence-specific period counts', () => {
    assert.equal(formatCoveragePeriods({ quarterly: { period_count: 24 } }, 'quarterly'), '24 periods');
    assert.equal(formatCoveragePeriods({}, 'annual'), '—');
});
