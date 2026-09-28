import test from 'node:test';
import assert from 'node:assert/strict';
import { INVESTOR_TOUR_STEPS, nextTourStep } from '../../resources/js/src/guidedTour/investorTourSteps.js';

test('guided tour skips a missing step to the next configured target', () => {
    assert.equal(nextTourStep(INVESTOR_TOUR_STEPS[0].id).id, INVESTOR_TOUR_STEPS[1].id);
    assert.equal(nextTourStep(INVESTOR_TOUR_STEPS.at(-1).id), null);
});

test('guided tour configuration remains ordered and has stable targets', () => {
    assert.ok(INVESTOR_TOUR_STEPS.length >= 2);
    for (const step of INVESTOR_TOUR_STEPS) {
        assert.match(step.id, /^[a-z-]+$/);
        assert.match(step.target, /^(#|\[)/);
    }
});
