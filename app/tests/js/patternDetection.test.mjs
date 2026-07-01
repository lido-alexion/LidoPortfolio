import test from 'node:test';
import assert from 'node:assert/strict';
import { scanOhlcv, isActionableCategory } from '../../resources/js/src/utils/patternDetection/index.js';

function bar(date, open, high, low, close) {
    return { price_date: date, open_price: open, high_price: high, low_price: low, close_price: close };
}

function downtrendBars(count, startClose = 100) {
    const rows = [];
    for (let i = 0; i < count; i += 1) {
        const close = startClose - i * 2;
        rows.push(bar(`2024-01-${String(i + 1).padStart(2, '0')}`, close + 1, close + 2, close - 2, close));
    }
    return rows;
}

test('detects hammer after downtrend', () => {
    const rows = downtrendBars(8, 120);
    rows.push(bar('2024-01-09', 102, 103, 94, 103));
    const matches = scanOhlcv(rows, { actionableOnly: false });
    assert.ok(matches.some((m) => m.id === 'hammer'));
});

test('detects bullish engulfing', () => {
    const rows = [
        bar('2024-01-01', 108, 109, 100, 101),
        bar('2024-01-02', 100, 112, 99, 111),
    ];
    const matches = scanOhlcv(rows, { actionableOnly: false });
    assert.ok(matches.some((m) => m.id === 'bullish_engulfing'));
});

test('detects piercing line after downtrend', () => {
    const rows = downtrendBars(6, 110);
    rows.push(bar('2024-01-07', 100, 101, 95, 96));
    rows.push(bar('2024-01-08', 95, 102, 94, 99));
    const matches = scanOhlcv(rows, { actionableOnly: false });
    assert.ok(matches.some((m) => m.id === 'piercing_line'));
});

test('actionable filter excludes doji', () => {
    const rows = [bar('2024-01-01', 100, 102, 98, 100)];
    const all = scanOhlcv(rows, { actionableOnly: false });
    const actionable = scanOhlcv(rows, { actionableOnly: true });
    assert.ok(all.some((m) => m.id === 'doji'));
    assert.ok(!actionable.some((m) => m.id === 'doji'));
});

test('isActionableCategory excludes neutral', () => {
    assert.equal(isActionableCategory('neutral'), false);
    assert.equal(isActionableCategory('bullish'), true);
});
