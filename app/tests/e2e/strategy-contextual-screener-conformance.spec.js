import { test, expect } from '@playwright/test';
import { journeyId, seedDeterministicJourney } from './journeyTestUtils.js';
import { installInvestorWorkflowApiMocks } from './investorWorkflowApiMocks.js';
import {
    createApiFailureDiagnosticCollector,
    handleApiFailureDiagnostic,
    registerApiFailureDiagnosticAfterEach,
    verifyApiFailureDiagnostics,
} from './apiFailureDiagnosticBoundary.js';

registerApiFailureDiagnosticAfterEach(test);

const VIEWPORTS = [
    { name: 'mobile', width: 390, height: 844 },
    { name: 'desktop', width: 1440, height: 900 },
];

test.afterEach(async ({ page }, testInfo) => {
    if (testInfo.status === testInfo.expectedStatus) return;

    const errors = (testInfo.errors || []).map((error) => ({
        message: error.message,
        stack: error.stack,
        location: error.location,
    }));
    console.error(`[STR-02 owned failure diagnostic] ${JSON.stringify({
        title: testInfo.title,
        retry: testInfo.retry,
        status: testInfo.status,
        url: page.url(),
        errors,
    })}`);
});

const EXISTING_SCREENER = {
    id: 41,
    name: 'Existing entry screen',
    description: 'Keep this eligibility source.',
    scope: 'holdings',
    is_enabled: true,
    version: 3,
    definition_json: { root: { type: 'group', op: 'AND', children: [] } },
};

const INITIAL_STRATEGY_ELIGIBILITY = [{
    screener_id: EXISTING_SCREENER.id,
    screener_name: EXISTING_SCREENER.name,
    description: EXISTING_SCREENER.description,
    enabled: true,
    priority: 1,
    display_order: 0,
    condition_count: 1,
    screener_version_id: 301,
}];

const EMPTY_NOTIFICATION_CENTER_RESPONSE = {
    data: [],
    meta: {
        current_page: 1,
        last_page: 1,
        per_page: 5,
        total: 0,
        unread_count: 0,
        active_critical_count: 0,
    },
};

const EXPECTED_STRATEGY_RESPONSE = {
    data: {
        strategy_id: 7,
        id: 7,
        name: 'Incomplete E2E Strategy',
        description: 'Saved but not executable',
        status: 'draft',
        is_enabled: false,
        setup_required: true,
        readiness: {
            requirements: [{
                code: 'eligibility_source_required',
                message: 'Add at least one eligibility Screener.',
            }],
        },
        indicators: [
            { key: 'momentum_score', category: 'Momentum', display_name: 'Momentum', label: 'Momentum', enabled: true, weight: 100 },
            { key: 'rsi_score', category: 'Momentum', display_name: 'RSI', label: 'RSI', enabled: false, weight: 0 },
        ],
        eligibility_sources: INITIAL_STRATEGY_ELIGIBILITY,
        portfolio_rules: { horizon_calendar_days: null, first_entry_pct: 50, max_holdings: 10 },
        exit_strategy: {
            enabled: true,
            mode: 'any',
            rules: [
                { key: 'trend_weakening', display_name: 'Trend Weakening', description: 'Trend score falls below threshold.', enabled: true, value: 40 },
                { key: 'score_exit', display_name: 'Overall Score Exit', description: 'Strategy score at or below exit threshold.', enabled: true, value: 20 },
                { key: 'atr_stop', display_name: 'ATR Stop', description: 'Unrealized loss exceeds N × ATR%.', enabled: false, atr_multiple: 2 },
                { key: 'screener_exit', display_name: 'Screener Exit', description: 'Exit when the holding appears in the selected screener latest completed results.', enabled: false, screener_id: null, screener_name: null },
            ],
        },
        market_gates: [],
    },
};

function installContextRequestGuard(page, appOrigin, mode) {
    const requests = [];
    const screenerWrites = [];
    const diagnostics = [];
    const diagnosticReports = createApiFailureDiagnosticCollector(page, appOrigin);
    const blockedRequests = [];
    const notificationReads = [];
    let createAttempts = 0;
    let createdScreener = null;

    page.on('request', (request) => requests.push(request));
    page.on('requestfailed', (request) => {
        blockedRequests.push(`${request.method()} ${new URL(request.url()).pathname}: ${request.failure()?.errorText || 'failed'}`);
    });

    page.route('**/*', async (route) => {
        const request = route.request();
        const url = new URL(request.url());
        const method = request.method().toUpperCase();
        const write = !['GET', 'HEAD', 'OPTIONS'].includes(method);

        if (await handleApiFailureDiagnostic(route, { origin: appOrigin, collector: diagnosticReports })) return;

        if (url.origin !== appOrigin) {
            blockedRequests.push(`${method} ${url.origin}${url.pathname}`);
            return route.abort();
        }

        if (method === 'GET' && url.pathname === '/api/notification-center') {
            const profileId = request.headers()['x-profile-id'] || null;
            if (url.search !== '?per_page=5' || (profileId !== null && profileId !== '1')) {
                blockedRequests.push(`${method} ${url.pathname} (unexpected notification query/profile)`);
                return route.abort();
            }
            notificationReads.push({
                method,
                path: url.pathname,
                query: url.search,
                profileId,
                status: 200,
                body: EMPTY_NOTIFICATION_CENTER_RESPONSE,
            });
            return route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify(EMPTY_NOTIFICATION_CENTER_RESPONSE),
            });
        }

        if (write && method === 'POST' && url.pathname === '/api/screeners') {
            const payload = request.postDataJSON();
            createAttempts += 1;
            const writeRecord = { method, path: url.pathname, payload };
            screenerWrites.push(writeRecord);
            if (mode === 'retry' && createAttempts === 1) {
                writeRecord.status = 503;
                return route.fulfill({
                    status: 503,
                    contentType: 'application/json',
                    body: JSON.stringify({ message: 'Synthetic Screener save unavailable.' }),
                });
            }
            createdScreener = {
                id: 99,
                name: payload.name,
                description: payload.description,
                scope: payload.scope,
                version: 1,
                is_enabled: true,
                definition_json: payload.definition_json,
            };
            writeRecord.status = 201;
            return route.fulfill({
                status: 201,
                contentType: 'application/json',
                body: JSON.stringify({ data: createdScreener }),
            });
        }

        if (write && method === 'POST' && url.pathname === '/api/logs/frontend') {
            const body = request.postDataJSON();
            const boundedRetryError = mode === 'retry'
                && body?.level === 'error'
                && body?.message === 'API request failed'
                && body?.extra?.category === 'API'
                && body?.extra?.status === 503
                && body?.extra?.message === 'Synthetic Screener save unavailable.'
                && String(body?.extra?.api || '').endsWith('/screeners');
            if (!boundedRetryError) {
                blockedRequests.push(`${method} ${url.pathname} (unexpected diagnostic)`);
                return route.abort();
            }
            diagnostics.push({ method, path: url.pathname, body });
            return route.fallback();
        }

        if (write) {
            blockedRequests.push(`${method} ${url.pathname}`);
            return route.abort();
        }
        if (method === 'GET' && url.pathname === '/api/screeners' && createdScreener) {
            return route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({ data: [EXISTING_SCREENER, createdScreener], count: 2 }),
            });
        }
        return route.fallback();
    });

    return { requests, screenerWrites, diagnostics, diagnosticReports, notificationReads, blockedRequests };
}

async function startJourney(page, testInfo, viewport, mode) {
    journeyId(testInfo, 'STR-02');
    await seedDeterministicJourney(page, `str02-context-${mode}-${viewport.name}`);
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    await installInvestorWorkflowApiMocks(page, {
        initialScreeners: [EXISTING_SCREENER],
        initialStrategyEligibility: INITIAL_STRATEGY_ELIGIBILITY,
    });
    const appOrigin = new URL(testInfo.project.use.baseURL).origin;
    const guard = installContextRequestGuard(page, appOrigin, mode);

    await page.goto('/strategy?strategy_id=7');
    await expect(page.locator('#strat-name')).toHaveValue('Incomplete E2E Strategy');
    await page.locator('#strat-name').fill('Unsaved contextual strategy');
    await page.getByRole('button', { name: 'Recommendation Thresholds', exact: true }).click();
    const openThreshold = page.locator('.lido-number-input-field').nth(1);
    await openThreshold.fill('73');
    await expect(openThreshold).toHaveValue('73');
    await page.getByRole('button', { name: 'Eligibility Sources', exact: true }).click();
    await expect(page.getByRole('row', { name: /Existing entry screen/ })).toBeVisible();
    await page.locator('#strategy-create-screener').click();
    await expect(page).toHaveURL(/\/screeners\/new\?from=strategy&strategy_id=7$/);
    await expect(page.getByRole('status')).toContainText('Save to return and add it to eligibility');
    await page.getByLabel('Name', { exact: true }).fill('Contextual momentum screen');
    const leaf = page.locator('.lido-screener-leaf').first();
    await leaf.getByLabel('left indicator').selectOption('close');
    await leaf.getByLabel('right indicator').selectOption('sma');
    await leaf.getByLabel('Period').fill('200');

    return { appOrigin, guard, openThreshold };
}

async function assertTerminalReceipts(page, guard, appOrigin) {
    await verifyApiFailureDiagnostics(page);
    expect(guard.blockedRequests).toEqual([]);
    expect(guard.requests.every((request) => new URL(request.url()).origin === appOrigin)).toBe(true);

    const writeRequests = guard.requests.filter((request) => !['GET', 'HEAD', 'OPTIONS'].includes(request.method().toUpperCase()));
    const recordedWrites = [
        ...guard.screenerWrites.map(({ method, path }) => `${method} ${path}`),
        ...guard.diagnostics.map(({ method, path }) => `${method} ${path}`),
        ...[...guard.diagnosticReports.observed.keys()].map((request) => `${request.method()} ${new URL(request.url()).pathname}`),
    ].sort();
    expect(writeRequests.map((request) => `${request.method()} ${new URL(request.url()).pathname}`).sort()).toEqual(recordedWrites);

    const apiReceipts = [];
    for (const request of guard.requests) {
        const response = await request.response();
        expect(response, `${request.method()} ${request.url()} has a terminal response`).not.toBeNull();
        expect(await response.finished()).toBeNull();
        const url = new URL(request.url());
        if (!url.pathname.startsWith('/api/')) continue;
        expect(response.headers()['content-type'] || '').toContain('application/json');
        const body = await response.json();
        expect(body).toBeTruthy();
        const unmockedBody = body?.error?.code === 'UNMOCKED'
            && typeof body.error.message === 'string'
            ? body.error.message.slice(0, 240)
            : null;
        if (!(request.method() === 'POST' && url.pathname === '/api/screeners')
            && !(request.method() === 'POST' && url.pathname === '/api/logs/frontend')) {
            const receiptLabel = `${request.method()} ${url.pathname} status=${response.status()}${unmockedBody ? ` unmocked=${JSON.stringify(unmockedBody)}` : ''}`;
            expect(response.status(), receiptLabel).toBeGreaterThanOrEqual(200);
            expect(response.status(), receiptLabel).toBeLessThan(300);
        }
        if (request.method() === 'POST' && url.pathname === '/api/logs/frontend') {
            expect(response.status()).toBe(200);
            expect(body).toEqual({ ok: true });
        }
        if (request.method() === 'POST' && url.pathname === '/api/ops/api-failures') {
            expect(url.search).toBe('');
            expect(url.username).toBe('');
            expect(url.password).toBe('');
            expect(response.status()).toBe(202);
            expect(body).toEqual({ data: { accepted: true } });
        }
        if (['/api/v1/strategy', '/api/screeners'].includes(url.pathname)) {
            expect(request.headers()['x-profile-id']).toBe('1');
        }
        if (request.method() === 'GET' && url.pathname === '/api/v1/strategy') {
            expect(url.search).toBe('?strategy_id=7');
            expect(body).toEqual(EXPECTED_STRATEGY_RESPONSE);
        }
        if (request.method() === 'GET' && url.pathname === '/api/screeners') {
            expect(url.search).toBe('');
        }
        if (request.method() === 'GET' && url.pathname === '/api/notification-center') {
            expect(url.search).toBe('?per_page=5');
            expect([null, '1']).toContain(request.headers()['x-profile-id'] || null);
            expect(response.status()).toBe(200);
            expect(body).toEqual(EMPTY_NOTIFICATION_CENTER_RESPONSE);
        }
        apiReceipts.push({
            method: request.method(),
            path: url.pathname,
            query: url.search,
            profileId: request.headers()['x-profile-id'] || null,
            status: response.status(),
            body,
        });
    }
    const notificationReceipts = apiReceipts.filter(({ method, path }) => (
        method === 'GET' && path === '/api/notification-center'
    ));
    expect(notificationReceipts.length).toBeGreaterThan(0);
    expect(notificationReceipts).toEqual(guard.notificationReads);
    expect(guard.diagnosticReports.violations).toEqual([]);
    expect(guard.diagnosticReports.failures).toEqual([]);
    expect(guard.diagnosticReports.pending.size).toBe(0);
    for (const receipt of guard.diagnosticReports.receipts) {
        expect(receipt).toMatchObject({ method: 'post', responseStatus: 202, finished: true, accepted: true });
    }
    expect(apiReceipts.filter(({ method, path }) => method === 'POST' && path === '/api/screeners').map(({ status }) => status))
        .toEqual(guard.screenerWrites.map(({ status }) => status));
    return apiReceipts;
}

function assertReturnReads(guard, start) {
    const strategyReads = guard.requests.slice(start).filter((request) => (
        request.method() === 'GET' && new URL(request.url()).pathname === '/api/v1/strategy'
    ));
    expect(strategyReads.length).toBeGreaterThan(0);
    for (const request of strategyReads) {
        const url = new URL(request.url());
        expect(url.search).toBe('?strategy_id=7');
        expect(request.headers()['x-profile-id']).toBe('1');
    }
}

function assertNoStrategySave(guard) {
    expect(guard.requests.some((request) => (
        ['POST', 'PUT', 'PATCH', 'DELETE'].includes(request.method())
        && ['/api/v1/strategy', '/api/v1/strategies'].includes(new URL(request.url()).pathname)
    ))).toBe(false);
}

async function assertRestoredDraft(page, openThreshold, expectedScreenerCount) {
    await expect(page.getByRole('button', { name: 'Eligibility Sources', exact: true })).toHaveClass(/btn-primary/);
    await page.getByRole('button', { name: 'General', exact: true }).click();
    await expect(page.locator('#strat-name')).toHaveValue('Unsaved contextual strategy');
    await page.getByRole('button', { name: 'Recommendation Thresholds', exact: true }).click();
    await expect(openThreshold).toHaveValue('73');
    await page.getByRole('button', { name: 'Eligibility Sources', exact: true }).click();
    await expect(page.getByRole('row', { name: /Existing entry screen/ })).toHaveCount(1);
    await expect(page.getByRole('row', { name: /Contextual momentum screen/ })).toHaveCount(expectedScreenerCount);
}

function watchContextReturn(page, parameter, value) {
    const contextReturn = page.waitForURL((url) => (
        url.pathname === '/strategy'
        && url.search === `?strategy_id=7&${parameter}=${value}`
    ));
    const canonicalReturn = page.waitForURL((url) => (
        url.pathname === '/strategy' && url.search === '?strategy_id=7'
    ));
    return Promise.all([contextReturn, canonicalReturn]);
}

for (const viewport of VIEWPORTS) {
    test(`STR-02 contextual save restores the Strategy and selects the new Screener (${viewport.name})`, async ({ page }, testInfo) => {
        const { appOrigin, guard, openThreshold } = await startJourney(page, testInfo, viewport, 'success');
        const returnReadStart = guard.requests.length;
        const saveResponse = page.waitForResponse((response) => (
            response.request().method() === 'POST' && new URL(response.url()).pathname === '/api/screeners'
        ));
        const returnNavigation = watchContextReturn(page, 'screener_created', '99');
        await page.getByRole('button', { name: 'Save', exact: true }).click();
        const response = await saveResponse;
        expect(response.status()).toBe(201);
        expect(await response.json()).toEqual({
            data: {
                id: 99,
                name: 'Contextual momentum screen',
                description: null,
                scope: 'holdings',
                version: 1,
                is_enabled: true,
                definition_json: {
                    root: {
                        type: 'group',
                        op: 'AND',
                        children: [{
                            type: 'condition',
                            left: { indicator: 'close', params: {} },
                            operator: 'gt',
                            weight_factor: 1,
                            right: { indicator: 'sma', params: { period: 200 } },
                        }],
                    },
                },
            },
        });
        await returnNavigation;
        await expect(page).toHaveURL('/strategy?strategy_id=7');
        await assertRestoredDraft(page, openThreshold, 1);
        const receipts = await assertTerminalReceipts(page, guard, appOrigin);
        assertReturnReads(guard, returnReadStart);
        expect(receipts.filter(({ method, path }) => method === 'POST' && path === '/api/screeners').map(({ status }) => status)).toEqual([201]);
        expect(guard.screenerWrites).toHaveLength(1);
        expect(guard.screenerWrites[0].payload).toEqual({
            name: 'Contextual momentum screen',
            description: null,
            scope: 'holdings',
            watchlist_id: null,
            index_symbol: null,
            definition_json: {
                root: {
                    type: 'group',
                    op: 'AND',
                    children: [{
                        type: 'condition',
                        left: { indicator: 'close', params: {} },
                        operator: 'gt',
                        weight_factor: 1,
                        right: { indicator: 'sma', params: { period: 200 } },
                    }],
                },
            },
            schedule_enabled: false,
            schedule_time: '18:30',
            schedule_days: [],
            telegram_enabled: false,
            is_enabled: true,
            is_shared: false,
            max_lookback: null,
        });
        assertNoStrategySave(guard);
    });

    test(`STR-02 failed contextual save retains the Screener draft for explicit retry (${viewport.name})`, async ({ page }, testInfo) => {
        const { appOrigin, guard, openThreshold } = await startJourney(page, testInfo, viewport, 'retry');
        const firstSave = page.waitForResponse((response) => (
            response.request().method() === 'POST' && new URL(response.url()).pathname === '/api/screeners'
        ));
        await page.getByRole('button', { name: 'Save', exact: true }).click();
        const firstSaveResponse = await firstSave;
        expect(firstSaveResponse.status()).toBe(503);
        expect(await firstSaveResponse.json()).toEqual({ message: 'Synthetic Screener save unavailable.' });
        await expect(page.getByText('Synthetic Screener save unavailable.', { exact: true })).toBeVisible();
        await expect(page).toHaveURL(/\/screeners\/new\?from=strategy&strategy_id=7$/);
        await expect(page.getByLabel('Name', { exact: true })).toHaveValue('Contextual momentum screen');
        await expect(page.locator('.lido-screener-leaf')).toHaveCount(1);

        const returnReadStart = guard.requests.length;
        const retrySave = page.waitForResponse((response) => (
            response.request().method() === 'POST' && new URL(response.url()).pathname === '/api/screeners'
        ));
        const returnNavigation = watchContextReturn(page, 'screener_created', '99');
        await page.getByRole('button', { name: 'Save', exact: true }).click();
        expect((await retrySave).status()).toBe(201);
        await returnNavigation;
        await expect(page).toHaveURL('/strategy?strategy_id=7');
        await assertRestoredDraft(page, openThreshold, 1);
        const receipts = await assertTerminalReceipts(page, guard, appOrigin);
        assertReturnReads(guard, returnReadStart);
        expect(receipts.filter(({ method, path }) => method === 'POST' && path === '/api/screeners').map(({ status }) => status)).toEqual([503, 201]);
        expect(guard.screenerWrites).toHaveLength(2);
        expect(guard.screenerWrites[0].payload).toEqual(guard.screenerWrites[1].payload);
        for (const diagnostic of guard.diagnostics) {
            expect(diagnostic).toMatchObject({ method: 'POST', path: '/api/logs/frontend' });
            expect(diagnostic.body).toMatchObject({
                level: 'error',
                message: 'API request failed',
                extra: { category: 'API', status: 503, message: 'Synthetic Screener save unavailable.' },
            });
        }
        assertNoStrategySave(guard);
    });

    test(`STR-02 contextual cancel restores the Strategy without creating a Screener (${viewport.name})`, async ({ page }, testInfo) => {
        const { appOrigin, guard, openThreshold } = await startJourney(page, testInfo, viewport, 'cancel');
        const returnReadStart = guard.requests.length;
        const returnNavigation = watchContextReturn(page, 'screener_return', 'cancel');
        await page.getByRole('button', { name: 'Cancel — return to Strategy', exact: true }).click();
        await returnNavigation;
        await expect(page).toHaveURL('/strategy?strategy_id=7');
        await assertRestoredDraft(page, openThreshold, 0);
        const receipts = await assertTerminalReceipts(page, guard, appOrigin);
        assertReturnReads(guard, returnReadStart);
        expect(receipts.filter(({ method, path }) => method === 'POST' && path === '/api/screeners')).toEqual([]);
        expect(guard.screenerWrites).toEqual([]);
        assertNoStrategySave(guard);
    });
}
