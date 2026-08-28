import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '../../resources/js/src/data/appDocumentation.js');
const source = readFileSync(root, 'utf8');

test('active help documents FEAT-010 unattended pipeline and Telegram ops alerts', () => {
    assert.match(source, /Unattended pipeline \(V4-FEAT-010\)/);
    assert.match(source, /schedule:run/);
    assert.match(source, /one effective pipeline decision per portfolio calendar day/);
    assert.match(source, /they are not emailed/);
    assert.match(source, /6-hour cooldown/);
});
