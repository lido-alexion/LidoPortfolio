import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '../../resources/js/src/data/appDocumentation.js');
const source = readFileSync(root, 'utf8');

test('active help documents FEAT-009 review reports list and stored metrics', () => {
    assert.match(source, /id: 'review-reports'/);
    assert.match(source, /\/review\/reports/);
    assert.match(source, /Accepted \(not executed\)/);
    assert.match(source, /period_start/);
    assert.match(source, /period_end/);
    assert.match(source, /90-day default/);
});
