import test from 'node:test';
import assert from 'node:assert/strict';
import { debounce } from '../../resources/js/src/utils/debounce.js';

test('debounce delays execution', async () => {
    let count = 0;
    const fn = debounce(() => {
        count += 1;
    }, 50);

    fn();
    fn();
    fn();

    assert.equal(count, 0);
    await new Promise((resolve) => setTimeout(resolve, 80));
    assert.equal(count, 1);
});

test('debounce cancel prevents pending call', async () => {
    let count = 0;
    const fn = debounce(() => {
        count += 1;
    }, 50);

    fn();
    fn.cancel();
    await new Promise((resolve) => setTimeout(resolve, 80));
    assert.equal(count, 0);
});
