import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const src = join(dirname(fileURLToPath(import.meta.url)), '../../resources/js/src');
const nav = readFileSync(join(src, 'config/navigation.js'), 'utf8');
const page = readFileSync(join(src, 'pages/StrategyPage.jsx'), 'utf8');
const registry = readFileSync(join(src, 'pages/StrategyRegistryPage.jsx'), 'utf8');
const createPanel = readFileSync(join(src, 'components/strategy/CreateStrategyPanel.jsx'), 'utf8');
const recs = readFileSync(join(src, 'pages/RecommendationsPage.jsx'), 'utf8');
const docs = readFileSync(join(src, 'data/appDocumentation.js'), 'utf8');

function catalogEntry(source, id) {
    const marker = `id: '${id}'`;
    const start = source.indexOf(marker);
    assert.notEqual(start, -1, `expected catalog id ${id}`);
    return source.slice(start, start + 900);
}

test('Discovery is absent from the Market sidebar and favourites', () => {
    const entry = catalogEntry(nav, 'discovery');
    assert.match(entry, /title: 'Discovery'/);
    assert.match(entry, /showInSidebar: false/);
    assert.match(entry, /favouriteEligible: false/);
    assert.match(entry, /ROUTES\.CANDIDATES/);
});

test('Strategy Registry remains a Trading sidebar item', () => {
    const entry = catalogEntry(nav, 'strategy-registry');
    assert.match(entry, /title: 'Strategy Registry'/);
    assert.match(entry, /showInSidebar: true/);
    assert.match(entry, /ROUTES\.STRATEGY_REGISTRY/);
});

test('Create Strategy action exists on Registry, editor, and create panel', () => {
    assert.match(registry, /id="create-strategy-open"/);
    assert.match(page, /id="create-strategy-open"/);
    assert.match(createPanel, /id="create-strategy-form"/);
    assert.match(createPanel, /id="create-strategy-name"/);
    assert.match(createPanel, /id="create-strategy-submit"/);
    assert.match(createPanel, /Create Strategy/);
    assert.match(createPanel, /\/v1\/strategy-registry/);
    assert.doesNotMatch(createPanel, /JSON\.parse/);
});

test('Registry and editor expose Enable and Archive without exclusive-activation copy', () => {
    assert.match(registry, /Enable “/);
    assert.match(registry, /Archive this strategy\?/);
    assert.match(registry, /\/archive/);
    assert.match(registry, /last enabled/);
    assert.match(page, /id="strategy-editor-enable"/);
    assert.match(page, /id="strategy-editor-archive"/);
    assert.match(page, /id="strategy-editor-select"/);
    assert.match(page, /navigate\(`\/strategy\?strategy_id=/);
    assert.doesNotMatch(registry, /Select it to make it active/);
    assert.doesNotMatch(registry, /use Select to activate/);
    assert.doesNotMatch(page, /Each portfolio has one strategy/);
    assert.doesNotMatch(page, /one strategy per portfolio/i);
    assert.doesNotMatch(registry, /one strategy per portfolio/i);
    assert.doesNotMatch(docs, /one strategy per portfolio/i);
    assert.doesNotMatch(docs, /Select it to make it active/);
});

test('Recommendations toolbar does not promote Discovery as a workflow tab', () => {
    assert.doesNotMatch(recs, /to="\/candidates">Discovery/);
});

test('Help documents Create Strategy and Discovery not in sidebar', () => {
    assert.match(docs, /name: 'Create Strategy'/);
    assert.match(docs, /POST `\/v1\/strategy-registry`/);
    assert.match(docs, /not listed in the left Market navigation/i);
    assert.match(docs, /last remaining enabled strategy cannot be archived/i);
});
