import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const root = join(dirname(fileURLToPath(import.meta.url)), '../../resources/js/src/data/appDocumentation.js');
const source = readFileSync(root, 'utf8');

test('active help documents SPEC-002 rights as a normal purchase not a CA', () => {
    assert.match(source, /Rights issues \(V4-SPEC-002\)/);
    assert.match(source, /record them as a normal purchase at the actual subscription price/);
    assert.match(source, /There is no rights-issue wizard/);
});
