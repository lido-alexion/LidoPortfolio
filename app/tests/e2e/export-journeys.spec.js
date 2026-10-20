import { test, expect } from '@playwright/test';
import { journeyId, seedDeterministicJourney } from './journeyTestUtils.js';
import { installInvestorWorkflowApiMocks } from './investorWorkflowApiMocks.js';
import { handleApiFailureDiagnosticForPage, registerApiFailureDiagnosticFixtureHooks } from './apiFailureDiagnosticBoundary.js';

registerApiFailureDiagnosticFixtureHooks(test);

const TOKEN = '11111111-1111-4111-8111-111111111111';
const DOWNLOAD = `/api/exports/${TOKEN}/download`;
const VIEWPORTS = [{ name: 'mobile', width: 390, height: 844 }, { name: 'desktop', width: 1440, height: 900 }];
async function prepare(page, name, writes) {
    await seedDeterministicJourney(page, `export-${name}`);
    await installInvestorWorkflowApiMocks(page);
    const rejected = [];
    const allowedOrigin = new URL(process.env.STOX_E2E_BASE_URL || 'http://127.0.0.1:4177').origin;
    return { rejected, seal: () => page.route('**/*', async route => {
        const request = route.request(); const url = new URL(request.url());
        if (await handleApiFailureDiagnosticForPage(route, page, allowedOrigin)) return;
        const allowed = ['GET', 'HEAD'].includes(request.method()) || [['POST', '/api/logs/frontend'], ...writes].some(([method, path]) => request.method() === method && url.pathname === path);
        if (url.origin !== 'http://127.0.0.1:4177' || !allowed) { rejected.push(`${request.method()} ${url.pathname}`); return route.abort(); }
        return route.fallback();
    }) };
}
async function snapshotPage(page) {
    await page.route('**/api/portfolio/snapshots*', route => route.fulfill({ json: { snapshots: [{ id: 101, snapshot_date: '2026-10-10', portfolio_value: 100000, invested_value: 90000 }], meta: { count: 1 } } }));
    await page.goto('/portfolio/snapshots');
    await expect(page.locator('.container-fluid').getByRole('heading', { name: 'Portfolio Snapshots', exact: true })).toBeVisible();
}
function prompts(page, answers) {
    page.on('dialog', async dialog => {
        expect(dialog.type()).toBe('prompt');
        expect(answers.length).toBeGreaterThan(0);
        const answer = answers.shift();
        if (answer === null) await dialog.dismiss(); else await dialog.accept(answer);
    });
}

for (const viewport of VIEWPORTS) {
    test.describe(`Data export ${viewport.name}`, () => {
        test.use({ hasTouch: viewport.name === 'mobile' });
        test(`E2E-18 refuses invalid fields, retries generation/status and downloads only a ready export (${viewport.name})`, async ({ page }, testInfo) => {
            journeyId(testInfo, 'E2E-18');
            const safety = await prepare(page, `${viewport.name}-ready`, [['POST', '/api/exports']]);
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            const answers = [null, 'csv', 'full', 'unsupported_field', 'csv', 'current', 'snapshot_date,portfolio_value', 'csv', 'current', 'snapshot_date,portfolio_value'];
            prompts(page, answers);
            let creates = 0; let reads = 0;
            await page.route('**/api/exports', route => {
                creates++;
                expect(route.request().postDataJSON()).toEqual({ dataset: 'portfolio-snapshots', format: 'csv', scope: 'current', fields: ['snapshot_date', 'portfolio_value'], filters: { range: '365d', sort_by: 'snapshot_date', sort_direction: 'desc' } });
                if (creates === 1) return route.fulfill({ status: 503, json: { message: 'Synthetic export unavailable.' } });
                return route.fulfill({ status: 202, json: { data: { token: TOKEN, status: 'queued', download_url: DOWNLOAD, expires_at: null } } });
            });
            await page.route(`**/api/exports/${TOKEN}`, route => {
                reads++;
                if (reads === 1) return route.fulfill({ status: 503, json: { message: 'Synthetic status unavailable.' } });
                return route.fulfill({ json: { data: { token: TOKEN, status: 'ready', download_url: DOWNLOAD, expires_at: '2099-01-01T00:00:00Z' } } });
            });
            let downloads = 0;
            await page.route(`**${DOWNLOAD}`, route => {
                downloads++;
                return route.fulfill({ contentType: 'text/csv', headers: { 'content-disposition': 'attachment; filename="synthetic-export.csv"' }, body: 'snapshot_date,portfolio_value\n2026-10-10,100000\n' });
            });
            await safety.seal(); await snapshotPage(page);
            const exportButton = page.getByRole('button', { name: 'Export table data', exact: true });
            await exportButton.click(); expect(creates).toBe(0);
            await exportButton.click(); await expect(page.getByText('Choose one or more fields from the available list.', { exact: true })).toBeVisible(); expect(creates).toBe(0);
            await exportButton.click(); await expect(page.getByText('Synthetic export unavailable.', { exact: true })).toBeVisible();
            await exportButton.click();
            const job = page.getByRole('region', { name: 'Latest export' });
            await expect(job.getByRole('status')).toContainText('Export is queued.');
            await expect(job.getByRole('link', { name: 'Download export' })).toHaveCount(0); expect(downloads).toBe(0);
            await job.getByRole('button', { name: 'Refresh export status' }).click();
            await expect(job.getByRole('alert')).toContainText('Please retry.');
            await job.getByRole('button', { name: 'Refresh export status' }).click();
            await expect(job.getByRole('link', { name: 'Download export' })).toBeVisible();
            const downloadEvent = page.waitForEvent('download');
            await job.getByRole('link', { name: 'Download export' }).click();
            expect((await downloadEvent).suggestedFilename()).toBe('synthetic-export.csv');
            expect(downloads).toBe(1); expect(creates).toBe(2); expect(answers).toEqual([]); expect(safety.rejected).toEqual([]);
        });

        test(`E2E-18 retries cancellation and withholds cancelled or expired downloads (${viewport.name})`, async ({ page }, testInfo) => {
            journeyId(testInfo, 'E2E-18');
            const safety = await prepare(page, `${viewport.name}-cancel`, [['POST', '/api/exports'], ['POST', `/api/exports/${TOKEN}/cancel`]]);
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            prompts(page, ['csv', 'full', 'snapshot_date']);
            let cancelled = false; let attempts = 0; let expired = false;
            await page.route('**/api/exports', route => route.fulfill({ status: 202, json: { data: { token: TOKEN, status: 'running', expires_at: null, download_url: DOWNLOAD } } }));
            await page.route(`**/api/exports/${TOKEN}/cancel`, route => {
                attempts++;
                if (attempts === 1) return route.fulfill({ status: 503, json: { message: 'Synthetic cancellation unavailable.' } });
                cancelled = true; return route.fulfill({ json: { data: { status: 'cancelled' } } });
            });
            await page.route(`**/api/exports/${TOKEN}`, route => route.fulfill({ json: { data: { token: TOKEN, status: expired ? 'ready' : cancelled ? 'cancelled' : 'running', expires_at: expired ? '2000-01-01T00:00:00Z' : null, download_url: expired ? DOWNLOAD : null } } }));
            let downloads = 0; await page.route(`**${DOWNLOAD}`, route => { downloads++; return route.abort(); });
            await safety.seal(); await snapshotPage(page); await page.getByRole('button', { name: 'Export table data', exact: true }).click();
            const job = page.getByRole('region', { name: 'Latest export' });
            await job.getByRole('button', { name: 'Cancel export' }).click(); await expect(job.getByRole('alert')).toContainText('could not be confirmed');
            await expect(job.getByRole('status')).toContainText('being prepared');
            await job.getByRole('button', { name: 'Cancel export' }).click(); await expect(job.getByRole('status')).toContainText('Export cancelled.');
            await expect(job.getByRole('link', { name: 'Download export' })).toHaveCount(0);
            expired = true; await job.getByRole('button', { name: 'Refresh export status' }).click();
            await expect(job.getByRole('status')).toContainText('no longer downloadable');
            await expect(job.getByRole('link', { name: 'Download export' })).toHaveCount(0);
            expect(attempts).toBe(2); expect(downloads).toBe(0); expect(safety.rejected).toEqual([]);
        });

        test(`E2E-19 preserves basket recovery, validates selection and exports two fresh dataset configurations (${viewport.name})`, async ({ page }, testInfo) => {
            journeyId(testInfo, 'E2E-19');
            const safety = await prepare(page, `${viewport.name}-basket`, [['PUT', '/api/exports/basket'], ['POST', '/api/exports/basket/export']]);
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            const fields = ['snapshot_date', 'portfolio_value', 'invested_value'];
            const catalog = [{ id: 'portfolio-snapshots', label: 'Daily snapshots', fields, scopes: ['full', 'current', 'selected'] }, { id: 'portfolio-growth', label: 'Growth chart', fields, scopes: ['full', 'current'] }];
            let items = []; let failSave = true; let saves = 0; let failExport = true; let exports = 0;
            const cloneJson = value => JSON.parse(JSON.stringify(value));
            const filters = { range: '90d', sort_by: 'snapshot_date', sort_direction: 'asc' };
            const initialSnapshot = { dataset: 'portfolio-snapshots', sheet_name: 'portfolio-snapshots', fields, scope: 'full', selected: [], filters };
            const evidenceSnapshot = { dataset: 'portfolio-snapshots', sheet_name: 'Evidence', fields: ['snapshot_date', 'portfolio_value'], scope: 'selected', selected: ['101'], filters };
            const correctedSnapshot = { ...evidenceSnapshot, selected: ['102'] };
            const growthItem = { dataset: 'portfolio-growth', sheet_name: 'portfolio-growth', fields, scope: 'full', selected: [], filters };
            const basketWritePlan = [
                { phase: 'initial-add-failure', items: [initialSnapshot], status: 503 },
                { phase: 'initial-add-retry', items: [initialSnapshot], status: 200 },
                { phase: 'evidence-save-failure', items: [evidenceSnapshot], status: 503 },
                { phase: 'evidence-save', items: [evidenceSnapshot], status: 200 },
                { phase: 'add-growth', items: [evidenceSnapshot, growthItem], status: 200 },
                { phase: 'rejected-export-preflight-save', items: [evidenceSnapshot, growthItem], status: 200 },
                { phase: 'explicit-correction-save', items: [correctedSnapshot, growthItem], status: 200 },
                { phase: 'retry-export-preflight-save', items: [correctedSnapshot, growthItem], status: 200 },
            ];
            const exportWritePlan = [
                { phase: 'stale-export-rejection', items: [evidenceSnapshot, growthItem], status: 422, body: { message: 'Synthetic stale basket; review and retry.' } },
                { phase: 'corrected-export-retry', items: [correctedSnapshot, growthItem], status: 202, body: { data: { token: TOKEN, status: 'queued', download_url: DOWNLOAD, expires_at: null } } },
            ];
            const mutationReceipts = []; const mutationOrder = [];
            const assertTerminalMutation = async (request, { phase, method, path, status, body }) => {
                expect(request.method()).toBe(method);
                const url = new URL(request.url());
                expect(url.origin).toBe(new URL(process.env.STOX_E2E_BASE_URL || 'http://127.0.0.1:4177').origin);
                expect(url.username).toBe(''); expect(url.password).toBe('');
                expect(url.pathname).toBe(path); expect(url.search).toBe(''); expect(url.hash).toBe('');
                const response = await request.response();
                expect(response).not.toBeNull(); expect(response.status()).toBe(status);
                expect(await response.json()).toEqual(body);
                expect(await response.finished()).toBeNull();
                mutationReceipts.push({ phase, method, path, status, requestBody: request.postData() === null ? null : cloneJson(request.postDataJSON()), responseBody: cloneJson(body) });
            };
            await page.route('**/api/exports/catalog', route => route.fulfill({ json: { data: catalog } }));
            await page.route('**/api/exports/basket', async route => {
                if (route.request().method() === 'GET') return route.fulfill({ json: { data: { items } } });
                const request = route.request(); const plan = basketWritePlan[saves++];
                expect(plan).toBeDefined();
                expect(request.postDataJSON()).toEqual({ items: plan.items });
                mutationOrder.push(`basket-put:${plan.phase}`);
                let status = 200; let body;
                if (failSave) { failSave = false; status = 503; body = { message: 'Synthetic basket save unavailable.' }; }
                else { items = cloneJson(plan.items); body = { data: { items: cloneJson(items) } }; }
                expect(status).toBe(plan.status);
                await route.fulfill({ status, json: body });
                await assertTerminalMutation(request, { phase: plan.phase, method: 'PUT', path: '/api/exports/basket', status, body });
            });
            await page.route('**/api/exports/basket/export', async route => {
                const request = route.request(); const plan = exportWritePlan[exports++];
                expect(plan).toBeDefined(); expect(items).toHaveLength(2); expect(items).toEqual(plan.items);
                expect(items[0]).toMatchObject({ dataset: 'portfolio-snapshots', sheet_name: 'Evidence', scope: 'selected', selected: exports === 1 ? ['101'] : ['102'], fields: ['snapshot_date', 'portfolio_value'] });
                expect(request.postData()).toBeNull(); mutationOrder.push(`basket-export:${plan.phase}`);
                expect(failExport).toBe(plan.status === 422);
                if (failExport) failExport = false;
                await route.fulfill({ status: plan.status, json: plan.body });
                await assertTerminalMutation(request, { phase: plan.phase, method: 'POST', path: '/api/exports/basket/export', status: plan.status, body: plan.body });
            });
            await page.route(`**/api/exports/${TOKEN}`, route => route.fulfill({ json: { data: { token: TOKEN, status: 'ready', download_url: DOWNLOAD, expires_at: '2099-01-01T00:00:00Z' } } }));
            let downloads = 0;
            await page.route(`**${DOWNLOAD}`, route => { downloads++; return route.fulfill({ contentType: 'application/octet-stream', headers: { 'content-disposition': 'attachment; filename="synthetic-basket.xlsx"' }, body: 'SYNTHETIC_DOWNLOAD_ROUTE_FIXTURE_NOT_WORKBOOK_QUALIFICATION' }); });
            await page.route('**/api/dashboard', route => route.fulfill({ json: { portfolio_value: 100000, invested_value: 90000, total_gain_loss: 10000, portfolio_growth: [], alerts: [] } }));
            await safety.seal(); await page.goto('/'); await page.getByRole('button', { name: 'Export basket (0)' }).click();
            await page.getByRole('button', { name: 'Add Daily snapshots' }).click(); await expect(page.getByText('Synthetic basket save unavailable.', { exact: true })).toBeVisible();
            await expect(page.getByRole('button', { name: 'Export basket (0)' })).toBeVisible();
            await page.getByRole('button', { name: 'Add Daily snapshots' }).click();
            const config = page.getByRole('region', { name: 'Configuration for Daily snapshots' }); await expect(config).toBeVisible();
            await config.getByLabel('Scope', { exact: true }).selectOption('selected'); const beforeInvalidSave = saves;
            await config.getByRole('button', { name: 'Save item configuration' }).click(); await expect(config.getByRole('alert')).toContainText('at least one stable row ID'); expect(saves).toBe(beforeInvalidSave);
            await config.getByLabel('Stable selected row IDs for portfolio-snapshots').fill('101');
            await config.getByLabel('Sheet name for portfolio-snapshots').fill('Evidence'); await config.getByRole('checkbox', { name: 'Include invested_value in Daily snapshots' }).uncheck();
            failSave = true; await config.getByRole('button', { name: 'Save item configuration' }).click();
            await expect(config.getByLabel('Sheet name for portfolio-snapshots')).toHaveValue('Evidence');
            await config.getByRole('button', { name: 'Save item configuration' }).click(); await expect.poll(() => items[0]?.sheet_name).toBe('Evidence');
            await page.getByRole('button', { name: 'Add Growth chart' }).click(); await expect(page.getByRole('button', { name: 'Export basket (2)' })).toBeVisible();
            await page.reload(); await page.getByRole('button', { name: 'Export basket (0)' }).click(); await expect(config.getByLabel('Sheet name for portfolio-snapshots')).toHaveValue('Evidence');
            await expect(config.getByLabel('Stable selected row IDs for portfolio-snapshots')).toHaveValue('101');
            await page.getByRole('button', { name: 'Export XLSX', exact: true }).click(); await expect(page.getByText('Synthetic stale basket; review and retry.', { exact: true })).toBeVisible();
            expect(exports).toBe(1); expect(downloads).toBe(0); expect(items[0].selected).toEqual(['101']);
            await expect(page.getByRole('region', { name: 'Latest export' })).toHaveCount(0);
            await expect(config.getByLabel('Stable selected row IDs for portfolio-snapshots')).toHaveValue('101');
            await config.getByLabel('Stable selected row IDs for portfolio-snapshots').fill('102');
            await config.getByRole('button', { name: 'Save item configuration' }).click();
            await expect.poll(() => items[0]?.selected).toEqual(['102']);
            expect(items[1]).toEqual(growthItem);
            await expect(config.getByLabel('Stable selected row IDs for portfolio-snapshots')).toHaveValue('102');
            await page.getByRole('button', { name: 'Export XLSX', exact: true }).click(); const job = page.getByRole('region', { name: 'Latest export' });
            await expect(job.getByRole('status')).toContainText('Export is queued.'); await expect(job.getByRole('link', { name: 'Download export' })).toHaveCount(0);
            await job.getByRole('button', { name: 'Refresh export status' }).click(); const downloadEvent = page.waitForEvent('download'); await job.getByRole('link', { name: 'Download export' }).click();
            expect((await downloadEvent).suggestedFilename()).toBe('synthetic-basket.xlsx'); expect(downloads).toBe(1); expect(exports).toBe(2); expect(saves).toBe(8);
            expect(mutationReceipts).toHaveLength(10);
            expect(mutationReceipts.find(receipt => receipt.phase === 'rejected-export-preflight-save').requestBody.items[0].selected).toEqual(['101']);
            expect(mutationReceipts.find(receipt => receipt.phase === 'explicit-correction-save').requestBody.items[0].selected).toEqual(['102']);
            expect(mutationReceipts.find(receipt => receipt.phase === 'retry-export-preflight-save').requestBody.items[0].selected).toEqual(['102']);
            expect(mutationReceipts.find(receipt => receipt.phase === 'stale-export-rejection').status).toBe(422);
            expect(mutationReceipts.find(receipt => receipt.phase === 'corrected-export-retry').status).toBe(202);
            expect(mutationOrder.slice(-5)).toEqual(['basket-put:rejected-export-preflight-save', 'basket-export:stale-export-rejection', 'basket-put:explicit-correction-save', 'basket-put:retry-export-preflight-save', 'basket-export:corrected-export-retry']);
            expect(safety.rejected).toEqual([]);
        });
    });
}
