import { createApiFailureDiagnosticCollector, installApiFailureDiagnosticBoundary, handleApiFailureDiagnostic, registerApiFailureDiagnosticAfterEach } from './apiFailureDiagnosticBoundary.js';

import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { readFile } from 'node:fs/promises';
import { journeyId, seedDeterministicJourney } from './journeyTestUtils.js';
import { TEST_PORTFOLIO } from '../js/tos/fixtures/tosApi.js';
import { installInvestorWorkflowApiMocks } from './investorWorkflowApiMocks.js';

registerApiFailureDiagnosticAfterEach(test);


const VIEWPORTS = [{ name: 'mobile', width: 390, height: 844 }, { name: 'desktop', width: 1440, height: 900 }];
const error = page => page.locator('.lido-main .contentPane.d-grid .alert[role="alert"]');
const failure = route => route.fulfill({ status: 503, json: { message: 'Synthetic registry unavailable.' } });
const screener = id => ({ schema_version: '1.0', artifact_type: 'screener', artifact_id: id, artifact_version: 2, slug: 'reviewed-runtime-screen', name: 'Reviewed runtime screen', definition_hash: 'synthetic-immutable-definition', metadata: { ownership: 'own', status: 'active', origin: 'imported', scope: 'portfolio', universe: 'all_equities' }, definition: { root: { type: 'condition', left: { indicator: 'close' }, operator: 'gt', right: { type: 'constant', value: 0 } } } });
const strategy = id => ({ schema_version: '1.0', artifact_type: 'strategy', artifact_id: id, artifact_version: 3, slug: 'reviewed-runtime-strategy', name: 'Reviewed runtime strategy', definition_hash: 'synthetic-strategy-definition', metadata: { ownership: 'own', status: 'draft', origin: 'imported', scope: 'portfolio', is_enabled: false }, definition: { eligibility_sources: [{ screener_slug: 'reviewed-runtime-screen', screener_version: 2, enabled: true, priority: 1 }], scoring_model: [{ key: 'relative_strength', weight: 100, enabled: true, minimum: 70, maximum: null, parameters: [] }] }, dependencies: [] });

async function prepare(page, viewport, name, allowed = () => false) {
    await seedDeterministicJourney(page, `registry-${name}-${viewport.name}`);
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    await installInvestorWorkflowApiMocks(page);
    await page.route('**/api/portfolios', route => route.fulfill({ json: { data: [TEST_PORTFOLIO, { ...TEST_PORTFOLIO, id: 2, name: 'Long Term', is_default: false }] } }));
    const blocked = [], writes = [], diagnosticReports = createApiFailureDiagnosticCollector(page);
    await installApiFailureDiagnosticBoundary(page, { origin: 'http://127.0.0.1:4177', collector: diagnosticReports });
    return { blocked, writes, seal: () => page.route('**/*', async route => {
        const request = route.request(), url = new URL(request.url());
        const write = /^(POST|PUT|PATCH|DELETE)$/.test(request.method());
        if (await handleApiFailureDiagnostic(route, { origin: 'http://127.0.0.1:4177', collector: diagnosticReports })) return;
        if (url.origin !== 'http://127.0.0.1:4177' || (write && url.pathname !== '/api/logs/frontend' && !allowed(request.method(), url.pathname))) { blocked.push(`${request.method()} ${url.pathname}`); return route.abort(); }
        if (write && url.pathname !== '/api/logs/frontend') writes.push({ method: request.method(), path: url.pathname, body: request.postDataJSON() });
        return route.fallback();
    }) };
}
async function switchPortfolio(page, current, next) { await page.getByRole('button', { name: current, exact: true }).click(); await page.getByRole('button', { name: next, exact: true }).click(); await expect(page.locator('.lido-portfolio-switcher')).toHaveText(next); }
const otherScope = route => route.request().headers()['x-profile-id'] === '2';
const scopeFailure = route => route.fulfill({ status: 403, json: { message: 'Artifact belongs to a different Portfolio.' } });
async function nativeAction(page, action, answer) {
    let done; const answered = new Promise(resolve => { done = resolve; });
    page.once('dialog', async dialog => { await dialog[answer](); done(); });
    await action(); await answered;
}
async function accessibleRegistry(page) { const result = await new AxeBuilder({ page }).include('.contentPane').withTags(['wcag2a', 'wcag2aa']).analyze(); expect(result.violations).toEqual([]); }
async function downloadJson(page, action) {
    const pending = page.waitForEvent('download'); await action();
    const download = await pending; return { name: download.suggestedFilename(), value: JSON.parse(await readFile(await download.path(), 'utf8')) };
}

for (const viewport of VIEWPORTS) test.describe(`Runtime registry compatibility ${viewport.name}`, () => {
    test('E2E-54 recovers catalogue reads and validates the exact runtime Screener import', async ({ page }, testInfo) => {
        journeyId(testInfo, 'E2E-54');
        const safety = await prepare(page, viewport, 'screener-import', (method, path) => method === 'POST' && ['/api/v1/screener-registry/validate', '/api/v1/screener-registry/import', '/api/v1/screener-registry/69/export'].includes(path));
        const source = screener(69), original = JSON.stringify(source); let readFails = true, validateFails = true, importFails = true;
        const imported = { ...screener(501), name: 'Reviewed import', slug: 'reviewed-import' };
        const payload = { ...imported }; delete payload.artifact_id;
        await page.route('**/api/v1/screener-registry/meta', route => route.fulfill({ json: { data: { counts: { own: 1, shared_available: 0, factory: 0 } } } }));
        await page.route('**/api/v1/screener-registry?*', route => readFails ? failure(route) : route.fulfill({ json: { data: [source] } }));
        await page.route('**/api/v1/screener-registry', route => readFails ? failure(route) : route.fulfill({ json: { data: [source] } }));
        await page.route('**/api/v1/screener-registry/69/export', route => route.fulfill({ json: { data: source } }));
        await page.route('**/api/v1/screener-registry/validate', route => { expect(route.request().postDataJSON()).toEqual(payload); if (validateFails) { validateFails = false; return failure(route); } return route.fulfill({ json: { data: { ok: true, issues: [] } } }); });
        await page.route('**/api/v1/screener-registry/import', route => { expect(route.request().postDataJSON()).toEqual(payload); if (importFails) { importFails = false; return failure(route); } return route.fulfill({ status: 201, json: { data: imported } }); });
        await safety.seal(); await page.goto('/screeners/registry');
        await expect(error(page).filter({ hasText: 'Screener Registry could not be loaded' })).toBeVisible();
        await expect(page.getByText('No screeners match the filters.', { exact: true })).toHaveCount(0);
        readFails = false; await page.getByRole('button', { name: 'Retry Screener Registry' }).click();
        const row = page.getByRole('row').filter({ hasText: source.name }); await expect(row).toBeVisible();
        const exported = await downloadJson(page, () => row.getByRole('button', { name: 'Export', exact: true }).click());
        expect(exported).toEqual({ name: `${source.slug}.json`, value: source });
        const input = page.getByRole('textbox', { name: 'Screener registry JSON' }); await input.fill(JSON.stringify(payload));
        const importButton = page.getByRole('button', { name: 'Import', exact: true }); await expect(importButton).toBeDisabled();
        await page.getByRole('button', { name: 'Validate', exact: true }).click(); await expect(error(page).filter({ hasText: 'Synthetic registry unavailable' })).toBeVisible(); await expect(input).toHaveValue(JSON.stringify(payload));
        await expect(importButton).toBeDisabled(); await page.getByRole('button', { name: 'Validate', exact: true }).click(); await expect(importButton).toBeEnabled();
        await input.fill(`${JSON.stringify(payload)}\n`); await expect(importButton).toBeDisabled();
        await page.getByRole('button', { name: 'Validate', exact: true }).click(); await expect(importButton).toBeEnabled();
        await importButton.click(); await expect(error(page).filter({ hasText: 'Synthetic registry unavailable' })).toBeVisible(); await expect(input).toHaveValue(`${JSON.stringify(payload)}\n`);
        await importButton.click(); await expect(page).toHaveURL(/\/screeners\/501(?:[?#]|$)/);
        expect(JSON.stringify(source)).toBe(original); expect(safety.writes.filter(w => w.path.endsWith('/import'))).toHaveLength(2); expect(safety.blocked).toEqual([]);
    });

    test('E2E-54 preserves shared definitions, recovers envelope/version reads and copies ownership', async ({ page }, testInfo) => {
        journeyId(testInfo, 'E2E-54'); const safety = await prepare(page, viewport, 'shared-screen', (method, path) => method === 'POST' && path === '/api/v1/screener-registry/shared/70/import');
        const own = screener(69), shared = { ...screener(70), name: 'Shared source screen', slug: 'shared-source-screen', metadata: { ...screener(70).metadata, ownership: 'shared', read_only: true } };
        const original = JSON.stringify(shared); let envelopeFails = true, versionsFail = true, copyFails = true, sharedVersionReads = 0;
        await page.route('**/api/v1/screener-registry/69', route => otherScope(route) ? scopeFailure(route) : envelopeFails ? failure(route) : route.fulfill({ json: { data: own } }));
        await page.route('**/api/v1/screener-registry/69/versions', route => versionsFail ? failure(route) : route.fulfill({ json: { data: [{ version: 2, status: 'active', definition_hash: own.definition_hash, change_notes: 'Reviewed immutable version', created_at: '2026-10-01' }] } }));
        await page.route('**/api/v1/screener-registry/70', route => route.fulfill({ json: { data: shared } }));
        await page.route('**/api/v1/screener-registry/70/versions', route => { sharedVersionReads++; return route.fulfill({ json: { data: [] } }); });
        await page.route('**/api/v1/screener-registry/meta', route => route.fulfill({ json: { data: { counts: { own: 1, shared_available: 1, factory: 0 } } } }));
        await page.route('**/api/v1/screener-registry', route => route.fulfill({ json: { data: [own, shared] } }));
        await page.route('**/api/v1/screener-registry/shared/70/import', route => { if (copyFails) { copyFails = false; return failure(route); } return route.fulfill({ status: 201, json: { data: { ...shared, artifact_id: 501, metadata: { ...shared.metadata, ownership: 'own', read_only: false } } } }); });
        await safety.seal(); await page.goto('/screeners/registry/69'); await expect(error(page).filter({ hasText: 'Synthetic registry unavailable' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Export JSON', exact: true })).toBeDisabled(); envelopeFails = false; await page.getByRole('button', { name: 'Retry registry artifact' }).click();
        await expect(page.getByRole('heading', { name: own.name })).toBeVisible(); await expect(error(page).filter({ hasText: 'Registry version history could not be loaded' })).toBeVisible(); await expect(page.getByText('No versions recorded yet.', { exact: true })).toHaveCount(0);
        versionsFail = false; await page.getByRole('button', { name: 'Retry registry versions' }).click(); await expect(page.getByRole('cell', { name: 'Reviewed immutable version', exact: true })).toBeVisible(); await accessibleRegistry(page);
        await switchPortfolio(page, 'Main', 'Long Term'); await expect(error(page).filter({ hasText: 'Artifact belongs to a different Portfolio' })).toBeVisible(); await expect(page.getByRole('heading', { name: own.name })).toHaveCount(0); await expect(page.getByRole('button', { name: 'Export JSON', exact: true })).toBeDisabled(); await switchPortfolio(page, 'Long Term', 'Main'); await expect(page.getByRole('heading', { name: own.name })).toBeVisible();
        await page.goto('/screeners/registry/70'); await expect(page.getByRole('heading', { name: shared.name })).toBeVisible(); await expect(page.getByRole('link', { name: 'Open editor', exact: true })).toHaveCount(0); expect(sharedVersionReads).toBe(0);
        await page.getByRole('link', { name: 'Back to registry', exact: true }).click(); const row = page.getByRole('row').filter({ hasText: shared.name });
        await row.getByRole('button', { name: 'Import copy', exact: true }).click(); await expect(error(page).filter({ hasText: 'Synthetic registry unavailable' })).toBeVisible(); await expect(row).toBeVisible();
        await row.getByRole('button', { name: 'Import copy', exact: true }).click(); await expect(page).toHaveURL(/\/screeners\/501(?:[?#]|$)/);
        expect(JSON.stringify(shared)).toBe(original); expect(safety.writes).toHaveLength(2); expect(safety.blocked).toEqual([]);
    });

    test('E2E-55 recovers portable Strategy evidence and confirms only the intended enable/archive', async ({ page }, testInfo) => {
        journeyId(testInfo, 'E2E-55'); const safety = await prepare(page, viewport, 'strategy-detail', (method, path) => method === 'POST' && /^\/api\/v1\/strategy-registry\/69\/(export|activate|archive)$/.test(path));
        let current = strategy(69), listFails = true, readFails = true, versionsFail = true, exportFails = true, activateFails = true, archiveFails = true;
        const definition = JSON.stringify(current.definition), sibling = { ...strategy(70), name: 'Other enabled strategy', slug: 'other-enabled-strategy', metadata: { status: 'active', is_enabled: true } }, originalSibling = JSON.stringify(sibling);
        await page.route('**/api/v1/strategy-registry/meta', route => { const statuses = [current, sibling].map(row => row.metadata.status); return route.fulfill({ json: { data: { counts: { total: statuses.length, active: statuses.filter(s => s === 'active').length, draft: statuses.filter(s => s === 'draft').length, archived: statuses.filter(s => s === 'archived').length } } } }); });
        await page.route('**/api/v1/strategy-registry', route => listFails ? failure(route) : route.fulfill({ json: { data: [current, sibling] } }));
        await page.route('**/api/v1/strategy-registry/69', route => otherScope(route) ? scopeFailure(route) : readFails ? failure(route) : route.fulfill({ json: { data: current } }));
        await page.route('**/api/v1/strategy-registry/69/versions', route => versionsFail ? failure(route) : route.fulfill({ json: { data: [{ version: 3, status: 'draft', definition_hash: current.definition_hash, change_notes: 'Reviewed pinned sources', created_at: '2026-10-01' }] } }));
        await page.route('**/api/v1/strategy-registry/69/export', route => { if (exportFails) { exportFails = false; return failure(route); } return route.fulfill({ json: { data: current } }); });
        await page.route('**/api/v1/strategy-registry/69/activate', route => { if (activateFails) { activateFails = false; return failure(route); } current = { ...current, metadata: { ...current.metadata, status: 'active', is_enabled: true } }; return route.fulfill({ json: { data: current } }); });
        await page.route('**/api/v1/strategy-registry/69/archive', route => { if (archiveFails) { archiveFails = false; return failure(route); } current = { ...current, metadata: { ...current.metadata, status: 'archived', is_enabled: false } }; return route.fulfill({ json: { data: current } }); });
        await safety.seal(); await page.goto('/strategy/registry'); await expect(error(page).filter({ hasText: 'Strategy Registry could not be loaded' })).toBeVisible(); await expect(page.getByText('No strategies match the filters.', { exact: true })).toHaveCount(0);
        listFails = false; await page.getByRole('button', { name: 'Retry Strategy Registry' }).click(); await expect(page.getByRole('row').filter({ has: page.getByRole('link', { name: sibling.slug, exact: true }) })).toBeVisible();
        await page.goto('/strategy/registry/69'); await expect(error(page).filter({ hasText: 'Synthetic registry unavailable' })).toBeVisible(); await expect(page.getByRole('button', { name: 'Enable', exact: true })).toBeDisabled();
        readFails = false; await page.getByRole('button', { name: 'Retry registry artifact' }).click(); await expect(page.getByRole('heading', { name: current.name })).toBeVisible(); await expect(error(page).filter({ hasText: 'Registry version history could not be loaded' })).toBeVisible(); await expect(page.getByText('No versions recorded yet.', { exact: true })).toHaveCount(0);
        versionsFail = false; await page.getByRole('button', { name: 'Retry registry versions' }).click(); await expect(page.getByRole('cell', { name: 'Reviewed pinned sources', exact: true })).toBeVisible(); await accessibleRegistry(page);
        await switchPortfolio(page, 'Main', 'Long Term'); await expect(error(page).filter({ hasText: 'Artifact belongs to a different Portfolio' })).toBeVisible(); await expect(page.getByRole('heading', { name: current.name })).toHaveCount(0); await expect(page.getByRole('button', { name: 'Export JSON', exact: true })).toBeDisabled(); await switchPortfolio(page, 'Long Term', 'Main'); await expect(page.getByRole('heading', { name: current.name })).toBeVisible();
        await page.getByRole('button', { name: 'Export JSON', exact: true }).click(); await expect(error(page).filter({ hasText: 'Synthetic registry unavailable' })).toBeVisible();
        const exported = await downloadJson(page, () => page.getByRole('button', { name: 'Export JSON', exact: true }).click()); expect(exported.value).toEqual(current); expect(exported.value.definition.eligibility_sources[0]).not.toHaveProperty('screener_id');
        const enable = () => page.getByRole('button', { name: 'Enable', exact: true }).click(); await nativeAction(page, enable, 'dismiss'); expect(safety.writes.filter(w => w.path.endsWith('/activate'))).toHaveLength(0);
        await nativeAction(page, enable, 'accept'); await expect(error(page).filter({ hasText: 'Synthetic registry unavailable' })).toBeVisible(); expect(current.metadata.is_enabled).toBe(false);
        await nativeAction(page, enable, 'accept'); await expect(page.getByRole('button', { name: 'Archive', exact: true })).toBeVisible();
        const archive = () => page.getByRole('button', { name: 'Archive', exact: true }).click(); await nativeAction(page, archive, 'dismiss'); expect(safety.writes.filter(w => w.path.endsWith('/archive'))).toHaveLength(0);
        await nativeAction(page, archive, 'accept'); await expect(error(page).filter({ hasText: 'Synthetic registry unavailable' })).toBeVisible(); expect(current.metadata.is_enabled).toBe(true);
        await nativeAction(page, archive, 'accept'); await expect(page.getByText('archived · imported · not enabled', { exact: true })).toBeVisible();
        await page.getByRole('link', { name: 'Back to registry', exact: true }).click(); await expect(page.getByRole('row').filter({ has: page.getByRole('link', { name: sibling.slug, exact: true }) })).toContainText('active');
        expect(JSON.stringify(current.definition)).toBe(definition); expect(JSON.stringify(sibling)).toBe(originalSibling); expect(safety.writes).toHaveLength(6); expect(safety.blocked).toEqual([]);
    });

    test('E2E-55 validates runtime Strategy import without requiring an Artifact Library publish', async ({ page }, testInfo) => {
        journeyId(testInfo, 'E2E-55'); const safety = await prepare(page, viewport, 'strategy-import', (method, path) => method === 'POST' && ['/api/v1/strategy-registry/validate', '/api/v1/strategy-registry/import'].includes(path));
        const payload = strategy(7); delete payload.artifact_id; let validateFails = true, importFails = true;
        await page.route('**/api/v1/strategy-registry/validate', route => { expect(route.request().postDataJSON()).toEqual(payload); if (validateFails) { validateFails = false; return failure(route); } return route.fulfill({ json: { data: { ok: true, issues: [] } } }); });
        await page.route('**/api/v1/strategy-registry/import', route => { expect(route.request().postDataJSON()).toEqual(payload); if (importFails) { importFails = false; return failure(route); } return route.fulfill({ status: 201, json: { data: { ...payload, artifact_id: 7, metadata: { ...payload.metadata, status: 'draft', is_enabled: false } } } }); });
        await safety.seal(); await page.goto('/strategy/registry'); await expect(page.getByText('Import creates an account-owned runtime Strategy draft.', { exact: false })).toBeVisible();
        const input = page.getByRole('textbox', { name: 'Strategy registry JSON' }), importButton = page.getByRole('button', { name: 'Import', exact: true }); await input.fill(JSON.stringify(payload)); await expect(importButton).toBeDisabled();
        await page.getByRole('button', { name: 'Validate', exact: true }).click(); await expect(error(page).filter({ hasText: 'Synthetic registry unavailable' })).toBeVisible(); await expect(input).toHaveValue(JSON.stringify(payload)); await expect(importButton).toBeDisabled();
        await page.getByRole('button', { name: 'Validate', exact: true }).click(); await expect(importButton).toBeEnabled(); await importButton.click(); await expect(error(page).filter({ hasText: 'Synthetic registry unavailable' })).toBeVisible(); await expect(input).toHaveValue(JSON.stringify(payload));
        await importButton.click(); await expect(page).toHaveURL(/\/strategy\?strategy_id=7(?:&|$)/); expect(safety.writes).toHaveLength(4); expect(safety.blocked).toEqual([]);
    });
});
