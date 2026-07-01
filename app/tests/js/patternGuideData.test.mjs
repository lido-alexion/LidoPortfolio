import test from 'node:test';
import assert from 'node:assert/strict';
import { CHART_PATTERNS } from '../../resources/js/src/data/chartPatterns.js';
import { CANDLESTICK_PATTERNS } from '../../resources/js/src/data/candlestickPatterns.js';

function assertPatternShape(patterns, label) {
    assert.ok(patterns.length > 0, `${label} should not be empty`);
    const ids = new Set();
    for (const pattern of patterns) {
        assert.ok(pattern.id, `${label} pattern missing id`);
        assert.ok(!ids.has(pattern.id), `duplicate id ${pattern.id}`);
        ids.add(pattern.id);
        assert.ok(pattern.name);
        assert.ok(pattern.category);
        assert.ok(Array.isArray(pattern.characteristics) && pattern.characteristics.length > 0);
        assert.ok(pattern.meaning);
        assert.ok(Array.isArray(pattern.mathRules) && pattern.mathRules.length > 0);
    }
}

test('chart patterns dataset is well formed', () => {
    assertPatternShape(CHART_PATTERNS, 'chart');
    assert.ok(CHART_PATTERNS.some((p) => p.id === 'cup_and_handle'));
});

test('candlestick patterns dataset is well formed', () => {
    assertPatternShape(CANDLESTICK_PATTERNS, 'candlestick');
    assert.ok(CANDLESTICK_PATTERNS.some((p) => p.id === 'shooting_star'));
    assert.ok(CANDLESTICK_PATTERNS.some((p) => p.id === 'piercing_line'));
    assert.ok(CANDLESTICK_PATTERNS.some((p) => p.id === 'dark_cloud_cover'));
    assert.ok(CANDLESTICK_PATTERNS.some((p) => p.id === 'bullish_harami'));
});
