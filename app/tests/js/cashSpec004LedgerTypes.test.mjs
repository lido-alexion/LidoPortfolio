import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const page = join(dirname(fileURLToPath(import.meta.url)), '../../resources/js/src/pages/CashManagementPage.jsx');
const source = readFileSync(page, 'utf8');

test('cash statement labels SPEC-004 loan recall bridge types', () => {
    assert.match(source, /case 'loan': return 'Loan'/);
    assert.match(source, /case 'recall': return 'Recall'/);
    assert.match(source, /case 'bridge': return 'Bridge'/);
    assert.doesNotMatch(source, /LOAN_IN/);
    assert.doesNotMatch(source, /RECALL_OUT/);
});

test('V5 cash statement uses effective-date balances and requires adjustment reasons', () => {
    assert.match(source, /api\.get\('\/cash\/statement'/);
    assert.match(source, /entry\.running_balance/);
    assert.match(source, /fmtWhen\(entry\.created_at\)/);
    assert.match(source, /Adjustment reason is required/);
    assert.match(source, /required=\{op === 'adjust'\}/);
    assert.match(source, /downloadPortfolioCsv\('cash_statement'/);
});
