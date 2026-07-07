import test from 'node:test';
import assert from 'node:assert/strict';
import { formatSchedulerTimestamp } from '../../resources/js/src/utils/schedulerTimestamp.js';

test('formatSchedulerTimestamp returns dash for empty values', () => {
    assert.equal(formatSchedulerTimestamp(null), '—');
    assert.equal(formatSchedulerTimestamp(''), '—');
});

test('formatSchedulerTimestamp formats UTC instant in Asia/Kolkata with timezone label', () => {
    const formatted = formatSchedulerTimestamp('2026-07-06T23:30:04.000Z', 'Asia/Kolkata');
    assert.match(formatted, /07 Jul 2026, 05:00:04/);
    assert.match(formatted, /\(Asia\/Kolkata\)/);
});

test('formatSchedulerTimestamp formats UTC instant in UTC with timezone label', () => {
    const formatted = formatSchedulerTimestamp('2026-07-06T23:30:04.000Z', 'UTC');
    assert.match(formatted, /06 Jul 2026, 23:30:04/);
    assert.match(formatted, /\(UTC\)/);
});
