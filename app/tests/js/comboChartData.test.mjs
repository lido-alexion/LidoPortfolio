import test from 'node:test';
import assert from 'node:assert/strict';
import { COMBO_CHART_PRESETS, availableComboPresets, comboPresetAvailability, cycleComboPreset, resolveComboDefault } from '../../resources/js/src/components/charts/comboChartPresets.js';
import { alignComboChartData, comboTooltipEntries, rangeAndSamplingRows } from '../../resources/js/src/components/charts/comboChartData.js';

test('catalogue selection skips unavailable presets and wraps in both directions', () => {
    const presets = availableComboPresets({ 'price-volume': { available: false } });
    assert.equal(cycleComboPreset(presets, 'price-net-profit', 1).id, 'price-pe');
    assert.equal(cycleComboPreset(presets, 'price-pe', -1).id, 'price-net-profit');
    assert.deepEqual(presets.map((preset) => preset.id), COMBO_CHART_PRESETS.map((preset) => preset.id));
});

test('explicit default wins when available, otherwise fallback then first available applies', () => {
    assert.equal(resolveComboDefault('price-pe'), 'price-pe');
    assert.equal(resolveComboDefault('price-pe', { 'price-pe': { available: false } }), 'price-volume');
    assert.equal(resolveComboDefault(null, { 'price-volume': { available: false }, 'price-pe': { available: false }, 'price-pb': { available: true }, 'price-ps': { available: false }, 'price-eps': { available: false }, 'price-revenue': { available: false }, 'price-net-profit': { available: false } }), 'price-pb');
});

test('availability requires sufficient stock data and carries a per-stock explanation', () => {
    const availability = comboPresetAvailability({ prices: [{ price_date: '2025-01-01', close_price: 10, volume: 100 }, { price_date: '2025-02-01', close_price: 11, volume: 120 }], series: { pe: { points: [{ value: 1 }, { value: 2 }] } } });
    assert.equal(availability['price-volume'].available, true);
    assert.match(comboPresetAvailability({ prices: [] })['price-volume'].reason, /Insufficient historical/);
    assert.equal(availability['price-pe'].available, false);
    assert.match(availability['price-pe'].reason, /need 4 periods/);
    const temporary = comboPresetAvailability({ prices: [{ price_date: '2025-01-01', close_price: 10, volume: 100 }, { price_date: '2025-02-01', close_price: 11, volume: 120 }], series: { pe: { unavailableReason: 'history request failed' } } });
    assert.equal(temporary['price-pe'].available, false);
    assert.match(temporary['price-pe'].reason, /Temporarily unavailable/);
});

test('cadence alignment leaves missing fundamentals empty and range sampling retains actual observations', () => {
    const rows = alignComboChartData({
        prices: [{ price_date: '2025-01-02', close_price: 10, volume: 100 }, { price_date: '2025-02-03', close_price: 12, volume: 120 }],
        fundamentals: { revenue: { points: [{ as_of: '2025-01-02', value: 50 }, { as_of: '2025-02-01', value: 60 }] } },
    });
    assert.equal(rows.find((row) => row.date === '2025-02-03').revenue, null);
    assert.equal(rangeAndSamplingRows(rows, '1m', '1d').length, 2);
    assert.equal(rangeAndSamplingRows(rows, 'all', '1m').length, 3);
    const quarterly = rangeAndSamplingRows(rows, 'all', '1q');
    assert.equal(quarterly.length, 3);
    assert.equal(quarterly.find((row) => row.date === '2025-02-01').revenue, 60);
    assert.equal(quarterly.find((row) => row.date === '2025-02-03').revenue, null);
});

test('synchronized tooltip entries keep date-aligned visible values and omit hidden or missing series', () => {
    const preset = COMBO_CHART_PRESETS.find((item) => item.id === 'price-pe');
    const entries = comboTooltipEntries([
        { dataKey: 'price', value: 102 },
        { dataKey: 'pe', value: 17.4 },
        { dataKey: 'pb', value: 2 },
    ], preset, { pe: false });
    assert.deepEqual(entries.map(({ key, entry }) => [key, entry.value]), [['price', 102]]);
    assert.deepEqual(comboTooltipEntries([{ dataKey: 'price', value: 102 }, { dataKey: 'pe', value: null }], preset).map(({ key }) => key), ['price']);
});
