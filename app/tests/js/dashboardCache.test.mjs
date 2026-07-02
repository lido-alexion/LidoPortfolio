import test from 'node:test';
import assert from 'node:assert/strict';
import {
    buildDashboardCacheKey,
    clearAllDashboardCaches,
    clearDashboardCache,
    flattenPatternScanResults,
    readDashboardCache,
    stripDashboardPayloadForCache,
    writeDashboardCache,
} from '../../resources/js/src/utils/dashboardCache.js';

function mockLocalStorage() {
    const store = {};
    global.localStorage = {
        setItem(k, v) { store[k] = v; },
        getItem(k) { return store[k] ?? null; },
        removeItem(k) { delete store[k]; },
        key(i) { return Object.keys(store)[i] ?? null; },
        get length() { return Object.keys(store).length; },
    };
    return store;
}

test('buildDashboardCacheKey scopes by user and portfolio', () => {
    assert.equal(buildDashboardCacheKey(1, 2), 'portfolio_dashboard_cache_v1_1_2');
});

test('stripDashboardPayloadForCache removes unused NIFTY price rows', () => {
    const stripped = stripDashboardPayloadForCache({
        portfolio_value: 100,
        nifty_comparison: {
            benchmark: { symbol: 'NIFTY50' },
            prices: [{ close_price: 1 }],
        },
    });
    assert.equal(stripped.portfolio_value, 100);
    assert.deepEqual(stripped.nifty_comparison, { benchmark: { symbol: 'NIFTY50' } });
});

test('flattenPatternScanResults expands matches per stock', () => {
    const flat = flattenPatternScanResults({
        results: [{
            stock_id: 9,
            symbol: 'TCS',
            matches: [
                { id: 'hammer', name: 'Hammer', category: 'bullish', bar_date: '2026-07-01', variant: 'candle' },
            ],
        }],
    });
    assert.equal(flat.length, 1);
    assert.equal(flat[0].symbol, 'TCS');
    assert.equal(flat[0].pattern_id, 'hammer');
});

test('write and read dashboard cache roundtrip', () => {
    mockLocalStorage();
    const dashboard = { portfolio_value: 50000, alerts: [] };
    const patternRows = [{ stock_id: 1, symbol: 'INFY', pattern_id: 'hammer' }];
    writeDashboardCache(10, 20, { dashboard, patternRows });
    const cached = readDashboardCache(10, 20);
    assert.ok(cached);
    assert.equal(cached.dashboard.portfolio_value, 50000);
    assert.equal(cached.patternRows.length, 1);
    assert.ok(cached.cachedAt);
    assert.ok(cached.expiresAt);
});

test('expired dashboard cache is not returned', () => {
    const store = mockLocalStorage();
    const key = buildDashboardCacheKey(3, 4);
    store[key] = JSON.stringify({
        v: 1,
        userId: 3,
        profileId: 4,
        cachedAt: '2020-01-01T00:00:00.000Z',
        expiresAt: '2020-01-02T00:00:00.000Z',
        dashboard: { portfolio_value: 1 },
        patternRows: [],
    });
    assert.equal(readDashboardCache(3, 4), null);
});

test('clearAllDashboardCaches removes every dashboard cache key', () => {
    const store = mockLocalStorage();
    writeDashboardCache(1, 1, { dashboard: { portfolio_value: 1 }, patternRows: [] });
    writeDashboardCache(2, 2, { dashboard: { portfolio_value: 2 }, patternRows: [] });
    clearAllDashboardCaches();
    assert.equal(readDashboardCache(1, 1), null);
    assert.equal(readDashboardCache(2, 2), null);
    assert.equal(Object.keys(store).length, 0);
});

test('clearDashboardCache removes one portfolio entry', () => {
    mockLocalStorage();
    writeDashboardCache(5, 6, { dashboard: { portfolio_value: 1 }, patternRows: [] });
    writeDashboardCache(5, 7, { dashboard: { portfolio_value: 2 }, patternRows: [] });
    clearDashboardCache(5, 6);
    assert.equal(readDashboardCache(5, 6), null);
    assert.ok(readDashboardCache(5, 7));
});
