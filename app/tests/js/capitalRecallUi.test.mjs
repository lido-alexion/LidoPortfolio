import test from 'node:test';
import assert from 'node:assert/strict';
import {
    TERMINOLOGY,
    actualExecutionAmount,
    bridgeStatusLabel,
    canSelectLenderForStatus,
    capitalAllocationStatusBadgeClass,
    capitalAllocationStatusLabel,
    capitalRequestIdFromRecommendation,
    capitalResolutionStateLabel,
    proceedsStatusLabel,
    recallKindLabel,
    recallStateBadgeClass,
    recallStateLabel,
    showsCapitalAllocationStatus,
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

test('capital allocation status labels for OPEN/INCREASE badges', () => {
    assert.equal(capitalAllocationStatusLabel('unfunded'), 'Capital required');
    assert.equal(capitalAllocationStatusLabel('UNFUNDED'), 'Capital required');
    assert.equal(capitalAllocationStatusLabel('awaiting_lender_selection'), 'Awaiting lender');
    assert.equal(capitalAllocationStatusLabel('partially_funded'), 'Partially funded');
    assert.equal(capitalAllocationStatusLabel('funded'), 'Funded');
    assert.equal(capitalAllocationStatusBadgeClass('unfunded'), 'text-bg-danger');
    assert.equal(capitalAllocationStatusBadgeClass('awaiting_lender_selection'), 'text-bg-warning');
    assert.equal(capitalAllocationStatusBadgeClass('funded'), 'text-bg-light border text-muted');
});

test('showsCapitalAllocationStatus only for open/increase/buy', () => {
    assert.equal(showsCapitalAllocationStatus('OPEN_POSITION'), true);
    assert.equal(showsCapitalAllocationStatus({ portfolio_action: 'INCREASE_POSITION' }), true);
    assert.equal(showsCapitalAllocationStatus('EXIT_POSITION'), false);
    assert.equal(showsCapitalAllocationStatus({ recommendation_type: 'WATCH' }), false);
});

test('capitalRequestIdFromRecommendation reads API or meta', () => {
    assert.equal(capitalRequestIdFromRecommendation({ capital_request_id: 42 }), 42);
    assert.equal(capitalRequestIdFromRecommendation({
        evidence: { capital_allocation: { capital_request_id: 7 } },
    }), 7);
    assert.equal(capitalRequestIdFromRecommendation({}), null);
    assert.equal(canSelectLenderForStatus('awaiting_lender_selection'), true);
    assert.equal(canSelectLenderForStatus('funded'), false);
});
