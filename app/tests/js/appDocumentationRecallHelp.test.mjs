import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const root = join(dirname(fileURLToPath(import.meta.url)), '../../resources/js/src/data/appDocumentation.js');
const source = readFileSync(root, 'utf8');

test('active help uses Recall Bridge Loan and Proceeds from Stock Sale', () => {
    assert.match(source, /Recall Bridge Loan/);
    assert.match(source, /Proceeds from Stock Sale/);
});

test('active help does not use Soft Loan or Return on Stock Sale', () => {
    assert.doesNotMatch(source, /Soft Loan/);
    assert.doesNotMatch(source, /Return on Stock Sale/i);
});

test('active help does not claim recall is unavailable', () => {
    assert.doesNotMatch(source, /Recall is not available yet/);
    assert.doesNotMatch(source, /Recall is not implemented yet/);
});

test('active help documents capital priority and 75% rule', () => {
    assert.match(source, /own capital first/i);
    assert.match(source, /75%/);
    assert.match(source, /recall period/i);
});

test('active help documents SPEC-004 loan recall bridge cash types', () => {
    assert.match(source, /Special cash-ledger types are exactly loan, recall, and bridge/);
    assert.match(source, /positive = money entered trading cash/);
});
