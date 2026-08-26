import test from 'node:test';
import assert from 'node:assert/strict';
import { formatSchedulerTimestamp } from '../../resources/js/src/utils/schedulerTimestamp.js';

test('formatSchedulerTimestamp returns dash for empty values', () => {
    assert.equal(formatSchedulerTimestamp(null), '—');
    assert.equal(formatSchedulerTimestamp(''), '—');
});

test('formatSchedulerTimestamp formats UTC instant in Asia/Kolkata with timezone label', () => {
    const formatted = formatSchedulerTimestamp('2026-07-06T23:30:04.000Z', 'Asia/Kolkata');
    // 23:30:04Z → 05:00:04 IST next calendar day
    assert.match(formatted, /07 Jul 2026, 05:00:04/);
    assert.match(formatted, /\(Asia\/Kolkata\)/);
    // Must not drift to locale 12-hour / ICU short-name-only forms
    assert.doesNotMatch(formatted, /AM|PM/i);
});

test('formatSchedulerTimestamp formats UTC instant in UTC with timezone label', () => {
    const formatted = formatSchedulerTimestamp('2026-07-06T23:30:04.000Z', 'UTC');
    assert.match(formatted, /06 Jul 2026, 23:30:04/);
    assert.match(formatted, /\(UTC\)/);
    assert.doesNotMatch(formatted, /AM|PM/i);
});

test('formatSchedulerTimestamp is deterministic for a fixed instant', () => {
    const a = formatSchedulerTimestamp('2026-07-06T23:30:04.000Z', 'Asia/Kolkata');
    const b = formatSchedulerTimestamp('2026-07-06T23:30:04.000Z', 'Asia/Kolkata');
    assert.equal(a, b);
    assert.match(a, /^07 Jul 2026, 05:00:04 .+\(Asia\/Kolkata\)$/);
});
