import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const app = readFileSync(new URL('../../resources/js/src/App.jsx', import.meta.url), 'utf8');
const navigation = readFileSync(new URL('../../resources/js/src/config/navigation.js', import.meta.url), 'utf8');
const detail = readFileSync(new URL('../../resources/js/src/pages/ArtifactLibraryDetailPage.jsx', import.meta.url), 'utf8');

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
