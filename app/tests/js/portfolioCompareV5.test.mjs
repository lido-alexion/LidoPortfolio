import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '../../resources/js/src');
const page = readFileSync(join(root, 'pages/PortfolioComparePage.jsx'), 'utf8');
const app = readFileSync(join(root, 'App.jsx'), 'utf8');

test('V5 portfolio compare is deep-linkable and does not mislabel wealth change as return', () => {
    assert.match(app, /path="\/portfolio\/compare"/);
    assert.match(page, /useSearchParams/);
    assert.match(page, /\/portfolio\/compare/);
    assert.match(page, /Value change is not investment return or causal attribution/);
    assert.match(page, /External flows/);
    assert.match(page, /Transaction evidence in \(A, B\]/);
    assert.match(page, /downloadPortfolioCsv\('portfolio_compare'/);
    for (const shortcut of ['1M', '3M', '6M', 'YTD', '1Y', 'Since inception']) {
        assert.match(page, new RegExp(`'${shortcut}'`));
    }
});
