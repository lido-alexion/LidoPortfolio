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
    assert.match(source, /params: \{ page: statementPage, per_page: statementPerPage, order: 'desc', entry_type: statementType \|\| undefined, classification: statementClassification \|\| undefined \}/);
    assert.match(source, /Previous cash statement page/);
    assert.match(source, /Next cash statement page/);
    assert.match(source, /\[25, 50, 100\]/);
    assert.match(source, /Showing \$\{\(\(pagination\.page - 1\) \* pagination\.per_page\) \+ 1\}/);
});

test('Kite net adjustments stay visibly classified and filterable in the cash statement', () => {
    assert.match(source, /const \[statementClassification, setStatementClassification\] = useState\(''\)/);
    assert.match(source, /classification: statementClassification \|\| undefined/);
    assert.match(source, /aria-label="Filter cash statement by classification"/);
    assert.match(source, /<option value="kite_net_adjustment">Kite net adjustments<\/option>/);
    assert.match(source, /entry\.entry_classification === 'kite_net_adjustment' \? 'Kite net cash adjustment'/);
});

test('cash edit preview reverses the original signed amount and locks the operation type', () => {
    assert.match(source, /currentBalance - \(Number\.isFinite\(oldSignedAmount\) \? oldSignedAmount : 0\) \+ newSignedAmount/);
    assert.match(source, /disabled=\{Boolean\(editing\)\}/);
    assert.match(source, /oldSignedAmount < 0 \? -oldSignedAmount : 0/);
    assert.match(source, /const clearMovementForm = \(\) => \{/);
    assert.match(source, /onClick=\{clearMovementForm\}>Cancel edit/);
});

test('withdrawal editor uses investable capacity and restores only the edited withdrawal', () => {
    assert.match(source, /const withdrawalCapacity = Math\.max\(0, availableCash\s*\+\s*\(editing && Number\.isFinite\(oldSignedAmount\) && oldSignedAmount < 0 \? -oldSignedAmount : 0\)\)/);
    assert.match(source, /max=\{op === 'withdraw' \? String\(withdrawalCapacity\) : undefined\}/);
    assert.match(source, /const availableCash = Number\(summary\?\.available_investable_cash \?\? summary\?\.available_physical_cash \?\? 0\)/);
    assert.match(source, /const available = withdrawalCapacity/);
    assert.match(source, /role="status" aria-live="polite"/);
    assert.match(source, /const entered = amount\.trim\(\) === '' \|\| !\/\^\[-\+\]\?\\d\+\(\\\.\\d\{0,4\}\)\?\$\//);
    assert.match(source, /step="0\.0001"/);
});
