import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(here, '../../../');
const journeysRoot = path.join(repoRoot, 'docs/user-journeys');
const e2eRoot = path.join(here, '../e2e');
const idPattern = /^(AUTH|SCR|STR|REC|EXE|E2E|AI|VIZ)-\d+$/;

function readDocumentedIds(root) {
    const documented = new Set();
    for (const file of fs.readdirSync(root).filter((name) => /^\d{2}-.*\.md$/.test(name))) {
        const source = fs.readFileSync(path.join(root, file), 'utf8');
        for (const [, id] of source.matchAll(/^##\s+((?:AUTH|SCR|STR|REC|EXE|E2E|AI|VIZ)-\d+)\s+—/gm)) {
            documented.add(id);
        }
    }
    return documented;
}

function collectAutomatedIds(sources, documented) {
    const automated = new Set();
    for (const [file, source] of Object.entries(sources)) {
        const loops = new Map();
        const calls = [...source.matchAll(/\bjourneyId\s*\(([^)]*)\)/g)];
        const dynamicVariables = new Set(calls.map((match) => {
            const args = match[1].match(/^\s*testInfo\s*,\s*(.*?)\s*$/s);
            if (!args) throw new Error(`${file} has an unsupported journeyId call`);
            const value = args[1];
            return /^['"]/.test(value) ? null : value;
        }).filter(Boolean));
        for (const loopMatch of source.matchAll(/for\s*\(\s*const\s+([A-Za-z_$][\w$]*)\s+of\s*\[([^\]]*)\]\s*\)/g)) {
            const [, variable, values] = loopMatch;
            if (!dynamicVariables.has(variable)) continue;
            const ids = [];
            const itemPattern = /\s*(['"])([A-Za-z0-9-]+)\1\s*(?:,|$)/gy;
            let offset = 0;
            while (offset < values.length) {
                itemPattern.lastIndex = offset;
                const item = itemPattern.exec(values);
                if (!item) throw new Error(`${file} has an unsupported journey loop array`);
                ids.push(item[2]);
                offset = itemPattern.lastIndex;
            }
            if (!ids.length) throw new Error(`${file} has an empty journey loop array`);
            const declarations = loops.get(variable) ?? [];
            declarations.push({ index: loopMatch.index, ids });
            // The source currently reuses `journey` in separate bounded loops. Keep
            // declarations in source order so each annotation binds to its nearest
            // preceding literal-array loop.
            loops.set(variable, declarations);
        }

        for (const match of calls) {
            const args = match[1].match(/^\s*testInfo\s*,\s*(.*?)\s*$/s);
            if (!args) throw new Error(`${file} has an unsupported journeyId call`);
            const value = args[1];
            const literal = value.match(/^(['"])([A-Za-z0-9-]+)\1$/);
            let ids;
            if (literal) {
                ids = [literal[2]];
            } else if (/^[A-Za-z_$][\w$]*$/.test(value) && loops.has(value)) {
                const declaration = [...loops.get(value)].reverse().find((loop) => loop.index < match.index);
                if (!declaration) throw new Error(`${file} has an unsupported journeyId annotation: ${value}`);
                ids = declaration.ids;
            } else {
                throw new Error(`${file} has an unsupported journeyId annotation: ${value}`);
            }

            for (const id of ids) {
                assert.match(id, idPattern, `${file} uses an invalid journey ID`);
                assert.ok(documented.has(id), `${file} refers to undocumented journey ${id}`);
                automated.add(id);
            }
        }
    }
    return automated;
}

function assertCompleteCoverage(sources, documented) {
    const automated = collectAutomatedIds(sources, documented);
    assert.deepEqual([...automated].sort(), [...documented].sort(), 'every documented ID must have browser automation and every annotation must be documented');
}

test('all documented journey IDs are connected to supported browser annotations', () => {
    const documented = readDocumentedIds(journeysRoot);
    const sources = Object.fromEntries(fs.readdirSync(e2eRoot)
        .filter((name) => name.endsWith('.spec.js'))
        .map((name) => [name, fs.readFileSync(path.join(e2eRoot, name), 'utf8')]));
    assertCompleteCoverage(sources, documented);
});

test('annotation contract fixtures fail closed for removed and unsupported coverage', () => {
    const documented = new Set(['AUTH-01', 'E2E-01', 'E2E-02']);
    const fixture = {
        'literal.spec.js': "journeyId(testInfo, 'AUTH-01');",
        'loop.spec.js': "for (const journey of ['E2E-01', 'E2E-02']) { journeyId(testInfo, journey); }",
    };
    assert.deepEqual([...collectAutomatedIds(fixture, documented)].sort(), [...documented].sort());

    assert.throws(() => assertCompleteCoverage({ ...fixture, 'literal.spec.js': '' }, documented), /every documented ID/);
    const withoutLoopId = { ...fixture, 'loop.spec.js': "for (const journey of ['E2E-01']) { journeyId(testInfo, journey); }" };
    assert.throws(() => assertCompleteCoverage(withoutLoopId, documented), /every documented ID/);
    assert.throws(() => collectAutomatedIds({ 'unknown.spec.js': "journeyId(testInfo, 'VIZ-999');" }, documented), /undocumented journey VIZ-999/);
    assert.throws(() => collectAutomatedIds({ 'dynamic.spec.js': 'journeyId(testInfo, resolveJourney());' }, documented), /unsupported journeyId annotation/);
});
