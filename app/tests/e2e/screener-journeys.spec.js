import { test, expect } from '@playwright/test';
import { journeyId, seedDeterministicJourney } from './journeyTestUtils.js';
import { installInvestorWorkflowApiMocks } from './investorWorkflowApiMocks.js';

const VIEWPORTS = [
    { name: 'mobile', width: 390, height: 844 },
    { name: 'desktop', width: 1440, height: 900 },
];

test.describe('V9-UX-001 screener journeys', () => {
    for (const viewport of VIEWPORTS) {
        test(`SCR-01 creates and saves Close > SMA(200) (${viewport.name})`, async ({ page }, testInfo) => {
            journeyId(testInfo, 'SCR-01');
            await seedDeterministicJourney(page, `screener-scr01-${viewport.name}`);
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            await installInvestorWorkflowApiMocks(page);
            await page.goto('/screeners/new');

            await expect(page.getByRole('heading', { name: 'New screener' })).toBeVisible();
            await page.getByLabel('Name').fill('Price Above MA200');
            await page.getByLabel('left indicator').selectOption('close');
            await page.getByLabel('right indicator').selectOption('sma');
            await page.getByLabel('Period').fill('200');

            const saveRequest = page.waitForRequest((request) => (
                request.method() === 'POST' && new URL(request.url()).pathname.endsWith('/api/screeners')
            ));
            await page.getByRole('button', { name: 'Save' }).click();
            const request = await saveRequest;
            const payload = request.postDataJSON();

            expect(payload.name).toBe('Price Above MA200');
            expect(payload.definition_json.root.op).toBe('AND');
            expect(payload.definition_json.root.children).toHaveLength(1);
            expect(payload.definition_json.root.children[0]).toMatchObject({
                type: 'condition',
                left: { indicator: 'close' },
                operator: 'gt',
                right: { indicator: 'sma', params: { period: 200 } },
            });
            await expect(page).toHaveURL(/\/screeners\/\d+$/);
            await expect(page.getByRole('heading', { name: 'Edit screener' })).toBeVisible();
        });

        test(`SCR-02 saves Price > SMA(200) AND RSI(14) < 70 (${viewport.name})`, async ({ page }, testInfo) => {
            journeyId(testInfo, 'SCR-02');
            await seedDeterministicJourney(page, `screener-scr02-${viewport.name}`);
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            await installInvestorWorkflowApiMocks(page);
            await page.goto('/screeners/new');
            await expect(page.getByRole('heading', { name: 'New screener' })).toBeVisible();

            await page.getByLabel('Name').fill('Momentum Entry — MA200 + RSI');
            const leaves = page.locator('.lido-screener-leaf');
            const first = leaves.nth(0);
            await first.getByLabel('left indicator').selectOption('close');
            await first.getByLabel('right indicator').selectOption('sma');
            await first.getByLabel('Period').fill('200');

            await page.getByRole('button', { name: '+ Condition' }).click();
            const second = leaves.nth(1);
            await second.getByLabel('left indicator').selectOption('rsi');
            await second.getByLabel('Period').fill('14');
            await second.getByLabel('Comparator').selectOption('lt');
            await second.getByLabel('right constant').fill('70');

            const saveRequest = page.waitForRequest((request) => (
                request.method() === 'POST' && new URL(request.url()).pathname.endsWith('/api/screeners')
            ));
            await page.getByRole('button', { name: 'Save' }).click();
            const payload = (await saveRequest).postDataJSON();
            expect(payload.name).toBe('Momentum Entry — MA200 + RSI');
            expect(payload.definition_json.root).toMatchObject({ type: 'group', op: 'AND' });
            expect(payload.definition_json.root.children).toHaveLength(2);
            expect(payload.definition_json.root.children[0]).toMatchObject({
                type: 'condition', left: { indicator: 'close' }, operator: 'gt',
                right: { indicator: 'sma', params: { period: 200 } },
            });
            expect(payload.definition_json.root.children[1]).toMatchObject({
                type: 'condition', left: { indicator: 'rsi', params: { period: 14 } }, operator: 'lt',
                right: { type: 'constant', value: 70 },
            });
            await expect(page).toHaveURL(/\/screeners\/\d+$/);
        });



        test(`SCR-03 saves an AND group with nested momentum alternatives (${viewport.name})`, async ({ page }, testInfo) => {
            journeyId(testInfo, 'SCR-03');
            await seedDeterministicJourney(page, `screener-scr03-${viewport.name}`);
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            await installInvestorWorkflowApiMocks(page);
            await page.goto('/screeners/new');
            await page.getByLabel('Name').fill('Trend with Momentum Alternatives');

            const groups = page.locator('.lido-screener-group');
            const leaves = page.locator('.lido-screener-leaf');
            const trend = leaves.nth(0);
            await trend.getByLabel('left indicator').selectOption('close');
            await trend.getByLabel('right indicator').selectOption('sma');
            await trend.getByLabel('Period').fill('200');
            await groups.nth(0).getByRole('button', { name: '+ Group' }).click();

            const alternatives = groups.nth(1);
            const rsi = leaves.nth(1);
            await rsi.getByLabel('left indicator').selectOption('rsi');
            await rsi.getByLabel('Comparator').selectOption('lt');
            await rsi.getByLabel('right constant').fill('70');
            await alternatives.getByRole('button', { name: '+ Condition' }).click();

            const roc = leaves.nth(2);
            await roc.getByLabel('left indicator').selectOption('roc');
            await roc.getByLabel('Comparator').selectOption('gt');
            await roc.getByLabel('right constant').fill('0');

            const saveRequest = page.waitForRequest((request) => (
                request.method() === 'POST' && new URL(request.url()).pathname.endsWith('/api/screeners')
            ));
            await page.getByRole('button', { name: 'Save' }).click();
            const payload = (await saveRequest).postDataJSON();
            const root = payload.definition_json.root;
            expect(root).toMatchObject({ type: 'group', op: 'AND' });
            expect(root.children).toHaveLength(2);
            expect(root.children[0]).toMatchObject({
                type: 'condition', left: { indicator: 'close' }, operator: 'gt',
                right: { indicator: 'sma', params: { period: 200 } },
            });
            expect(root.children[1]).toMatchObject({ type: 'group', op: 'OR' });
            expect(root.children[1].children).toHaveLength(2);
            expect(root.children[1].children[0]).toMatchObject({
                type: 'condition', left: { indicator: 'rsi', params: { period: 14 } }, operator: 'lt',
                right: { type: 'constant', value: 70 },
            });
            expect(root.children[1].children[1]).toMatchObject({
                type: 'condition', left: { indicator: 'roc', params: { period: 12 } }, operator: 'gt',
                right: { type: 'constant', value: 0 },
            });
        });
        test(`SCR-04 edits the selected screener and saves a revised rule (${viewport.name})`, async ({ page }, testInfo) => {
            journeyId(testInfo, 'SCR-04');
            await seedDeterministicJourney(page, `screener-scr04-${viewport.name}`);
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            await installInvestorWorkflowApiMocks(page, {
                initialScreeners: [{
                    id: 41,
                    name: 'Quarterly Price Gate',
                    scope: 'holdings',
                    is_enabled: true,
                    definition_json: { root: { type: 'group', op: 'AND', children: [{
                        type: 'condition', left: { indicator: 'close', params: {} }, operator: 'gt',
                        weight_factor: 1, right: { type: 'constant', value: 0 },
                    }] } },
                    description: 'Starting rule for editing.',
                    watchlist_id: null,
                    index_symbol: null,
                }],
            });
            await page.goto('/screeners');
            await page.getByRole('link', { name: 'Quarterly Price Gate' }).click();
            await expect(page.getByRole('heading', { name: 'Edit screener' })).toBeVisible();

            const condition = page.locator('.lido-screener-leaf').nth(0);
            await condition.getByRole('button', { name: 'Indicator' }).nth(1).click();
            await condition.getByLabel('right indicator').selectOption('sma');
            await condition.getByLabel('Period').fill('200');

            const updateRequest = page.waitForRequest((request) => (
                request.method() === 'PUT' && new URL(request.url()).pathname.endsWith('/api/screeners/41')
            ));
            await page.getByRole('button', { name: 'Save' }).click();
            const payload = (await updateRequest).postDataJSON();
            expect(payload.name).toBe('Quarterly Price Gate');
            expect(payload.definition_json.root.children[0]).toMatchObject({
                type: 'condition', left: { indicator: 'close' }, operator: 'gt',
                right: { indicator: 'sma', params: { period: 200 } },
            });
            await expect(page).toHaveURL(/\/screeners\/41$/);
            await expect(page.getByText('Screener "Quarterly Price Gate" updated successfully.')).toBeVisible();
        });
        test(`SCR-05 reports an invalid parameter and recovers after correction (${viewport.name})`, async ({ page }, testInfo) => {
            journeyId(testInfo, 'SCR-05');
            await seedDeterministicJourney(page, `screener-scr05-${viewport.name}`);
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            await installInvestorWorkflowApiMocks(page, { invalidScreenerAttempts: 1 });
            await page.goto('/screeners/new');
            await page.getByLabel('Name').fill('SMA Parameter Recovery');
            await page.getByLabel('left indicator').selectOption('close');
            await page.getByLabel('right indicator').selectOption('sma');
            await page.getByLabel('Period').fill('401');

            const invalidResponse = page.waitForResponse((response) => (
                response.request().method() === 'POST'
                && new URL(response.url()).pathname.endsWith('/api/screeners')
            ));
            await page.getByRole('button', { name: 'Save' }).click();
            expect((await invalidResponse).status()).toBe(422);
            await expect(page.getByText('Param period out of range for sma.')).toBeVisible();
            await expect(page).toHaveURL(/\/screeners\/new$/);

            await page.getByLabel('Period').fill('200');
            const validRequest = page.waitForRequest((request) => (
                request.method() === 'POST' && new URL(request.url()).pathname.endsWith('/api/screeners')
            ));
            await page.getByRole('button', { name: 'Save' }).click();
            const payload = (await validRequest).postDataJSON();
            expect(payload.definition_json.root.children[0].right).toMatchObject({
                indicator: 'sma', params: { period: 200 },
            });
            await expect(page).toHaveURL(/\/screeners\/\d+$/);
        });
        test('SCR-06 runs a screener and inspects matching evidence (' + viewport.name + ')', async ({ page }, testInfo) => {
            journeyId(testInfo, 'SCR-06');
            await seedDeterministicJourney(page, 'screener-scr06-' + viewport.name);
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            await installInvestorWorkflowApiMocks(page, {
                initialScreeners: [{ id: 41, name: 'Price Above MA200', scope: 'holdings', is_enabled: true,
                    definition_json: { root: { type: 'group', op: 'AND', children: [{
                        type: 'condition', left: { indicator: 'close', params: {} }, operator: 'gt',
                        weight_factor: 1, right: { indicator: 'sma', params: { period: 200 } },
                    }] } }, description: 'Deterministic run fixture.', watchlist_id: null, index_symbol: null }],
                screenerRun: {
                    id: 501, screener_id: 41, status: 'completed', triggered_by: 'manual',
                    started_at: '2026-10-09T09:00:00Z', finished_at: '2026-10-09T09:00:02Z',
                    progress_pct: 100, error_message: null,
                    stats: { scanned: 8, matched: 1, skipped_insufficient_data: 2, errors: 0, warnings: [] },
                    hits: { data: [{ id: 601, symbol: 'TCS', exchange: 'NSE', name: 'Tata Consultancy Services',
                        metrics: [{ left: 'Close', left_value: 3520, operator: 'gt', weight_factor: 1, right: 'SMA(200)' }] }],
                        current_page: 1, last_page: 1, total: 1 },
                },
            });
            const observedRequests = [];
            page.on('request', (request) => observedRequests.push(new URL(request.url()).pathname));
            await page.goto('/screeners/41');
            const runRequest = page.waitForRequest((request) => (
                request.method() === 'POST' && new URL(request.url()).pathname.endsWith('/api/screeners/41/run')
            ));
            await page.getByRole('button', { name: 'Run now' }).click();
            await runRequest;
            await expect(page).toHaveURL(/\/screeners\/41\?run=501$/);
            await expect(page.getByRole('heading', { name: /Results for Run ID 501 · Manual · completed/ })).toBeVisible();
            await expect(page.getByText('1 matched · 8 scanned · 2 skipped').first()).toBeVisible();
            await expect(page.getByRole('link', { name: /TCS/ })).toBeVisible();
            await expect(page.getByText('Tata Consultancy Services')).toBeVisible();
            await expect(page.getByText('Close=3520.00 > SMA(200)')).toBeVisible();
            await expect(page.getByRole('button', { name: /Run ID 501 · Manual · completed/ })).toBeVisible();
            expect(observedRequests.some((path) => /\/orders(?:\/|$)/.test(path))).toBe(false);
        });
        test('SCR-07 imports a shared screener as an active-portfolio copy (' + viewport.name + ')', async ({ page }, testInfo) => {
            journeyId(testInfo, 'SCR-07');
            await seedDeterministicJourney(page, 'screener-scr07-' + viewport.name);
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            await installInvestorWorkflowApiMocks(page, {
                sharedScreeners: [{
                    id: 17, name: 'Shared Momentum Gate', scope: 'holdings', is_enabled: true, is_shared: true,
                    definition_json: { root: { type: 'group', op: 'AND', children: [{
                        type: 'condition', left: { indicator: 'close', params: {} }, operator: 'gt',
                        weight_factor: 1, right: { indicator: 'sma', params: { period: 100 } },
                    }] } }, description: 'Shared factory screen for the active portfolio.',
                    watchlist_id: null, index_symbol: null,
                }],
            });
            await page.goto('/screeners?tab=shared');
            await expect(page.getByRole('heading', { name: 'Shared screens' })).toBeVisible();
            await expect(page.getByText('Shared Momentum Gate')).toBeVisible();
            const importResponse = page.waitForResponse((response) => (
                response.request().method() === 'POST'
                && new URL(response.url()).pathname.endsWith('/api/screeners/shared/17/import')
            ));
            await page.getByRole('button', { name: 'Import' }).click();
            const response = await importResponse;
            expect(response.status()).toBe(201);
            expect((await response.json()).data).toMatchObject({
                id: 99, name: 'Shared Momentum Gate', scope: 'holdings', is_shared: false,
            });
            await expect(page).toHaveURL(/\/screeners\/99$/);
            await expect(page.getByRole('heading', { name: 'Edit screener' })).toBeVisible();
            await expect(page.getByLabel('Name')).toHaveValue('Shared Momentum Gate');
            await expect(page.getByLabel('right indicator')).toHaveValue('sma');
            await expect(page.getByLabel('Period')).toHaveValue('100');
        });
        test('SCR-08 archives an unused reusable screener while preserving its published version (' + viewport.name + ')', async ({ page }, testInfo) => {
            journeyId(testInfo, 'SCR-08');
            await seedDeterministicJourney(page, 'screener-scr08-' + viewport.name);
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            const artifactUuid = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
            await installInvestorWorkflowApiMocks(page, {
                reusableScreenerArtifact: {
                    artifact_uuid: artifactUuid, type: 'screener', slug: 'unused-momentum-gate',
                    name: 'Unused Momentum Gate', origin: 'user', permission: 'owner', archived_at: null,
                    latest_published_version: '1.0.0', draft_version: null, portfolio_binding: null,
                    versions: [{ id: 301, semver: '1.0.0', status: 'published', definition_hash: 'sha256-fixture',
                        content: { artifact_type: 'screener', definition_json: { root: { type: 'group', op: 'AND', children: [] } } },
                        documentation: {}, change_summary: 'Initial published screen', lock_version: 1,
                        dependencies: [] }],
                },
            });
            let archiveConfirmed = false;
            page.on('dialog', async (dialog) => { archiveConfirmed = true; await dialog.accept(); });
            await page.goto('/artifact-library/' + artifactUuid);
            await expect(page.getByRole('heading', { name: 'Unused Momentum Gate' })).toBeVisible();
            await expect(page.getByText('This artifact is not bound to the active Portfolio.')).toBeVisible();
            await expect(page.getByText('1.0.0').first()).toBeVisible();
            const archiveRequest = page.waitForRequest((request) => (
                request.method() === 'POST' && new URL(request.url()).pathname.endsWith('/api/v1/artifact-library/' + artifactUuid + '/archive')
            ));
            await page.getByRole('button', { name: 'Archive artifact' }).click();
            await archiveRequest;
            expect(archiveConfirmed).toBe(true);
            await expect(page.getByRole('button', { name: 'Archive artifact' })).toHaveCount(0);
            await expect(page.getByRole('heading', { name: /1.0.0/ })).toBeVisible();
            await expect(page.getByText('published', { exact: true })).toBeVisible();
        });
    }
});
