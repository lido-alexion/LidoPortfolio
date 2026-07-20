import test from 'node:test';
import assert from 'node:assert/strict';
import {
    buildExplorerComparePath,
    compareStrengthLabel,
    pickPrimaryBenchmark,
} from '../../resources/js/src/utils/explorerLinks.js';

test('buildExplorerComparePath encodes symbol and benchmark', () => {
    assert.equal(
        buildExplorerComparePath('reliance', 'nifty50'),
        '/explorer?symbol=RELIANCE&benchmark=NIFTY50',
    );
    assert.equal(buildExplorerComparePath('', ''), '/explorer');
});

test('compareStrengthLabel uses index display name', () => {
    assert.equal(compareStrengthLabel('Nifty 50'), 'Compare strength against Nifty 50');
});

test('pickPrimaryBenchmark prefers is_primary then NIFTY50', () => {
    const primary = pickPrimaryBenchmark([
        { symbol: 'SENSEX', name: 'SENSEX', is_primary: false },
        { symbol: 'NIFTY50', name: 'Nifty 50', is_primary: true },
    ]);
    assert.equal(primary.symbol, 'NIFTY50');
});
