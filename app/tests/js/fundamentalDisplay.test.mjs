import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { formatCoveragePeriods, formatFundamentalMetric } from '../../resources/js/src/utils/fundamentalDisplay.js';
import { fundamentalDefinition } from '../../resources/js/src/utils/fundamentalDefinitions.js';
import { advancedFundamentalsPreferenceKey } from '../../resources/js/src/utils/fundamentalPreference.js';

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

test('fundamental definitions explain basis without making recommendations', () => {
    assert.match(fundamentalDefinition('pe', 'ttm'), /evaluation-date price/);
    assert.match(fundamentalDefinition('revenue_growth_yoy', 'quarterly_yoy'), /same fiscal quarter/);
    assert.doesNotMatch(fundamentalDefinition('roe'), /buy|sell|cheap|expensive/i);
});

test('advanced preference is namespaced to the authenticated user', () => {
    const panel = fs.readFileSync(
        path.resolve(process.cwd(), 'resources/js/src/components/WatchlistResearchPanel.jsx'),
        'utf8',
    );
    assert.match(panel, /advancedFundamentalsPreferenceKey\(user\?\.id\)/);
    assert.notEqual(advancedFundamentalsPreferenceKey(1), advancedFundamentalsPreferenceKey(2));
    assert.equal(advancedFundamentalsPreferenceKey(null), 'lido.fundamentals.advancedExpanded.user.anonymous');
});
