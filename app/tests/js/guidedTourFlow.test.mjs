import test from 'node:test';
import assert from 'node:assert/strict';
import { INVESTOR_TOUR_STEPS, nextTourStep } from '../../resources/js/src/guidedTour/investorTourSteps.js';
import { hasTranslation, t } from '../../resources/js/src/i18n/index.js';

test('guided tour skips a missing step to the next configured target', () => {
    assert.equal(nextTourStep(INVESTOR_TOUR_STEPS[0].id).id, INVESTOR_TOUR_STEPS[1].id);
    assert.equal(nextTourStep(INVESTOR_TOUR_STEPS.at(-1).id), null);
});

test('guided tour configuration remains ordered and has stable targets', () => {
    assert.ok(INVESTOR_TOUR_STEPS.length >= 2);
    for (const step of INVESTOR_TOUR_STEPS) {
        assert.match(step.id, /^[a-z-]+$/);
        assert.match(step.target, /^(#|\[)/);
        assert.equal(hasTranslation(step.titleKey), true);
        assert.equal(hasTranslation(step.bodyKey), true);
    }
});

test('guided tour translations use keyed copy with safe locale fallback', () => {
    assert.equal(t('guidedTour.step.progress', { current: 2, total: 8 }, 'en'), 'Step 2 of 8');
    assert.equal(t('guidedTour.action.begin', {}, 'fr'), 'Begin tour');
    assert.equal(t('guidedTour.missing', {}, 'en'), 'guidedTour.missing');
});
