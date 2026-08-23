import test from 'node:test';
import assert from 'node:assert/strict';
import {
    TERMINOLOGY,
    actualExecutionAmount,
    bridgeStatusLabel,
    capitalResolutionStateLabel,
    proceedsStatusLabel,
    recallKindLabel,
    recallStateBadgeClass,
    recallStateLabel,
} from '../../resources/js/src/utils/capitalRecallUi.js';

test('recall pending_held uses funds-being-arranged label', () => {
    assert.equal(recallStateLabel('pending_held'), 'Pending — funds being arranged');
    assert.equal(recallStateBadgeClass('pending_held'), 'text-bg-warning');
});

test('recall lifecycle labels cover all v0.28 states', () => {
    for (const state of [
        'requested',
        'immediate_settlement',
        'pending_held',
        'liquidation',
        'settlement',
        'completed',
    ]) {
        assert.ok(recallStateLabel(state));
        assert.notEqual(recallStateLabel(state), state || '—');
    }
});

test('proceeds distinguish sale executed from available', () => {
    assert.match(proceedsStatusLabel('pending'), /Sale executed/);
    assert.match(proceedsStatusLabel('pending'), /pending/i);
    assert.equal(proceedsStatusLabel('available'), 'Proceeds available');
});

test('terminology avoids Soft Loan and Return on Stock Sale', () => {
    assert.equal(TERMINOLOGY.bridgeLoan, 'Recall Bridge Loan');
    assert.equal(TERMINOLOGY.proceeds, 'Proceeds from Stock Sale');
    assert.doesNotMatch(TERMINOLOGY.bridgeLoan, /soft/i);
    assert.doesNotMatch(TERMINOLOGY.proceeds, /return on/i);
});

test('actual execution amount prefers actual_execution_amount', () => {
    assert.equal(actualExecutionAmount({
        actual_execution_amount: 19000,
        total_immediately_available: 20000,
        requested_investment_amount: 20000,
    }), 19000);
    assert.equal(actualExecutionAmount({
        total_immediately_available: 15000,
    }), 15000);
    assert.equal(actualExecutionAmount(null), null);
});

test('kind and bridge labels', () => {
    assert.equal(recallKindLabel('full'), 'Full Recall');
    assert.equal(recallKindLabel('partial'), 'Partial Recall');
    assert.equal(bridgeStatusLabel('partially_returned'), 'Partially repaid');
    assert.equal(capitalResolutionStateLabel('closed_at_actual_with_shortfall'), 'Closed at actual (shortfall remains)');
});
