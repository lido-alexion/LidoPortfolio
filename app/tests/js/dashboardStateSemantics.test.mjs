import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { normalizeDashboardChartNumber } from '../../resources/js/src/utils/dashboardState.js';

const dashboardSource = readFileSync(
    new URL('../../resources/js/src/pages/DashboardPage.jsx', import.meta.url),
    'utf8',
);
const calendarSource = readFileSync(
    new URL('../../resources/js/src/components/calendar/CalendarDayEventsDialog.jsx', import.meta.url),
    'utf8',
);
const dataTableSource = readFileSync(
    new URL('../../resources/js/src/components/DataTable.jsx', import.meta.url),
    'utf8',
);

test('dashboard chart normalization preserves zero and represents unavailable values as null', () => {
    assert.equal(normalizeDashboardChartNumber(0), 0);
    assert.equal(normalizeDashboardChartNumber('0'), 0);
    assert.equal(normalizeDashboardChartNumber('123.5'), 123.5);
    assert.equal(normalizeDashboardChartNumber(null), null);
    assert.equal(normalizeDashboardChartNumber(undefined), null);
    assert.equal(normalizeDashboardChartNumber(''), null);
    assert.equal(normalizeDashboardChartNumber('not-a-number'), null);
});

test('dashboard source keeps primary, pattern, and calendar failures distinct from empty states', () => {
    assert.match(dashboardSource, /<div className="alert alert-danger" role="alert">\{loadError\}<\/div>/);
    assert.match(dashboardSource, /setPatternError\('Pattern signals are temporarily unavailable\.'\)/);
    assert.match(dashboardSource, /error=\{patternError\}/);
    assert.match(dashboardSource, /error=\{calendarError\}/);
    assert.doesNotMatch(dashboardSource, /setPatternRows\(\[\]\);\s*setCalendarEvents\(\[\]\);\s*\}\)\s*\.finally/);
});

test('shared table and calendar surfaces expose explicit alert semantics for failures', () => {
    assert.match(dataTableSource, /if \(error\) \{[\s\S]*role="alert"/);
    assert.match(calendarSource, /\) : error \? \([\s\S]*role="alert"/);
});
