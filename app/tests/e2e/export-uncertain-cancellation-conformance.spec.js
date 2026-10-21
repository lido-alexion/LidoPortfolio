import { test, expect } from '@playwright/test';
import { journeyId, seedDeterministicJourney } from './journeyTestUtils.js';
import { installInvestorWorkflowApiMocks } from './investorWorkflowApiMocks.js';

const ORIGIN = 'http://127.0.0.1:4177';
const RECENT_SNAPSHOT = { id: '101', snapshot_date: '2026-10-10', portfolio_value: '100000.00', invested_value: '90000.00' };
const RECENT_SNAPSHOT_BODY = {
    snapshots: [RECENT_SNAPSHOT],
    meta: { count: 1, from_date: '2026-10-10', to_date: '2026-10-10', limit: 365 },
};
const HISTORIC_SNAPSHOT_ROWS = Array.from({ length: 1000 }, (_, index) => ({
    id: String(1000 + index),
    snapshot_date: new Date(Date.UTC(2023, 11, 1) + index * 24 * 60 * 60 * 1000).toISOString().slice(0, 10),
    portfolio_value: `${95000 + (index * 4)}.00`,
    invested_value: `${85000 + (index * 4)}.00`,
}));
const ALL_SNAPSHOT_ROWS = [...HISTORIC_SNAPSHOT_ROWS, RECENT_SNAPSHOT];
const ALL_SNAPSHOT_BODY = {
    snapshots: ALL_SNAPSHOT_ROWS,
    meta: {
        count: ALL_SNAPSHOT_ROWS.length,
        from_date: ALL_SNAPSHOT_ROWS[0].snapshot_date,
        to_date: ALL_SNAPSHOT_ROWS.at(-1).snapshot_date,
        limit: 2000,
    },
};
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const SNAPSHOT_FILTERS = { range: 'all', sort_by: 'snapshot_date', sort_direction: 'desc' };
const FIELD_SELECTION = ['snapshot_date', 'portfolio_value'];
const NOTIFICATION_BODY = {
    data: [],
    meta: { current_page: 1, last_page: 1, per_page: 5, total: 0, unread_count: 0, active_critical_count: 0 },
};
const SCENARIOS = {
    committed: { token: '11111111-1111-4111-8111-111111111111', id: 1801 },
    notCommitted: { token: '22222222-2222-4222-8222-222222222222', id: 1802 },
};
const VIEWPORTS = [
    { name: 'mobile', width: 390, height: 844 },
    { name: 'desktop', width: 1440, height: 900 },
];

function exactKeys(value, keys) {
    return value && typeof value === 'object' && !Array.isArray(value)
        && Object.keys(value).sort().join(',') === [...keys].sort().join(',');
}

function expectApiFailurePayload(request, cancelRequestId, token) {
    const url = new URL(request.url());
    expect(url.origin).toBe(ORIGIN);
    expect(url.username).toBe('');
    expect(url.password).toBe('');
    expect(url.pathname).toBe('/api/ops/api-failures');
    expect(url.search).toBe('');
    expect(request.method()).toBe('POST');
    const body = request.postDataJSON();
    expect(exactKeys(body, ['method', 'endpoint', 'status', 'message', 'request_id'])).toBe(true);
    expect(body.method).toBe('post');
    expect(body.endpoint).toBe(`/api/exports/${token}/cancel`);
    expect(body.status).toBe(503);
    expect(body.message).toBe('Request failed with status code 503');
    expect(body.request_id).toBe(cancelRequestId);
}

function expectFrontendLogPayload(request, cancelRequestId, token) {
    const url = new URL(request.url());
    expect(url.origin).toBe(ORIGIN);
    expect(url.username).toBe('');
    expect(url.password).toBe('');
    expect(url.pathname).toBe('/api/logs/frontend');
    expect(url.search).toBe('');
    expect(request.method()).toBe('POST');
    const body = request.postDataJSON();
    expect(exactKeys(body, ['level', 'message', 'url', 'userAgent', 'timestamp', 'requestId', 'extra'])).toBe(true);
    expect(body.level).toBe('error');
    expect(body.message).toBe('API request failed');
    expect(body.url).toBe('/portfolio/snapshots');
    expect(typeof body.userAgent).toBe('string');
    expect(body.userAgent.length).toBeLessThanOrEqual(500);
    expect(Number.isNaN(Date.parse(body.timestamp))).toBe(false);
    expect(body.requestId).toBe(cancelRequestId);
    expect(exactKeys(body.extra, ['category', 'requestId', 'status', 'api', 'message'])).toBe(true);
    expect(body.extra.category).toBe('API');
    expect(body.extra.requestId).toBe(cancelRequestId);
    expect(body.extra.status).toBe(503);
    expect(body.extra.api).toBe(`/exports/${token}/cancel`);
    expect(body.extra.message).toBe('Synthetic cancellation response unavailable.');
}

async function prepare(page, viewport, scenario, mode) {
    await seedDeterministicJourney(page, `export-uncertain-cancel-${scenario}-${viewport.name}`);
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    await installInvestorWorkflowApiMocks(page);

    const token = SCENARIOS[scenario].token;
    const statusPath = `/api/exports/${token}`;
    const cancelPath = `${statusPath}/cancel`;
    const downloadPath = `${statusPath}/download`;
    const cancelExpiry = new Date(Date.now() + 24 * 60 * 60 * 1000).toISOString();
    const requests = [];
    const requestReceipts = new Map();
    const events = [];
    const rejected = [];
    const state = { status: 'running', cancelAttempts: 0, createCount: 0, downloads: 0 };

    page.on('request', request => {
        const url = new URL(request.url());
        if (url.origin !== ORIGIN || !url.pathname.startsWith('/api/')) return;
        const watched = url.pathname === '/api/portfolio/snapshots'
            || url.pathname === '/api/notification-center'
            || url.pathname === '/api/exports'
            || url.pathname === statusPath
            || url.pathname === cancelPath
            || url.pathname === downloadPath
            || url.pathname === '/api/ops/api-failures'
            || url.pathname === '/api/logs/frontend';
        if (!watched) return;
        const receipt = { request, url, expected: null, response: null, failed: false };
        requests.push(receipt);
        requestReceipts.set(request, receipt);
    });
    page.on('response', response => {
        const receipt = requestReceipts.get(response.request());
        if (receipt) receipt.response = response;
    });
    page.on('requestfailed', request => {
        const receipt = requestReceipts.get(request);
        if (receipt) receipt.failed = true;
    });
    page.on('download', () => { state.downloads += 1; });

    // Registered after the shared workflow mock and before the global boundary.
    await page.route('**/api/notification-center?*', async route => {
        const request = route.request();
        const url = new URL(request.url());
        expect(url.origin).toBe(ORIGIN);
        expect(url.username).toBe('');
        expect(url.password).toBe('');
        expect(url.pathname).toBe('/api/notification-center');
        expect([...url.searchParams.entries()]).toEqual([['per_page', '5']]);
        expect(request.method()).toBe('GET');
        const profile = request.headers()['x-profile-id'] ?? null;
        expect([null, '1']).toContain(profile);
        const body = structuredClone(NOTIFICATION_BODY);
        requestReceipts.get(request).expected = { method: 'GET', path: url.pathname, query: url.search, profile, status: 200, body };
        return route.fulfill({ status: 200, json: body });
    });

    const snapshotReceipts = [];
    await page.route('**/api/portfolio/snapshots*', async route => {
        const request = route.request();
        const url = new URL(request.url());
        expect(url.origin).toBe(ORIGIN);
        expect(url.username).toBe('');
        expect(url.password).toBe('');
        expect(url.pathname).toBe('/api/portfolio/snapshots');
        expect([...url.searchParams.keys()].sort()).toContain('limit');
        expect(request.method()).toBe('GET');
        const profile = request.headers()['x-profile-id'] ?? null;
        expect(profile).toBe('1');
        let body;
        if (url.searchParams.get('limit') === '365') {
            expect([...url.searchParams.keys()].sort()).toEqual(['from_date', 'limit']);
            expect(url.searchParams.get('from_date')).toMatch(/^\d{4}-\d{2}-\d{2}$/);
            body = structuredClone(RECENT_SNAPSHOT_BODY);
        } else {
            expect(url.searchParams.get('limit')).toBe('2000');
            expect([...url.searchParams.keys()]).toEqual(['limit']);
            body = structuredClone(ALL_SNAPSHOT_BODY);
        }
        requestReceipts.get(request).expected = { method: 'GET', path: url.pathname, query: url.search, profile, status: 200, body };
        snapshotReceipts.push(requestReceipts.get(request));
        return route.fulfill({ status: 200, json: body });
    });

    await page.route('**/api/exports', async route => {
        const request = route.request();
        const url = new URL(request.url());
        expect(url.origin).toBe(ORIGIN);
        expect(url.username).toBe('');
        expect(url.password).toBe('');
        expect(url.pathname).toBe('/api/exports');
        expect(url.search).toBe('');
        expect(request.method()).toBe('POST');
        expect(request.headers()['x-profile-id']).toBe('1');
        const payload = {
            dataset: 'portfolio-snapshots',
            format: 'csv',
            scope: 'current',
            fields: FIELD_SELECTION,
            filters: SNAPSHOT_FILTERS,
        };
        expect(request.postDataJSON()).toEqual(payload);
        expect(snapshotReceipts.some(receipt => receipt.url.searchParams.get('limit') === '2000')).toBe(true);
        state.createCount += 1;
        events.push('create');
        expect(state.createCount).toBe(1);
        const body = { data: { id: SCENARIOS[scenario].id, token, status: 'running', download_url: `${downloadPath}`, expires_at: null } };
        requestReceipts.get(request).expected = { method: 'POST', path: url.pathname, query: '', profile: '1', payload, status: 202, body };
        return route.fulfill({ status: 202, json: body });
    });

    await page.route(`**${cancelPath}`, async route => {
        const request = route.request();
        const url = new URL(request.url());
        expect(url.origin).toBe(ORIGIN);
        expect(url.username).toBe('');
        expect(url.password).toBe('');
        expect(url.pathname).toBe(cancelPath);
        expect(url.search).toBe('');
        expect(request.method()).toBe('POST');
        expect(request.headers()['x-profile-id']).toBe('1');
        expect(request.postData()).toBeNull();
        state.cancelAttempts += 1;
        if (state.cancelAttempts === 1) {
            events.push('cancel-uncertain');
            if (mode === 'committed') state.status = 'cancelled';
            const body = { message: 'Synthetic cancellation response unavailable.' };
            requestReceipts.get(request).expected = { method: 'POST', path: url.pathname, query: '', profile: '1', payload: null, status: 503, body };
            return route.fulfill({ status: 503, json: body });
        }
        expect(mode).toBe('notCommitted');
        expect(state.status).toBe('running');
        expect(events).toContain('status-refresh-running');
        state.status = 'cancelled';
        events.push('cancel-retry');
        const body = { data: { status: 'cancelled' } };
        requestReceipts.get(request).expected = { method: 'POST', path: url.pathname, query: '', profile: '1', payload: null, status: 200, body };
        return route.fulfill({ status: 200, json: body });
    });

    await page.route(`**${statusPath}`, async route => {
        const request = route.request();
        const url = new URL(request.url());
        expect(url.origin).toBe(ORIGIN);
        expect(url.username).toBe('');
        expect(url.password).toBe('');
        expect(url.pathname).toBe(statusPath);
        expect(url.search).toBe('');
        expect(request.method()).toBe('GET');
        expect(request.headers()['x-profile-id']).toBe('1');
        if (mode === 'committed') {
            expect(state.status).toBe('cancelled');
            events.push('status-refresh-cancelled');
        } else if (state.status === 'running') {
            events.push('status-refresh-running');
        } else {
            expect(state.status).toBe('cancelled');
            events.push('status-after-cancel');
        }
        const body = {
            data: {
                token,
                status: state.status,
                expires_at: state.status === 'cancelled' ? cancelExpiry : null,
                download_url: null,
            },
        };
        requestReceipts.get(request).expected = { method: 'GET', path: url.pathname, query: '', profile: '1', status: 200, body };
        return route.fulfill({ status: 200, json: body });
    });

    await page.route(`**${downloadPath}`, async route => {
        state.downloads += 1;
        return route.abort();
    });

    await page.route('**/api/ops/api-failures*', async route => {
        const request = route.request();
        const cancelRequestId = requests.find(item => item.url.pathname === cancelPath)?.request.headers()['x-request-id'];
        expect(cancelRequestId).toBeTruthy();
        expectApiFailurePayload(request, cancelRequestId, token);
        expect(request.headers()['x-profile-id']).toBe('1');
        const body = { data: { accepted: true } };
        requestReceipts.get(request).expected = { method: 'POST', path: '/api/ops/api-failures', query: '', profile: '1', payload: request.postDataJSON(), status: 202, body };
        return route.fulfill({ status: 202, json: body });
    });

    await page.route('**/api/logs/frontend*', async route => {
        const request = route.request();
        const cancelRequestId = requests.find(item => item.url.pathname === cancelPath)?.request.headers()['x-request-id'];
        expect(cancelRequestId).toBeTruthy();
        expectFrontendLogPayload(request, cancelRequestId, token);
        expect(new URL(request.url()).search).toBe('');
        const body = { message: 'Log accepted' };
        requestReceipts.get(request).expected = { method: 'POST', path: '/api/logs/frontend', query: '', profile: request.headers()['x-profile-id'] ?? null, payload: request.postDataJSON(), status: 202, body };
        return route.fulfill({ status: 202, json: body });
    });

    // This boundary is last so a write must pass it before reaching a fixture handler.
    await page.route('**/*', route => {
        const request = route.request();
        const url = new URL(request.url());
        const allowedWrite = request.method() === 'POST'
            && (url.pathname === '/api/exports'
                || url.pathname === '/api/logs/frontend'
                || url.pathname === '/api/ops/api-failures'
                || url.pathname === cancelPath);
        if (url.origin !== ORIGIN || (!['GET', 'HEAD'].includes(request.method()) && !allowedWrite)) {
            rejected.push(`${request.method()} ${url.pathname}`);
            return route.abort();
        }
        return route.fallback();
    });

    return { page, token, statusPath, cancelPath, downloadPath, state, requests, events, rejected, requestReceipts, snapshotReceipts };
}

function answerExportPrompts(page) {
    const answers = ['csv', 'current', 'snapshot_date,portfolio_value'];
    const expectedMessages = [
        'Export format: csv or xlsx',
        'Choose scope: current, full',
        'Fields to include (comma separated): snapshot_date, portfolio_value, invested_value',
    ];
    page.on('dialog', async dialog => {
        expect(dialog.type()).toBe('prompt');
        expect(expectedMessages.shift()).toBe(dialog.message());
        const answer = answers.shift();
        expect(answer).toBeTruthy();
        await dialog.accept(answer);
    });
    return { answers, expectedMessages };
}

async function openSnapshotExport(page) {
    await page.goto('/portfolio/snapshots');
    await expect(page.locator('.container-fluid').getByRole('heading', { name: 'Portfolio Snapshots', exact: true })).toBeVisible();
    await expect(page.getByLabel('Select snapshot 2026-10-10', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'All', exact: true }).click();
    const latestSnapshot = ALL_SNAPSHOT_ROWS.at(-1);
    const snapshotRow = page.getByRole('row').filter({ has: page.getByLabel(`Select snapshot ${latestSnapshot.snapshot_date}`, { exact: true }) });
    const [, year, month, day] = latestSnapshot.snapshot_date.match(/^(\d{4})-(\d{2})-(\d{2})$/);
    const displayDate = `${day}-${MONTHS[Number(month) - 1]}-${year}`;
    await expect(snapshotRow).toContainText(displayDate);
    await expect(snapshotRow).toContainText(`₹ ${new Intl.NumberFormat('en-IN', { maximumFractionDigits: 0 }).format(Number(latestSnapshot.portfolio_value))}`);
    await expect(snapshotRow).toContainText(`₹ ${new Intl.NumberFormat('en-IN', { maximumFractionDigits: 0 }).format(Number(latestSnapshot.invested_value))}`);
    const promptState = answerExportPrompts(page);
    await page.getByRole('button', { name: 'Export table data', exact: true }).click();
    const job = page.getByRole('region', { name: 'Latest export', exact: true });
    await expect(job.getByRole('status')).toContainText('Export is being prepared.');
    return { job, promptState };
}

async function expectTerminalReceipts(safety) {
    await safety.page.waitForLoadState('networkidle');
    for (const receipt of safety.requests) {
        expect(receipt.expected, `${receipt.request.method()} ${receipt.url.pathname} must be mapped to an inspected fixture contract`).toBeTruthy();
        expect(receipt.failed, `${receipt.request.method()} ${receipt.url.pathname} must settle normally`).toBe(false);
        const response = receipt.response ?? await receipt.request.response();
        expect(response, `${receipt.request.method()} ${receipt.url.pathname} must have a response receipt`).toBeTruthy();
        expect(await response.finished()).toBeNull();
        const expected = receipt.expected;
        const url = new URL(receipt.request.url());
        expect(receipt.request.method()).toBe(expected.method);
        expect(url.pathname).toBe(expected.path);
        expect(url.search).toBe(expected.query);
        expect(url.origin).toBe(ORIGIN);
        expect(url.username).toBe('');
        expect(url.password).toBe('');
        expect(receipt.request.headers()['x-profile-id'] ?? null).toBe(expected.profile);
        if (Object.hasOwn(expected, 'payload')) {
            const payload = receipt.request.postData() == null ? null : receipt.request.postDataJSON();
            expect(payload).toEqual(expected.payload);
        }
        expect(response.status()).toBe(expected.status);
        expect(await response.json()).toEqual(expected.body);
    }
    expect(safety.requests.filter(item => item.url.pathname === '/api/portfolio/snapshots').length).toBeGreaterThan(0);
    expect(safety.snapshotReceipts.some(item => item.url.searchParams.get('limit') === '2000')).toBe(true);
    expect(safety.requests.filter(item => item.url.pathname === '/api/notification-center').length).toBeGreaterThan(0);
    expect(safety.rejected).toEqual([]);
    expect(safety.state.downloads).toBe(0);
}

for (const viewport of VIEWPORTS) {
    test.describe(`Uncertain export cancellation ${viewport.name}`, () => {
        test.use({ hasTouch: viewport.name === 'mobile' });

        test(`E2E-18 inspects status after a cancellation that committed before its response was lost (${viewport.name})`, async ({ page }, testInfo) => {
            journeyId(testInfo, 'E2E-18');
            const safety = await prepare(page, viewport, 'committed', 'committed');
            const { job, promptState } = await openSnapshotExport(page);
            expect(promptState.answers).toEqual([]);
            expect(promptState.expectedMessages).toEqual([]);
            expect(safety.state.createCount).toBe(1);
            await job.getByRole('button', { name: 'Cancel export', exact: true }).click();
            await expect(job.getByRole('alert')).toContainText('could not be confirmed');
            await expect(job.getByRole('status')).toHaveText('Export is being prepared.');
            await expect(job.getByRole('link', { name: 'Download export' })).toHaveCount(0);
            expect(safety.state.cancelAttempts).toBe(1);
            await job.getByRole('button', { name: 'Refresh export status', exact: true }).click();
            await expect(job.getByRole('status')).toHaveText('Export cancelled. No download is available.');
            await expect(job.getByRole('link', { name: 'Download export' })).toHaveCount(0);
            expect(safety.state.createCount).toBe(1);
            expect(safety.state.cancelAttempts).toBe(1);
            expect(safety.events.indexOf('cancel-uncertain')).toBeLessThan(safety.events.indexOf('status-refresh-cancelled'));
            await expectTerminalReceipts(safety);
        });

        test(`E2E-18 confirms a still-running export before a deliberate cancellation retry (${viewport.name})`, async ({ page }, testInfo) => {
            journeyId(testInfo, 'E2E-18');
            const safety = await prepare(page, viewport, 'notCommitted', 'notCommitted');
            const { job, promptState } = await openSnapshotExport(page);
            expect(promptState.answers).toEqual([]);
            expect(promptState.expectedMessages).toEqual([]);
            expect(safety.state.createCount).toBe(1);
            await job.getByRole('button', { name: 'Cancel export', exact: true }).click();
            await expect(job.getByRole('alert')).toContainText('could not be confirmed');
            await expect(job.getByRole('status')).toHaveText('Export is being prepared.');
            await expect(job.getByRole('link', { name: 'Download export' })).toHaveCount(0);
            expect(safety.state.cancelAttempts).toBe(1);
            await job.getByRole('button', { name: 'Refresh export status', exact: true }).click();
            await expect(job.getByRole('status')).toHaveText('Export is being prepared.');
            expect(safety.state.status).toBe('running');
            expect(safety.state.cancelAttempts).toBe(1);
            await job.getByRole('button', { name: 'Cancel export', exact: true }).click();
            await expect(job.getByRole('status')).toHaveText('Export cancelled. No download is available.');
            await expect(job.getByRole('link', { name: 'Download export' })).toHaveCount(0);
            expect(safety.state.createCount).toBe(1);
            expect(safety.state.cancelAttempts).toBe(2);
            expect(safety.events.indexOf('cancel-uncertain')).toBeLessThan(safety.events.indexOf('status-refresh-running'));
            expect(safety.events.indexOf('status-refresh-running')).toBeLessThan(safety.events.indexOf('cancel-retry'));
            expect(safety.events.indexOf('cancel-retry')).toBeLessThan(safety.events.indexOf('status-after-cancel'));
            await expectTerminalReceipts(safety);
        });
    });
}
