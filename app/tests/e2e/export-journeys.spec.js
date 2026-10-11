import { test, expect } from '@playwright/test';
import { journeyId, seedDeterministicJourney } from './journeyTestUtils.js';
import { installInvestorWorkflowApiMocks } from './investorWorkflowApiMocks.js';

const TOKEN = '11111111-1111-4111-8111-111111111111';
const DOWNLOAD = `/api/exports/${TOKEN}/download`;
const VIEWPORTS = [{ name: 'mobile', width: 390, height: 844 }, { name: 'desktop', width: 1440, height: 900 }];
async function prepare(page, name, writes) {
    await seedDeterministicJourney(page, `export-${name}`);
    await installInvestorWorkflowApiMocks(page);
    const rejected = [];
    return { rejected, seal: () => page.route('**/*', route => {
        const request = route.request(); const url = new URL(request.url());
        const allowed = ['GET', 'HEAD'].includes(request.method()) || [['POST', '/api/logs/frontend'], ...writes].some(([method, path]) => request.method() === method && url.pathname === path);
        if (url.origin !== 'http://127.0.0.1:4177' || !allowed) { rejected.push(`${request.method()} ${url.pathname}`); return route.abort(); }
        return route.fallback();
    }) };
}
async function snapshotPage(page) {
    await page.route('**/api/portfolio/snapshots*', route => route.fulfill({ json: { snapshots: [{ id: 101, snapshot_date: '2026-10-10', portfolio_value: 100000, invested_value: 90000 }], meta: { count: 1 } } }));
    await page.goto('/portfolio/snapshots');
    await expect(page.getByRole('heading', { name: 'Portfolio Snapshots', exact: true })).toBeVisible();
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
            await page.route('**/api/exports/catalog', route => route.fulfill({ json: { data: catalog } }));
            await page.route('**/api/exports/basket', route => {
                if (route.request().method() === 'GET') return route.fulfill({ json: { data: { items } } });
                saves++;
                if (failSave) { failSave = false; return route.fulfill({ status: 503, json: { message: 'Synthetic basket save unavailable.' } }); }
                items = route.request().postDataJSON().items; return route.fulfill({ json: { data: { items } } });
            });
            await page.route('**/api/exports/basket/export', route => {
                exports++; expect(items).toHaveLength(2); expect(items[0]).toMatchObject({ dataset: 'portfolio-snapshots', sheet_name: 'Evidence', scope: 'selected', selected: ['101'], fields: ['snapshot_date', 'portfolio_value'] });
                if (failExport) { failExport = false; return route.fulfill({ status: 422, json: { message: 'Synthetic stale basket; review and retry.' } }); }
                return route.fulfill({ status: 202, json: { data: { token: TOKEN, status: 'queued', download_url: DOWNLOAD, expires_at: null } } });
            });
            await page.route(`**/api/exports/${TOKEN}`, route => route.fulfill({ json: { data: { token: TOKEN, status: 'ready', download_url: DOWNLOAD, expires_at: '2099-01-01T00:00:00Z' } } }));
            await page.route(`**${DOWNLOAD}`, route => route.fulfill({ contentType: 'application/octet-stream', headers: { 'content-disposition': 'attachment; filename="synthetic-basket.xlsx"' }, body: 'SYNTHETIC_DOWNLOAD_ROUTE_FIXTURE_NOT_WORKBOOK_QUALIFICATION' }));
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
            await page.getByRole('button', { name: 'Export XLSX', exact: true }).click(); await expect(page.getByText('Synthetic stale basket; review and retry.', { exact: true })).toBeVisible();
            await page.getByRole('button', { name: 'Export XLSX', exact: true }).click(); const job = page.getByRole('region', { name: 'Latest export' });
            await expect(job.getByRole('status')).toContainText('Export is queued.'); await expect(job.getByRole('link', { name: 'Download export' })).toHaveCount(0);
            await job.getByRole('button', { name: 'Refresh export status' }).click(); const downloadEvent = page.waitForEvent('download'); await job.getByRole('link', { name: 'Download export' }).click();
            expect((await downloadEvent).suggestedFilename()).toBe('synthetic-basket.xlsx'); expect(exports).toBe(2); expect(safety.rejected).toEqual([]);
        });
    });
}
