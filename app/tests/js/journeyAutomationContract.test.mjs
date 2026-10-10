import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(here, '../../../');
const journeysRoot = path.join(repoRoot, 'docs/user-journeys');
const e2eRoot = path.join(here, '../e2e');
const idPattern = /^(AUTH|SCR|STR|REC|EXE|E2E|AI)-\d+$/;

test('automated journey annotations resolve to an authoritative journey heading', () => {
    const documented = new Set();
    for (const file of fs.readdirSync(journeysRoot).filter((name) => /^\d{2}-.*\.md$/.test(name))) {
        const source = fs.readFileSync(path.join(journeysRoot, file), 'utf8');
        for (const [, id] of source.matchAll(/^##\s+((?:AUTH|SCR|STR|REC|EXE|E2E|AI)-\d+)\s+—/gm)) {
            documented.add(id);
        }
    }

    const automated = new Set();
    for (const file of fs.readdirSync(e2eRoot).filter((name) => name.endsWith('.spec.js'))) {
        const source = fs.readFileSync(path.join(e2eRoot, file), 'utf8');
        for (const [, id] of source.matchAll(/journeyId\(testInfo,\s*['"]([^'"]+)['"]\)/g)) {
            assert.match(id, idPattern, `${file} uses an invalid journey ID`);
            assert.ok(documented.has(id), `${file} refers to undocumented journey ${id}`);
            automated.add(id);
        }
    }

    assert.ok(automated.has('AUTH-01'), 'sign-in journey must remain connected to browser automation');
    assert.ok(automated.has('AUTH-02'), 'credential-recovery journey must remain connected to browser automation');
});
