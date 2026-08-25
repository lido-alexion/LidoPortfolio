import test from 'node:test';
import assert from 'node:assert/strict';
import {
    EXIT_ATTRIBUTION_LABELS,
    exitAttributionLabel,
} from '../../resources/js/src/utils/exitAttributionLabels.js';

test('maps all canonical §13.2 exit reasons to human labels', () => {
    assert.equal(exitAttributionLabel('strategy_exit'), 'Strategy exit');
    assert.equal(exitAttributionLabel('stop_loss'), 'Portfolio stop-loss');
    assert.equal(exitAttributionLabel('trailing_stop'), 'Portfolio trailing stop');
    assert.equal(exitAttributionLabel('horizon_expiry'), 'Strategy horizon');
    assert.deepEqual(Object.keys(EXIT_ATTRIBUTION_LABELS).sort(), [
        'horizon_expiry',
        'stop_loss',
        'strategy_exit',
        'trailing_stop',
    ]);
});

test('preserves unknown canonical strings and empty as dash', () => {
    assert.equal(exitAttributionLabel('custom_future'), 'custom_future');
    assert.equal(exitAttributionLabel(null), '—');
    assert.equal(exitAttributionLabel(''), '—');
});
