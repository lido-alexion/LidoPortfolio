import { test, expect } from '@playwright/test';
import { journeyId, seedDeterministicJourney } from './journeyTestUtils.js';
import { installInvestorWorkflowApiMocks } from './investorWorkflowApiMocks.js';

const VIEWPORTS = [
    { name: 'mobile', width: 390, height: 844 },
    { name: 'desktop', width: 1440, height: 900 },
];

const DEPENDENCY_MESSAGE = 'An active Strategy still uses this screener. Update or archive that Strategy before archiving the screener.';
const DEFINITION = {
    root: {
        type: 'group', op: 'AND', children: [{
            type: 'condition', left: { indicator: 'close', params: {} }, operator: 'gt',
            weight_factor: 1, right: { indicator: 'sma', params: { period: 20 } },
        }],
    },
};

function runFixture(screenerId, runId = 702) {
    return {
        id: runId,
        screener_id: screenerId,
        screener_version_id: 120 + screenerId,
        reusable_artifact_version_id: null,
        artifact_binding_revision_id: null,
        triggered_by: 'manual',
        status: 'completed',
        started_at: '2026-10-18T09:00:00Z',
        finished_at: '2026-10-18T09:00:04Z',
        stats: {
            scanned: 8, matched: 1, skipped_insufficient_data: 2, errors: 0,
            cursor: 8, total: 8, warnings: [], telegram_sent: false, telegram_failed: false,
        },
        error_message: null,
        progress_pct: 100,
        hits: {
            data: [{
                id: 810 + screenerId,
                stock_id: 44,
                symbol: 'TCS',
                exchange: 'NSE',
                name: 'Tata Consultancy Services',
                metrics: [{ left: 'Close', left_value: 3520, operator: 'gt', weight_factor: 1, right: 'SMA(20)' }],
            }],
            current_page: 1,
            last_page: 1,
            total: 1,
        },
        diagnostics: { data: [], current_page: 1, last_page: 1, total: 0 },
    };
}

function screenerFixture(id, artifactStatus = 'active') {
    const run = runFixture(id);
    const archived = artifactStatus === 'archived';
    return {
        id,
        profile_id: 1,
        name: id === 41 ? 'Strategy-dependent momentum screener' : 'Independent value screener',
        slug: `runtime-screener-${id}`,
        artifact_version: 1,
        definition_hash: `definition-${id}`,
        description: 'Runtime screener with a saved historical result.',
        intent: null,
        summary: null,
        tags: [],
        artifact_status: artifactStatus,
        scope: 'holdings',
        watchlist_id: null,
        index_symbol: null,
        index: null,
        watchlist: null,
        definition_json: DEFINITION,
        max_lookback: 20,
        schedule_enabled: archived ? false : true,
        schedule_time: archived ? null : '18:30',
        schedule_days: archived ? [] : [1, 2, 3, 4, 5],
        telegram_enabled: false,
        is_enabled: !archived,
        is_shared: false,
        is_factory: false,
        factory_key: null,
        reusable_artifact_id: null,
        reusable_artifact_uuid: null,
        compatibility_read_only: false,
        watchlist_issue: null,
        index_issue: null,
        last_run_at: run.finished_at,
        last_run: {
            id: run.id,
            status: run.status,
            finished_at: run.finished_at,
            stats: {
                matched: run.stats.matched,
                scanned: run.stats.scanned,
                skipped_insufficient_data: run.stats.skipped_insufficient_data,
                errors: run.stats.errors,
                warnings: run.stats.warnings,
            },
            error_message: run.error_message,
        },
        created_at: '2026-10-17T08:00:00Z',
        updated_at: archived ? '2026-10-18T10:00:00Z' : '2026-10-17T08:00:00Z',
    };
}

function createNetworkEvidence(page, allowedOrigin) {
    const requests = [];
    const byRequest = new Map();

    page.on('request', (request) => {
        const url = new URL(request.url());
        if (!['http:', 'https:'].includes(url.protocol)) return;
        const receipt = { request, url, method: request.method(), response: null };
        requests.push(receipt);
        byRequest.set(request, receipt);
    });
    page.on('response', (response) => {
        const receipt = byRequest.get(response.request());
        if (receipt) receipt.response = response;
    });
    async function settleAllObserved() {
        let cursor = 0;
        while (cursor < requests.length) {
            const end = requests.length;
            const batch = requests.slice(cursor, end);
            await Promise.all(batch.map(async (receipt) => {
                const response = receipt.response ?? await receipt.request.response();
                expect(response, `${receipt.method} ${new URL(receipt.request.url()).pathname} must have a response receipt`).toBeTruthy();
                receipt.response = response;
                expect(await response.finished(), `${receipt.method} ${new URL(receipt.request.url()).pathname} must finish cleanly`).toBeNull();
                await response.body();
            }));
            cursor = end;
        }
    }

    function assertBoundary(allowedDeletePath) {
        expect(requests.map(({ url }) => url.origin).filter((origin) => origin !== allowedOrigin)).toEqual([]);
        const mutations = requests
            .filter(({ method }) => /^(POST|PUT|PATCH|DELETE)$/.test(method))
            .map(({ method, url, request }) => ({ method, path: url.pathname, body: request.postData() }));
        expect(mutations).toEqual(allowedDeletePath ? [{ method: 'DELETE', path: allowedDeletePath, body: null }] : []);
    }

    return { requests, settleAllObserved, assertBoundary };
}

async function prepareRuntimeArchive(page, viewport, screenerId, archiveOutcome) {
    await seedDeterministicJourney(page, `scr08-runtime-${viewport.name}-${screenerId}`);
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    const allowedOrigin = new URL(process.env.STOX_E2E_BASE_URL || 'http://127.0.0.1:4177').origin;
    const evidence = createNetworkEvidence(page, allowedOrigin);
    await page.route('**/*', async (route) => {
        const url = new URL(route.request().url());
        if (['http:', 'https:'].includes(url.protocol) && url.origin !== allowedOrigin) {
            return route.abort();
        }
        return route.fallback();
    });
    await installInvestorWorkflowApiMocks(page);

    let record = screenerFixture(screenerId);
    const savedRun = runFixture(screenerId);
    const pathForRecord = `/api/screeners/${screenerId}`;
    const pathForRuns = `${pathForRecord}/runs`;
    const pathForMatrix = `${pathForRecord}/backtest/matrix`;
    const pathForRun = `/api/screener-runs/${savedRun.id}`;

    await page.route('**/api/**', async (route) => {
        const request = route.request();
        const url = new URL(request.url());
        const method = request.method();
        const path = url.pathname;

        if (path === '/api/screeners' && method === 'GET') {
            return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [record], count: 1 }) });
        }
        if (path === pathForRecord && method === 'GET') {
            return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: record }) });
        }
        if (path === pathForRuns && method === 'GET') {
            const { hits, diagnostics, ...summary } = savedRun;
            return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [summary], count: 1, total: 1, limit: 30 }) });
        }
        if (path === pathForRun && method === 'GET') {
            return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: savedRun }) });
        }
        if (path === pathForMatrix && method === 'GET') {
            return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: { run_count: 0, windows: [], matrix: [] } }) });
        }
        if (path === pathForRecord && method === 'DELETE') {
            if (archiveOutcome === 'dependency') {
                return route.fulfill({
                    status: 422,
                    contentType: 'application/json',
                    body: JSON.stringify({ message: 'The given data was invalid.', errors: { screener: [DEPENDENCY_MESSAGE] } }),
                });
            }
            record = screenerFixture(screenerId, 'archived');
            return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ message: 'Screener archived. Run history was preserved.' }) });
        }
        return route.fallback();
    });

    await page.goto('/screeners');
    await evidence.settleAllObserved();
    const row = page.getByRole('row').filter({ has: page.getByRole('link', { name: record.name, exact: true }) });
    await expect(row).toHaveCount(1);
    await expect(row.getByRole('button', { name: 'Run', exact: true })).toBeEnabled();
    return { evidence, record, savedRun, row, pathForRecord };
}

async function openSavedHistory(page, record, savedRun, evidence) {
    const editLabel = record.artifact_status === 'archived' ? 'Inspect' : 'Edit';
    const row = page.getByRole('row').filter({ has: page.getByRole('link', { name: record.name, exact: true }) });
    await row.getByRole('link', { name: editLabel, exact: true }).click();
    await expect(page.getByRole('heading', { name: record.artifact_status === 'archived' ? 'Archived screener' : 'Edit screener', exact: true })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Run history', exact: true })).toBeVisible();
    const historyEntry = page.getByRole('button', { name: new RegExp(`Run ID ${savedRun.id} · Manual · completed`) });
    await expect(historyEntry).toHaveCount(1);
    const detailResponse = page.waitForResponse((response) => (
        response.request().method() === 'GET'
        && new URL(response.url()).pathname === `/api/screener-runs/${savedRun.id}`
    ));
    await historyEntry.click();
    const response = await detailResponse;
    expect(response.status()).toBe(200);
    expect(await response.finished()).toBeNull();
    expect(await response.json()).toMatchObject({
        data: {
            id: savedRun.id,
            screener_id: record.id,
            screener_version_id: savedRun.screener_version_id,
            status: 'completed',
            hits: {
                data: [{
                    id: savedRun.hits.data[0].id,
                    symbol: 'TCS',
                    exchange: 'NSE',
                    name: 'Tata Consultancy Services',
                    metrics: [{ left: 'Close', left_value: 3520, operator: 'gt', right: 'SMA(20)' }],
                }],
            },
        },
    });
    await evidence.settleAllObserved();
    const resultRegion = page.getByRole('region', { name: 'Screener run results', exact: true });
    await expect(resultRegion).toContainText(`Run ID ${savedRun.id}`);
    await expect(resultRegion).toContainText('Latest run');
    await expect(resultRegion).toContainText('Tata Consultancy Services');
    await expect(resultRegion).toContainText('Close=3520.00 > SMA(20)');
    return resultRegion;
}

async function resolveArchiveDialog(page, row, outcome) {
    const dialogPromise = page.waitForEvent('dialog').then(async (dialog) => {
        let assertionError = null;
        try {
            expect(dialog.type()).toBe('confirm');
            expect(dialog.message()).toBe('Archive this screener? Its run history will be preserved.');
        } catch (error) {
            assertionError = error;
        } finally {
            if (outcome === 'dismiss') {
                await dialog.dismiss();
            } else {
                await dialog.accept();
            }
        }
        if (assertionError) throw assertionError;
        return { type: dialog.type(), message: dialog.message() };
    });
    const clickPromise = row.getByRole('button', { name: 'Archive', exact: true }).click();
    const [, dialog] = await Promise.all([clickPromise, dialogPromise]);
    expect(dialog).toEqual({
        type: 'confirm',
        message: 'Archive this screener? Its run history will be preserved.',
    });
}

test.describe('SCR-08 runtime screener archive conformance', () => {
    for (const viewport of VIEWPORTS) {
        test(`SCR-08 cancels archive, then shows the Strategy dependency rejection without losing run history (${viewport.name})`, async ({ page }, testInfo) => {
            journeyId(testInfo, 'SCR-08');
            const { evidence, record, savedRun, row, pathForRecord } = await prepareRuntimeArchive(page, viewport, 41, 'dependency');

            await resolveArchiveDialog(page, row, 'dismiss');
            await evidence.settleAllObserved();
            await expect(row.getByText('Strategy-dependent momentum screener', { exact: true })).toBeVisible();
            evidence.assertBoundary(null);

            const deleteResponse = page.waitForResponse((response) => (
                response.request().method() === 'DELETE'
                && new URL(response.url()).origin === new URL(process.env.STOX_E2E_BASE_URL || 'http://127.0.0.1:4177').origin
                && new URL(response.url()).pathname === pathForRecord
            ));
            await resolveArchiveDialog(page, row, 'accept');
            const response = await deleteResponse;
            expect(response.status()).toBe(422);
            expect(await response.finished()).toBeNull();
            expect(await response.json()).toEqual({ message: 'The given data was invalid.', errors: { screener: [DEPENDENCY_MESSAGE] } });
            await evidence.settleAllObserved();
            await expect(page.getByText(DEPENDENCY_MESSAGE, { exact: true })).toBeVisible();
            await expect(row.getByRole('button', { name: 'Run', exact: true })).toBeEnabled();
            await expect(row.getByRole('button', { name: 'Archive', exact: true })).toBeEnabled();
            await expect(row.getByText('Off', { exact: true })).toHaveCount(0);
            await expect(page.getByText('Screener archived. Run history was preserved.', { exact: true })).toHaveCount(0);
            evidence.assertBoundary(pathForRecord);
            await openSavedHistory(page, record, savedRun, evidence);
            evidence.assertBoundary(pathForRecord);
        });

        test(`SCR-08 archives an independent runtime screener and keeps its saved run inspectable (${viewport.name})`, async ({ page }, testInfo) => {
            journeyId(testInfo, 'SCR-08');
            const { evidence, record, savedRun, row, pathForRecord } = await prepareRuntimeArchive(page, viewport, 52, 'eligible');
            await openSavedHistory(page, record, savedRun, evidence);
            await page.getByRole('link', { name: '← Screeners', exact: true }).click();
            await evidence.settleAllObserved();
            await expect(row.getByRole('link', { name: 'Edit', exact: true })).toBeVisible();
            await expect(row.getByRole('button', { name: 'Run', exact: true })).toBeEnabled();

            const deleteResponse = page.waitForResponse((response) => (
                response.request().method() === 'DELETE'
                && new URL(response.url()).pathname === pathForRecord
            ));
            await resolveArchiveDialog(page, row, 'accept');
            const response = await deleteResponse;
            expect(response.status()).toBe(200);
            expect(await response.finished()).toBeNull();
            expect(await response.json()).toEqual({ message: 'Screener archived. Run history was preserved.' });
            await evidence.settleAllObserved();
            await expect(row.locator('span.badge').filter({ hasText: /^Archived$/ })).toBeVisible();
            await expect(row.getByRole('link', { name: 'Inspect', exact: true })).toBeVisible();
            await expect(row.getByRole('button', { name: 'Run', exact: true })).toBeDisabled();
            await expect(row.getByRole('button', { name: 'Archived', exact: true })).toBeDisabled();
            evidence.assertBoundary(pathForRecord);

            const history = await openSavedHistory(page, { ...record, artifact_status: 'archived' }, savedRun, evidence);
            await expect(page.getByText('This screener is archived and cannot be run or edited. Its run history remains available below.', { exact: true })).toBeVisible();
            await expect(page.getByRole('button', { name: 'Run now', exact: true })).toBeDisabled();
            await expect(history).toContainText('TCS');
            evidence.assertBoundary(pathForRecord);
        });
    }
});
