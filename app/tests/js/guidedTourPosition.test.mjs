import test from 'node:test';
import assert from 'node:assert/strict';
import { guidedTourTooltipStyle } from '../../resources/js/src/guidedTour/guidedTourPosition.js';

const viewport = { width: 390, height: 844 };

test('guided tour panel flips above a low target and stays inside a narrow viewport', () => {
    const style = guidedTourTooltipStyle(
        { top: 760, left: 150, width: 80, height: 40 },
        'bottom',
        viewport,
    );

    assert.equal(style.transform, 'none');
    assert.equal(style.left, 16);
    assert.equal(style['--guided-tour-placement'], 'top');
    assert.match(style['--guided-tour-caret-position'], /px$/);
    assert.ok(style.top >= 16);
    assert.ok(style.top + 280 <= viewport.height - 16);
});

test('guided tour panel flips horizontally when the preferred side has no room', () => {
    const style = guidedTourTooltipStyle(
        { top: 260, left: 8, width: 24, height: 24 },
        'left',
        viewport,
    );

    assert.equal(style.transform, 'none');
    assert.equal(style.left, 16);
    assert.equal(style['--guided-tour-placement'], 'right');
    assert.match(style['--guided-tour-caret-position'], /px$/);
    assert.ok(style.top >= 16);
    assert.ok(style.top + 280 <= viewport.height - 16);
});

test('guided tour panel remains centered when the target is temporarily unavailable', () => {
    const style = guidedTourTooltipStyle(null, 'bottom', viewport);

    assert.equal(style.top, '50%');
    assert.equal(style.left, '50%');
    assert.equal(style.transform, 'translate(-50%, -50%)');
    assert.equal(style['--guided-tour-caret-position'], '50%');
});
