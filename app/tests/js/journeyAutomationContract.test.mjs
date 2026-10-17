import test from 'node:test';
import assert from 'node:assert/strict';
import { parse } from 'acorn';
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

function namesInPattern(pattern, names = []) {
    if (!pattern) return names;
    switch (pattern.type) {
        case 'Identifier': names.push(pattern.name); break;
        case 'RestElement': namesInPattern(pattern.argument, names); break;
        case 'AssignmentPattern': namesInPattern(pattern.left, names); break;
        case 'ArrayPattern': pattern.elements.forEach((element) => namesInPattern(element, names)); break;
        case 'ObjectPattern': pattern.properties.forEach((property) => namesInPattern(property.type === 'RestElement' ? property.argument : property.value, names)); break;
    }
    return names;
}

function declarationNames(statements) {
    const names = new Set();
    for (const statement of statements ?? []) {
        const node = statement.type === 'ExportNamedDeclaration' ? statement.declaration : statement;
        if (node?.type === 'VariableDeclaration') {
            node.declarations.forEach(({ id }) => namesInPattern(id).forEach((name) => names.add(name)));
        } else if ((node?.type === 'FunctionDeclaration' || node?.type === 'ClassDeclaration') && node.id) {
            names.add(node.id.name);
        }
    }
    return names;
}

function functionVarNames(node, names = new Set()) {
    if (!node || typeof node !== 'object') return names;
    if (Array.isArray(node)) {
        node.forEach((child) => functionVarNames(child, names));
        return names;
    }
    if (['FunctionDeclaration', 'FunctionExpression', 'ArrowFunctionExpression', 'ClassDeclaration', 'ClassExpression'].includes(node.type)) return names;
    if (node.type === 'VariableDeclaration' && node.kind === 'var') {
        node.declarations.forEach(({ id }) => namesInPattern(id).forEach((name) => names.add(name)));
    }
    for (const [key, value] of Object.entries(node)) {
        if (key !== 'type' && key !== 'start' && key !== 'end') functionVarNames(value, names);
    }
    return names;
}

function scopeWithBindings(parent, bindings = new Map()) {
    return { parent, bindings };
}

function visitSource(node, scope, file, documented, automated) {
    if (!node || typeof node !== 'object') return;
    if (Array.isArray(node)) {
        node.forEach((child) => visitSource(child, scope, file, documented, automated));
        return;
    }

    if (node.type === 'Program') {
        const bindings = new Map([...declarationNames(node.body)].map((name) => [name, { kind: 'unsupported' }]));
        node.body.forEach((child) => visitSource(child, scopeWithBindings(scope, bindings), file, documented, automated));
        return;
    }
    if (node.type === 'BlockStatement') {
        const bindings = new Map([...declarationNames(node.body)].map((name) => [name, { kind: 'unsupported' }]));
        node.body.forEach((child) => visitSource(child, scopeWithBindings(scope, bindings), file, documented, automated));
        return;
    }
    if (node.type === 'FunctionDeclaration' || node.type === 'FunctionExpression' || node.type === 'ArrowFunctionExpression') {
        const names = new Set(node.params.flatMap((param) => namesInPattern(param)));
        if (node.id) names.add(node.id.name);
        if (node.body.type === 'BlockStatement') {
            declarationNames(node.body.body).forEach((name) => names.add(name));
            functionVarNames(node.body).forEach((name) => names.add(name));
        }
        const functionScope = scopeWithBindings(scope, new Map([...names].map((name) => [name, { kind: 'unsupported' }])));
        for (const [key, value] of Object.entries(node)) {
            if (key !== 'type' && key !== 'start' && key !== 'end') visitSource(value, functionScope, file, documented, automated);
        }
        return;
    }
    if (node.type === 'ForOfStatement') {
        visitSource(node.right, scope, file, documented, automated);
        const bindings = new Map();
        if (node.left.type === 'VariableDeclaration') {
            node.left.declarations.forEach(({ id }) => namesInPattern(id).forEach((name) => bindings.set(name, { kind: 'unsupported' })));
            if (node.left.kind === 'const' && node.left.declarations.length === 1 && node.left.declarations[0].id.type === 'Identifier') {
                const name = node.left.declarations[0].id.name;
                const ids = node.right.type === 'ArrayExpression' && node.right.elements.length > 0
                    ? node.right.elements.map((element) => element?.type === 'Literal' && typeof element.value === 'string' && idPattern.test(element.value) ? element.value : null)
                    : null;
                bindings.set(name, ids && ids.every(Boolean) ? { kind: 'journey-loop', ids } : { kind: 'unsupported' });
            }
        } else if (node.left.type === 'Identifier') {
            bindings.set(node.left.name, { kind: 'unsupported' });
        }
        visitSource(node.body, scopeWithBindings(scope, bindings), file, documented, automated);
        return;
    }
    if (node.type === 'ForInStatement' || node.type === 'ForStatement') {
        if (node.type === 'ForInStatement') visitSource(node.right, scope, file, documented, automated);
        const bindingNode = node.type === 'ForInStatement' ? node.left : node.init;
        const bindings = new Map();
        if (bindingNode?.type === 'VariableDeclaration') bindingNode.declarations.forEach(({ id }) => namesInPattern(id).forEach((name) => bindings.set(name, { kind: 'unsupported' })));
        if (bindingNode?.type === 'Identifier') bindings.set(bindingNode.name, { kind: 'unsupported' });
        for (const [key, value] of Object.entries(node)) {
            if (key !== 'type' && key !== 'start' && key !== 'end' && key !== 'body' && !(node.type === 'ForInStatement' && key === 'right')) {
                visitSource(value, scope, file, documented, automated);
            }
        }
        visitSource(node.body, scopeWithBindings(scope, bindings), file, documented, automated);
        return;
    }
    if (node.type === 'SwitchStatement') {
        visitSource(node.discriminant, scope, file, documented, automated);
        const statements = node.cases.flatMap((caseNode) => caseNode.consequent);
        const bindings = new Map([...declarationNames(statements)].map((name) => [name, { kind: 'unsupported' }]));
        node.cases.forEach((caseNode) => visitSource(caseNode, scopeWithBindings(scope, bindings), file, documented, automated));
        return;
    }
    if (node.type === 'CallExpression' && node.callee.type === 'Identifier' && node.callee.name === 'journeyId') {
        const [testInfo, journey] = node.arguments;
        if (node.arguments.length !== 2 || testInfo?.type !== 'Identifier' || testInfo.name !== 'testInfo') {
            throw new Error(`${file} has an unsupported journeyId call`);
        }
        let ids;
        if (journey.type === 'Literal' && typeof journey.value === 'string' && idPattern.test(journey.value)) {
            ids = [journey.value];
        } else if (journey.type === 'Identifier') {
            let current = scope;
            while (current && !current.bindings.has(journey.name)) current = current.parent;
            const binding = current?.bindings.get(journey.name);
            if (binding?.kind !== 'journey-loop') throw new Error(`${file} has an unsupported or shadowed journeyId binding: ${journey.name}`);
            ids = binding.ids;
        } else {
            throw new Error(`${file} has an unsupported journeyId annotation`);
        }
        for (const id of ids) {
            assert.ok(documented.has(id), `${file} refers to undocumented journey ${id}`);
            automated.add(id);
        }
        return;
    }
    if (node.type === 'CatchClause') {
        const names = namesInPattern(node.param);
        visitSource(node.body, scopeWithBindings(scope, new Map(names.map((name) => [name, { kind: 'unsupported' }]))), file, documented, automated);
        return;
    }

    for (const [key, value] of Object.entries(node)) {
        if (key !== 'type' && key !== 'start' && key !== 'end') visitSource(value, scope, file, documented, automated);
    }
}

function collectAutomatedIds(sources, documented) {
    const automated = new Set();
    for (const [file, source] of Object.entries(sources)) {
        const ast = parse(source, { ecmaVersion: 'latest', sourceType: 'module' });
        visitSource(ast, null, file, documented, automated);
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

test('annotation contract fixtures enforce literal and lexical loop coverage', () => {
    const documented = new Set(['AUTH-01', 'E2E-01', 'E2E-02']);
    const fixture = {
        'literal.spec.js': "journeyId(testInfo, 'AUTH-01');",
        'loop.spec.js': [
            "const brace = '}';",
            "const braceRegex = /journeyId\\(testInfo, 'VIZ-997'\\)}/;",
            "const fakeCalls = `journeyId(testInfo, 'VIZ-999') ${`nested } journeyId(testInfo, 'VIZ-998')`}`;",
            "for (const journey of ['E2E-01']) { journeyId(testInfo, journey); }",
            "for (const journey of ['E2E-02']) { journeyId(testInfo, journey); }",
        ].join('\n'),
    };
    assert.deepEqual([...collectAutomatedIds(fixture, documented)].sort(), [...documented].sort());

    assert.throws(() => assertCompleteCoverage({ ...fixture, 'literal.spec.js': '' }, documented), /every documented ID/);
    const withoutLoopId = { ...fixture, 'loop.spec.js': "for (const journey of ['E2E-01']) { journeyId(testInfo, journey); }" };
    assert.throws(() => assertCompleteCoverage(withoutLoopId, documented), /every documented ID/);
    assert.throws(() => collectAutomatedIds({ 'unknown.spec.js': "journeyId(testInfo, 'VIZ-999');" }, documented), /undocumented journey VIZ-999/);
    assert.throws(() => collectAutomatedIds({ 'dynamic.spec.js': 'journeyId(testInfo, resolveJourney());' }, documented), /unsupported journeyId annotation/);
    assert.throws(() => collectAutomatedIds({
        'outside-loop.spec.js': "for (const journey of ['E2E-01']) {} journeyId(testInfo, journey);",
    }, documented), /unsupported or shadowed journeyId binding/);
    assert.throws(() => collectAutomatedIds({
        'shadowed.spec.js': "for (const journey of ['E2E-01']) { { const journey = 'E2E-02'; journeyId(testInfo, journey); } }",
    }, documented), /unsupported or shadowed journeyId binding/);
    assert.throws(() => collectAutomatedIds({
        'shadowed-parameter.spec.js': "for (const journey of ['E2E-01']) { const callback = (journey) => journeyId(testInfo, journey); }",
    }, documented), /unsupported or shadowed journeyId binding/);
    assert.throws(() => collectAutomatedIds({
        'shadowed-destructuring.spec.js': "for (const journey of ['E2E-01']) { { const { journey } = source; journeyId(testInfo, journey); } }",
    }, documented), /unsupported or shadowed journeyId binding/);
    assert.throws(() => collectAutomatedIds({
        'nested-var-before.spec.js': "for (const journey of ['E2E-01']) { function callback() { if (true) { var journey = 'E2E-02'; } journeyId(testInfo, journey); } }",
    }, documented), /unsupported or shadowed journeyId binding/);
    assert.throws(() => collectAutomatedIds({
        'nested-var-after.spec.js': "for (const journey of ['E2E-01']) { function callback() { journeyId(testInfo, journey); if (true) { var journey = 'E2E-02'; } } }",
    }, documented), /unsupported or shadowed journeyId binding/);
    assert.throws(() => collectAutomatedIds({
        'for-init-var.spec.js': "for (const journey of ['E2E-01']) { function callback() { for (var journey = 'E2E-02'; false;) {} journeyId(testInfo, journey); } }",
    }, documented), /unsupported or shadowed journeyId binding/);
    assert.throws(() => collectAutomatedIds({
        'mutable-array.spec.js': "const journeys = ['E2E-01']; journeys.push('E2E-02'); for (const journey of journeys) { journeyId(testInfo, journey); }",
    }, documented), /unsupported or shadowed journeyId binding/);
    assert.throws(() => assertCompleteCoverage({
        'comment-and-string.spec.js': "// journeyId(testInfo, 'AUTH-01');\nconst example = \"journeyId(testInfo, 'E2E-01')\";",
    }, documented), /every documented ID/);
});
