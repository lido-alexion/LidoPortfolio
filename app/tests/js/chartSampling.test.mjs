import test from 'node:test';
import assert from 'node:assert/strict';
import { DENSE_DAILY_CHART_POINT_THRESHOLD, resolveChartSampling } from '../../resources/js/src/components/charts/chartSampling.js';

test('daily cadence stays selected for ordinary histories and components without adaptive sampling', () => {
    assert.equal(resolveChartSampling({ rowCount: DENSE_DAILY_CHART_POINT_THRESHOLD, defaultSampling: '1d', adaptive: true }), '1d');
    assert.equal(resolveChartSampling({ rowCount: DENSE_DAILY_CHART_POINT_THRESHOLD + 1, defaultSampling: '1d' }), '1d');
});

test('long histories open on deterministic monthly buckets until the user chooses a cadence', () => {
    const denseHistory = DENSE_DAILY_CHART_POINT_THRESHOLD + 1;
    assert.equal(resolveChartSampling({ rowCount: denseHistory, defaultSampling: '1d', adaptive: true }), '1m');
    assert.equal(resolveChartSampling({ rowCount: denseHistory, userSelection: '1d', defaultSampling: '1d', adaptive: true }), '1d');
    assert.equal(resolveChartSampling({ rowCount: denseHistory, userSelection: '1q', defaultSampling: '1d', adaptive: true }), '1q');
});
