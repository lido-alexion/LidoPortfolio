import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import {
    validateFirstEntryPct,
    validateHorizonCalendarDays,
} from '../../resources/js/src/utils/strategyPageRules.js';

const src = join(dirname(fileURLToPath(import.meta.url)), '../../resources/js/src');
const page = readFileSync(join(src, 'pages/StrategyPage.jsx'), 'utf8');
const docs = readFileSync(join(src, 'data/appDocumentation.js'), 'utf8');
const factory = readFileSync(
    join(dirname(fileURLToPath(import.meta.url)), '../../app/Engines/Strategy/FactoryMomentumStrategy.php'),
    'utf8',
);
const cooldown = readFileSync(
    join(dirname(fileURLToPath(import.meta.url)), '../../app/Services/Entry/BuyCooldownEvaluator.php'),
    'utf8',
);

test('horizon_calendar_days control is rendered on Strategy page Exit tab', () => {
    assert.match(page, /id="horizon-calendar-days"/);
    assert.match(page, /Horizon \(calendar days\)/);
    assert.match(page, /horizon_calendar_days/);
    assert.match(page, /no horizon expiry/);
});

test('horizon empty/unset is allowed; valid integer accepted; invalid rejected', () => {
    assert.deepEqual(validateHorizonCalendarDays(''), { ok: true, persist: null });
    assert.deepEqual(validateHorizonCalendarDays(null), { ok: true, persist: null });
    assert.deepEqual(validateHorizonCalendarDays(undefined), { ok: true, persist: null });
    assert.deepEqual(validateHorizonCalendarDays(30), { ok: true, persist: 30 });
    assert.equal(validateHorizonCalendarDays(0).ok, false);
    assert.equal(validateHorizonCalendarDays(-5).ok, false);
    assert.equal(validateHorizonCalendarDays(1.5).ok, false);
    assert.equal(validateHorizonCalendarDays('abc').ok, false);
});

test('first_entry_pct control is rendered; factory default remains 50', () => {
    assert.match(page, /id="first-entry-pct"/);
    assert.match(page, /First entry %/);
    assert.match(page, /first_entry_pct/);
    assert.match(page, /engine default \(50%\)/);
    assert.match(factory, /'first_entry_pct'\s*=>\s*50\.0/);
});

test('first_entry empty uses engine default path; valid pct accepted; out of range rejected', () => {
    assert.deepEqual(validateFirstEntryPct(''), { ok: true, persist: null });
    assert.deepEqual(validateFirstEntryPct(50), { ok: true, persist: 50 });
    assert.deepEqual(validateFirstEntryPct(40), { ok: true, persist: 40 });
    assert.equal(validateFirstEntryPct(0).ok, false);
    assert.equal(validateFirstEntryPct(101).ok, false);
    assert.equal(validateFirstEntryPct(-1).ok, false);
});

test('Strategy page persists both keys via existing PUT /v1/strategy config.portfolio_rules', () => {
    assert.match(page, /api\.put\('\/v1\/strategy'/);
    assert.match(page, /horizon_calendar_days: horizonCheck\.persist/);
    assert.match(page, /first_entry_pct: firstEntryCheck\.persist/);
});

test('help documents horizon and first-entry as strategy settings', () => {
    assert.match(docs, /horizon_calendar_days/);
    assert.match(docs, /no horizon expiry/);
    assert.match(docs, /engine default \(50%\)/);
    assert.match(docs, /First entry % \(first_entry_pct\)/);
});

test('Strategy page does not duplicate portfolio SL/trailing or expose BUY cooldown', () => {
    assert.doesNotMatch(page, /portfolio_trailing_percent/);
    assert.doesNotMatch(page, /default_stoploss_percent/);
    assert.doesNotMatch(page, /buy_cooldown/);
    assert.match(cooldown, /COOLDOWN_CALENDAR_DAYS = 1/);
});
