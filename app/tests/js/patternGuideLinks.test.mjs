import test from 'node:test';
import assert from 'node:assert/strict';
import {
    normalizePatternHash,
    patternGuideLink,
    patternGuideSectionForId,
} from '../../resources/js/src/utils/patternGuideLinks.js';

test('pattern guide deep links', () => {
    assert.equal(normalizePatternHash('#hammer'), 'hammer');
    assert.equal(patternGuideLink('hammer'), '/patterns#hammer');
    assert.equal(patternGuideSectionForId('hammer'), 'candle');
    assert.equal(patternGuideSectionForId('double_top'), 'chart');
    assert.equal(patternGuideSectionForId('unknown'), null);
});
