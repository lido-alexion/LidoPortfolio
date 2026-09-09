import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const app = readFileSync(new URL('../../resources/js/src/App.jsx', import.meta.url), 'utf8');
const navigation = readFileSync(new URL('../../resources/js/src/config/navigation.js', import.meta.url), 'utf8');
const detail = readFileSync(new URL('../../resources/js/src/pages/ArtifactLibraryDetailPage.jsx', import.meta.url), 'utf8');
const screenerEditor = readFileSync(new URL('../../resources/js/src/pages/ScreenerEditorPage.jsx', import.meta.url), 'utf8');
const screenerRegistry = readFileSync(new URL('../../resources/js/src/pages/ScreenerRegistryPage.jsx', import.meta.url), 'utf8');
const strategyCreate = readFileSync(new URL('../../resources/js/src/components/strategy/CreateStrategyPanel.jsx', import.meta.url), 'utf8');
const strategyRegistry = readFileSync(new URL('../../resources/js/src/pages/StrategyRegistryPage.jsx', import.meta.url), 'utf8');

test('immutable Artifact Library is routable and discoverable in Trading navigation', () => {
    assert.match(app, /path="\/artifact-library"/);
    assert.match(app, /path="\/artifact-library\/:uuid"/);
    assert.match(navigation, /title: 'Artifact Library'/);
    assert.match(navigation, /route: ROUTES\.ARTIFACT_LIBRARY/);
});

test('Artifact Library exposes only explicit binding upgrade and transactional Bundle deployment actions', () => {
    assert.match(detail, /Upgrade binding/);
    assert.match(detail, /expected_lock_version: binding\.lock_version/);
    assert.match(detail, /bundle-plan/);
    assert.match(detail, /bundle-deployments\/\$\{plan\.deployment_uuid\}\/deploy/);
    assert.doesNotMatch(detail, /auto.?upgrade/i);
});

test('Artifact detail exposes immutable package export, exact dependencies, and structural comparison', () => {
    assert.match(detail, /Structural version comparison/);
    assert.match(detail, /Exact dependencies/);
    assert.match(detail, /Published package downloaded/);
    assert.match(detail, /\/diff/);
});

test('Draft, share, Fork, archive, and enablement controls retain explicit lifecycle gates', () => {
    const library = readFileSync(new URL('../../resources/js/src/pages/ArtifactLibraryPage.jsx', import.meta.url), 'utf8');
    assert.match(library, /AI cannot publish or deploy it/);
    assert.match(library, /Imported .* validated Drafts\. Nothing was published or deployed/);
    assert.match(detail, /Saving never publishes or deploys this artifact/);
    assert.match(detail, /Exact dependencies JSON array/);
    assert.match(detail, /New version Draft/);
    assert.match(detail, />Share</);
    assert.match(detail, />Fork</);
    assert.match(detail, /Archive artifact/);
    assert.match(detail, /artifact-bindings\/\$\{binding\.binding_uuid\}\/enabled/);
});

test('legacy authoring surfaces redirect new definitions to Library Drafts', () => {
    assert.match(strategyCreate, /createdArtifactPath/);
    assert.match(strategyRegistry, /navigate\(path\)/);
    assert.match(screenerEditor, /created\?\.library_path/);
    assert.match(screenerRegistry, /Copied shared Screener as Artifact Library Draft/);
    assert.match(detail, /suggested_binding_settings/);
    assert.match(detail, /settings: suggestedSettings/);
});
