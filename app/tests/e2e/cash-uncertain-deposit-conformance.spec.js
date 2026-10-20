import { test, expect } from '@playwright/test';
import { journeyId, seedDeterministicJourney } from './journeyTestUtils.js';
import { installInvestorWorkflowApiMocks } from './investorWorkflowApiMocks.js';
import { TEST_PORTFOLIO } from '../js/tos/fixtures/tosApi.js';

const ORIGIN = 'http://127.0.0.1:4177';
const failureEvidenceByPage = new WeakMap();
const FAILURE_EVIDENCE_REASONS = new Set([
    'transport-failure',
    'url-credentials',
    'off-origin',
    'malformed-diagnostic',
    'unlisted-write',
    'fixture-unmocked-501',
    'status-outside-fixture-allowance',
    'response-received',
    'finished-without-response',
    'request-pending',
    'unexpected-notification-read',
    'other',
]);
const VIEWPORTS = [
    { name: 'mobile', width: 390, height: 844 },
    { name: 'desktop', width: 1440, height: 900 },
];
const BASELINE_ENTRY = {
    id: 4101,
    entry_date: '2026-10-09',
    entry_type: 'deposit',
    category: 'external_flow',
    amount: 2500,
    running_balance: 12500,
    reason: 'Prior unrelated funding',
};
const DEPOSIT = {
    amount: 3000,
    remarks: 'E2E22 uncertain deposit evidence',
    transaction_date: '2026-10-10',
};
const FAILURE_MESSAGE = 'Synthetic deposit response unavailable.';
const NOTIFICATION_EMPTY_RESPONSE = {
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

function statement(entries, closingBalance) {
    return {
        opening_balance: 10000,
        closing_balance: closingBalance,
        complete: true,
        incomplete_reason: null,
        from: null,
        to: '2026-10-20',
        entries,
        pagination: { page: 1, per_page: 100, total: entries.length, last_page: 1 },
    };
}

function cashReceipt(request, path, status, body) {
    const url = new URL(request.url());
    return {
        request,
        origin: url.origin,
        method: request.method(),
        path,
        query: url.search,
        profileId: request.headers()['x-profile-id'] ?? null,
        status,
        body,
    };
}

function safeRequestEvidence(request, reason, status = null) {
    const url = new URL(request.url());
    const method = request.method();
    const path = url.pathname;
    return {
        method: method.slice(0, 12),
        path: path.slice(0, 240),
        status,
        reason: FAILURE_EVIDENCE_REASONS.has(reason) ? reason : 'other',
        truncated: method.length > 12 || path.length > 240,
    };
}

function diagnosticPayloadIsValid(request, url) {
    if (request.method() !== 'POST' || url.origin !== ORIGIN || url.username !== '' || url.password !== ''
        || url.pathname !== '/api/ops/api-failures' || url.search !== '') return false;

    let payload;
    try {
        payload = request.postDataJSON();
    } catch {
        return false;
    }
    if (!payload || typeof payload !== 'object' || Array.isArray(payload)) return false;
    const keys = Object.keys(payload).sort();
    if (keys.join(',') !== 'endpoint,message,method,request_id,status') return false;
    return ['get', 'head', 'post', 'put', 'patch', 'delete', 'options'].includes(payload.method)
        && typeof payload.endpoint === 'string' && payload.endpoint.length <= 500
        && /^\/api\/(?!\/)[A-Za-z0-9_./{}:-]+$/.test(payload.endpoint)
        && (payload.status === null || (Number.isInteger(payload.status) && payload.status >= 400 && payload.status <= 599))
        && (payload.message === null || (typeof payload.message === 'string' && payload.message.length <= 500))
        && (payload.request_id === null || (typeof payload.request_id === 'string' && payload.request_id.length <= 128));
}

async function prepare(page, viewport, mode) {
    await seedDeterministicJourney(page, `cash-uncertain-deposit-${viewport.name}-${mode}`);
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    await installInvestorWorkflowApiMocks(page);

    const entries = [{ ...BASELINE_ENTRY }];
    const attempts = [];
    const rejectedWrites = [];
    const malformedDiagnostics = [];
    const diagnostics = [];
    const cashReceipts = [];
    const notificationRequests = [];
    const notificationReceipts = [];
    const invalidNotificationRequests = [];
    const apiRequests = new Set();
    const finishedRequests = new Set();
    const apiResponseStatuses = new Map();
    const failedRequests = [];
    let committed = false;

    page.on('request', request => {
        const url = new URL(request.url());
        if (url.origin === ORIGIN && url.pathname.startsWith('/api/')) {
            apiRequests.add(request);
            if (url.pathname === '/api/notification-center') notificationRequests.push(request);
        }
    });
    page.on('response', response => {
        const request = response.request();
        if (apiRequests.has(request)) apiResponseStatuses.set(request, response.status());
    });
    page.on('requestfinished', request => finishedRequests.add(request));
    page.on('requestfailed', request => {
        if (apiRequests.has(request)) {
            failedRequests.push(safeRequestEvidence(request, 'transport-failure'));
            finishedRequests.add(request);
        }
    });

    // The shell's NotificationProvider requests this exact read during bootstrap.
    // Install after shared mocks and before the final guard so fallback reaches here.
    await page.route('**/api/notification-center*', async route => {
        const request = route.request();
        const url = new URL(request.url());
        const profileId = request.headers()['x-profile-id'] ?? null;
        if (request.method() !== 'GET' || url.origin !== ORIGIN || url.username !== '' || url.password !== ''
            || url.pathname !== '/api/notification-center' || url.search !== '?per_page=5'
            || (profileId !== null && profileId !== '1')) {
            invalidNotificationRequests.push(safeRequestEvidence(request, 'unexpected-notification-read'));
            return route.abort();
        }
        const body = JSON.parse(JSON.stringify(NOTIFICATION_EMPTY_RESPONSE));
        notificationReceipts.push(cashReceipt(request, url.pathname, 200, body));
        return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(body) });
    });

    await page.route(/\/api\/cash(?:\?.*)?$/, route => {
        const body = JSON.parse(JSON.stringify({ data: {
            cash_balance: committed ? 15500 : 12500,
            reserved_cash: 0,
            available_physical_cash: committed ? 15500 : 12500,
            available_investable_cash: committed ? 15500 : 12500,
            reserve_shortfall_exists: false,
            strategies: [],
            reservations: [],
        } }));
        cashReceipts.push(cashReceipt(route.request(), new URL(route.request().url()).pathname, 200, body));
        return route.fulfill({ json: body });
    });
    await page.route('**/api/cash/statement*', route => {
        const body = JSON.parse(JSON.stringify({ data: statement(entries, committed ? 15500 : 12500) }));
        cashReceipts.push(cashReceipt(route.request(), new URL(route.request().url()).pathname, 200, body));
        return route.fulfill({ json: body });
    });
    for (const resource of ['recalls', 'bridge-loans', 'pending-sale-proceeds']) {
        await page.route(`**/api/v1/capital/${resource}*`, route => route.fulfill({ json: { data: [] } }));
    }
    await page.route('**/api/cash/deposit', async route => {
        const request = route.request();
        const url = new URL(request.url());
        const payload = request.postDataJSON();
        attempts.push({
            method: request.method(),
            origin: url.origin,
            path: url.pathname,
            query: url.search,
            profileId: request.headers()['x-profile-id'] ?? null,
            payload,
        });
        expect(request.method()).toBe('POST');
        expect(url.origin).toBe(ORIGIN);
        expect(url.pathname).toBe('/api/cash/deposit');
        expect(url.search).toBe('');
        expect(request.headers()['x-profile-id']).toBe(String(TEST_PORTFOLIO.id));
        expect(payload).toEqual(DEPOSIT);

        if (mode === 'uncertain-commit') {
            if (!committed) {
                committed = true;
                entries.push({
                    id: 4102,
                    entry_date: payload.transaction_date,
                    entry_type: 'deposit',
                    category: 'external_flow',
                    amount: payload.amount,
                    running_balance: 15500,
                    reason: payload.remarks,
                });
            }
        } else if (attempts.length === 2) {
            committed = true;
            entries.push({
                id: 4102,
                entry_date: payload.transaction_date,
                entry_type: 'deposit',
                category: 'external_flow',
                amount: payload.amount,
                running_balance: 15500,
                reason: payload.remarks,
            });
        }

        const status = attempts.length === 1 ? 503 : 201;
        const body = status === 503
            ? { message: FAILURE_MESSAGE }
            : { data: { entry: { id: 4102, entry_type: 'deposit', amount: 3000, balance_after: 15500, reason: DEPOSIT.remarks, entry_date: DEPOSIT.transaction_date } } };
        const receipt = JSON.parse(JSON.stringify(body));
        cashReceipts.push(cashReceipt(request, url.pathname, status, receipt));
        return route.fulfill({ status, json: body });
    });

    // Registered last so it guards every route above and the shared auth/API mock.
    await page.route('**/*', async route => {
        const request = route.request();
        const url = new URL(request.url());
        const mutation = /^(POST|PUT|PATCH|DELETE)$/.test(request.method());
        if (url.username !== '' || url.password !== '') {
            rejectedWrites.push(safeRequestEvidence(request, 'url-credentials'));
            return route.abort();
        }
        if (url.origin !== ORIGIN) {
            rejectedWrites.push(safeRequestEvidence(request, 'off-origin'));
            return route.abort();
        }
        if (url.pathname === '/api/ops/api-failures') {
            if (diagnosticPayloadIsValid(request, url)) {
                const body = { data: { accepted: true } };
                diagnostics.push({ request, path: url.pathname, status: 202, body: JSON.parse(JSON.stringify(body)) });
                return route.fulfill({ status: 202, contentType: 'application/json', body: JSON.stringify(body) });
            }
            malformedDiagnostics.push(safeRequestEvidence(request, 'malformed-diagnostic'));
            rejectedWrites.push(safeRequestEvidence(request, 'malformed-diagnostic'));
            return route.abort();
        }
        if (!mutation) return route.fallback();

        if (request.method() === 'POST' && url.pathname === '/api/cash/deposit' && url.search === '') {
            return route.fallback();
        }
        rejectedWrites.push(safeRequestEvidence(request, 'unlisted-write'));
        return route.abort();
    });

    const evidence = { entries, attempts, rejectedWrites, malformedDiagnostics, diagnostics, cashReceipts, notificationRequests, notificationReceipts, invalidNotificationRequests, apiRequests, finishedRequests, apiResponseStatuses, failedRequests };
    failureEvidenceByPage.set(page, evidence);
    return evidence;
}

async function fillDepositForm(page) {
    const operationGroup = page.getByRole('group', { name: 'Cash operation' });
    const depositToggle = operationGroup.getByRole('button', { name: 'Deposit', exact: true });
    await expect(depositToggle).toHaveCount(1);
    await expect(depositToggle).toBeVisible();
    await depositToggle.click();
    const form = page.locator('form').filter({ has: page.getByLabel('Amount', { exact: true }) });
    await expect(form).toHaveCount(1);
    const submit = form.getByRole('button', { name: 'Deposit', exact: true });
    await expect(submit).toHaveCount(1);
    await expect(submit).toBeVisible();
    await page.getByLabel('Amount', { exact: true }).fill(String(DEPOSIT.amount));
    await page.getByLabel('Transaction date', { exact: true }).fill('10-Oct-2026');
    await page.getByLabel('Transaction date', { exact: true }).press('Tab');
    await page.getByLabel('Remarks', { exact: true }).fill(DEPOSIT.remarks);
    return form;
}

async function cashHeaderRefresh(page) {
    const heading = page.getByRole('heading', { name: 'Cash management', exact: true });
    await expect(heading).toHaveCount(1);
    const header = heading.locator('..').locator('..');
    const refresh = header.getByRole('button', { name: 'Refresh', exact: true });
    await expect(refresh).toHaveCount(1);
    await expect(refresh).toBeVisible();
    return refresh;
}

async function expectDepositRow(page, expectedBalance) {
    const row = page.getByRole('row').filter({ has: page.getByRole('cell', { name: DEPOSIT.remarks, exact: true }) });
    await expect(row).toHaveCount(1);
    await expect(row.getByRole('cell').nth(0)).toHaveText('10-Oct-2026');
    await expect(row.getByRole('cell').nth(2)).toHaveText('Deposit');
    await expect(row.getByRole('cell').nth(3)).toHaveText('external flow');
    await expect(row.getByRole('cell').nth(4)).toContainText('3,000');
    await expect(row.getByRole('cell').nth(5)).toContainText(expectedBalance.toLocaleString('en-IN'));
    await expect(row.getByRole('cell').nth(6)).toHaveText(DEPOSIT.remarks);
}

async function expectCapturedApiReceiptsSettled(evidence) {
    await expect.poll(() => [...evidence.apiRequests].every(request => evidence.finishedRequests.has(request))).toBe(true);
    expect(evidence.failedRequests).toEqual([]);
    expect(evidence.malformedDiagnostics).toEqual([]);
    expect(evidence.rejectedWrites).toEqual([]);
    expect(evidence.invalidNotificationRequests).toEqual([]);
    expect(evidence.notificationRequests.length).toBeGreaterThan(0);
    const notificationReceiptRequests = evidence.notificationReceipts.map(receipt => receipt.request);
    expect(new Set(notificationReceiptRequests).size).toBe(notificationReceiptRequests.length);
    for (const request of evidence.notificationRequests) {
        expect(notificationReceiptRequests).toContain(request);
    }
    expect(notificationReceiptRequests).toHaveLength(evidence.notificationRequests.length);
    for (const request of evidence.apiRequests) {
        const response = await request.response();
        expect(response).not.toBeNull();
        const status = response.status();
        const reason = status === 501 ? 'fixture-unmocked-501' : 'status-outside-fixture-allowance';
        const label = safeRequestEvidence(request, reason, status);
        expect(status < 400 || status === 503,
            `${label.method} ${label.path} returned HTTP ${label.status}; reason=${label.reason}; truncated=${label.truncated}`).toBe(true);
        expect(await response.finished()).toBeNull();
    }
    for (const receipt of [...evidence.cashReceipts, ...evidence.notificationReceipts, ...evidence.diagnostics]) {
        const response = await receipt.request.response();
        expect(response).not.toBeNull();
        expect(response.status()).toBe(receipt.status);
        expect(await response.finished()).toBeNull();
        expect(await response.json()).toEqual(receipt.body);
        if (evidence.diagnostics.includes(receipt)) {
            expect(receipt.request.postDataJSON()).toMatchObject({ method: 'post', endpoint: '/api/cash/deposit', status: 503 });
        } else if (evidence.notificationReceipts.includes(receipt)) {
            const url = new URL(receipt.request.url());
            expect(receipt.origin).toBe(ORIGIN);
            expect(receipt.method).toBe('GET');
            expect(receipt.path).toBe('/api/notification-center');
            expect(receipt.query).toBe('?per_page=5');
            expect(receipt.profileId === null || receipt.profileId === '1').toBe(true);
            expect(url.username).toBe('');
            expect(url.password).toBe('');
            expect(receipt.status).toBe(200);
            expect(receipt.body).toEqual(NOTIFICATION_EMPTY_RESPONSE);
        } else {
            expect(receipt.origin).toBe(ORIGIN);
            expect(receipt.profileId).toBe(String(TEST_PORTFOLIO.id));
            if (receipt.path === '/api/cash') {
                expect(receipt.method).toBe('GET');
                expect(receipt.query).toBe('?include_reservations=true');
            } else if (receipt.path === '/api/cash/statement') {
                expect(receipt.method).toBe('GET');
                expect(receipt.query).toBe('?per_page=100');
            } else {
                expect(receipt.path).toBe('/api/cash/deposit');
                expect(receipt.method).toBe('POST');
                expect(receipt.query).toBe('');
                expect(receipt.request.postDataJSON()).toEqual(DEPOSIT);
            }
        }
    }
}

test.afterEach(({ page }, testInfo) => {
    if (testInfo.status === testInfo.expectedStatus) return;
    const evidence = failureEvidenceByPage.get(page);
    if (!evidence) return;

    const bounded = values => ({ items: values.slice(0, 40), truncated: values.length > 40 });
    const apiRequests = [...evidence.apiRequests].map(request => {
        const status = evidence.apiResponseStatuses.get(request) ?? null;
        const reason = status === 501
            ? 'fixture-unmocked-501'
            : status !== null ? 'response-received'
            : evidence.finishedRequests.has(request) ? 'finished-without-response' : 'request-pending';
        return safeRequestEvidence(request, reason, status);
    });
    // Failure-only output is limited to fixed test title and method/path/status/reason metadata.
    // It deliberately excludes request URLs with queries, headers, bodies, and response bodies.
    console.error(`[cash-uncertain-deposit] ${JSON.stringify({
        test: testInfo.title.slice(0, 200),
        testTitleTruncated: testInfo.title.length > 200,
        assertionFailed: true,
        apiRequests: bounded(apiRequests),
        failedRequests: bounded(evidence.failedRequests),
        rejectedWrites: bounded(evidence.rejectedWrites),
        malformedDiagnostics: bounded(evidence.malformedDiagnostics),
    })}`);
});

for (const viewport of VIEWPORTS) {
    test.describe(`Cash uncertain deposit recovery ${viewport.name}`, () => {
        test.use({ hasTouch: viewport.name === 'mobile' });

        test(`E2E-22 inspects the statement before deciding not to repeat a possibly committed deposit (${viewport.name})`, async ({ page }, testInfo) => {
            journeyId(testInfo, 'E2E-22');
            const evidence = await prepare(page, viewport, 'uncertain-commit');
            await page.goto('/cash');
            const baseline = page.getByRole('row').filter({ has: page.getByRole('cell', { name: 'Prior unrelated funding', exact: true }) });
            await expect(baseline).toHaveCount(1);
            await expect(baseline.getByRole('cell').nth(5)).toContainText('12,500');
            const statementCard = page.locator('.card').filter({ hasText: 'Cash account statement' });
            await cashHeaderRefresh(page);

            const form = await fillDepositForm(page);
            const uncertainResponsePromise = page.waitForResponse(response => new URL(response.url()).pathname === '/api/cash/deposit' && response.status() === 503);
            await form.getByRole('button', { name: 'Deposit', exact: true }).click();
            const uncertainResponse = await uncertainResponsePromise;
            expect(await uncertainResponse.finished()).toBeNull();
            expect(await uncertainResponse.json()).toEqual({ message: FAILURE_MESSAGE });
            await expect(page.getByText(FAILURE_MESSAGE, { exact: true })).toBeVisible();
            await expect(page.getByLabel('Amount', { exact: true })).toHaveValue('3000');
            await expect(page.getByLabel('Transaction date', { exact: true })).toHaveValue('10-Oct-2026');
            await expect(page.getByLabel('Remarks', { exact: true })).toHaveValue(DEPOSIT.remarks);
            await expect(page.getByText('Deposit recorded', { exact: true })).toHaveCount(0);
            await expect(page.getByRole('row').filter({ has: page.getByRole('cell', { name: DEPOSIT.remarks, exact: true }) })).toHaveCount(0);
            await expect(baseline).toHaveCount(1);
            await expect(statementCard.locator('.card-header')).toContainText('12,500');
            expect(evidence.attempts).toHaveLength(1);
            expect(evidence.entries).toHaveLength(2);

            const refreshResponsePromise = page.waitForResponse(response => new URL(response.url()).pathname === '/api/cash/statement' && response.status() === 200);
            const refresh = await cashHeaderRefresh(page);
            await refresh.click();
            const refreshResponse = await refreshResponsePromise;
            const refreshed = await refreshResponse.json();
            expect(refreshed.data.entries.map(entry => ({ id: entry.id, date: entry.entry_date, type: entry.entry_type, amount: entry.amount, balance: entry.running_balance, remarks: entry.reason }))).toEqual([
                { id: BASELINE_ENTRY.id, date: BASELINE_ENTRY.entry_date, type: 'deposit', amount: 2500, balance: 12500, remarks: BASELINE_ENTRY.reason },
                { id: 4102, date: DEPOSIT.transaction_date, type: 'deposit', amount: DEPOSIT.amount, balance: 15500, remarks: DEPOSIT.remarks },
            ]);
            expect(refreshResponse.request().headers()['x-profile-id']).toBe(String(TEST_PORTFOLIO.id));
            expect(refreshResponse.request().postData()).toBeNull();
            await expectDepositRow(page, 15500);
            expect(evidence.attempts).toHaveLength(1);

            await page.reload();
            await expectDepositRow(page, 15500);
            expect(evidence.entries.filter(entry => entry.reason === DEPOSIT.remarks)).toHaveLength(1);
            expect(evidence.attempts).toHaveLength(1);
            await expectCapturedApiReceiptsSettled(evidence);
            expect(evidence.rejectedWrites).toEqual([]);
            expect(evidence.attempts).toEqual([{
                method: 'POST', origin: ORIGIN, path: '/api/cash/deposit', query: '',
                profileId: String(TEST_PORTFOLIO.id), payload: DEPOSIT,
            }]);
        });

        test(`E2E-22 checks positive unrelated statement evidence before deliberately retrying a known non-commit (${viewport.name})`, async ({ page }, testInfo) => {
            journeyId(testInfo, 'E2E-22');
            const evidence = await prepare(page, viewport, 'known-no-commit');
            await page.goto('/cash');
            const baseline = page.getByRole('row').filter({ has: page.getByRole('cell', { name: 'Prior unrelated funding', exact: true }) });
            await expect(baseline).toHaveCount(1);
            await expect(baseline.getByRole('cell').nth(5)).toContainText('12,500');

            const form = await fillDepositForm(page);
            const failedResponsePromise = page.waitForResponse(response => new URL(response.url()).pathname === '/api/cash/deposit' && response.status() === 503);
            await form.getByRole('button', { name: 'Deposit', exact: true }).click();
            const failedResponse = await failedResponsePromise;
            expect(await failedResponse.finished()).toBeNull();
            expect(await failedResponse.json()).toEqual({ message: FAILURE_MESSAGE });
            await expect(page.getByText(FAILURE_MESSAGE, { exact: true })).toBeVisible();
            await expect(page.getByLabel('Amount', { exact: true })).toHaveValue('3000');
            await expect(page.getByLabel('Transaction date', { exact: true })).toHaveValue('10-Oct-2026');
            await expect(page.getByLabel('Remarks', { exact: true })).toHaveValue(DEPOSIT.remarks);
            expect(evidence.attempts).toHaveLength(1);
            expect(evidence.entries.filter(entry => entry.reason === DEPOSIT.remarks)).toHaveLength(0);

            const refreshResponsePromise = page.waitForResponse(response => new URL(response.url()).pathname === '/api/cash/statement' && response.status() === 200);
            const refresh = await cashHeaderRefresh(page);
            await refresh.click();
            const refreshResponse = await refreshResponsePromise;
            const refreshed = await refreshResponse.json();
            expect(refreshed.data.entries).toEqual([BASELINE_ENTRY]);
            expect(refreshed.data.entries.some(entry => entry.reason === DEPOSIT.remarks)).toBe(false);
            expect(refreshResponse.request().headers()['x-profile-id']).toBe(String(TEST_PORTFOLIO.id));
            const unrelatedAfterRefresh = page.getByRole('row').filter({ has: page.getByRole('cell', { name: 'Prior unrelated funding', exact: true }) });
            await expect(unrelatedAfterRefresh).toHaveCount(1);
            await expect(unrelatedAfterRefresh.getByRole('cell').nth(5)).toContainText('12,500');
            await expect(page.getByRole('row').filter({ has: page.getByRole('cell', { name: DEPOSIT.remarks, exact: true }) })).toHaveCount(0);
            expect(evidence.attempts).toHaveLength(1);

            const successResponsePromise = page.waitForResponse(response => new URL(response.url()).pathname === '/api/cash/deposit' && response.status() === 201);
            await form.getByRole('button', { name: 'Deposit', exact: true }).click();
            const successResponse = await successResponsePromise;
            expect(await successResponse.finished()).toBeNull();
            expect(await successResponse.json()).toEqual({ data: { entry: {
                id: 4102, entry_type: 'deposit', amount: 3000, balance_after: 15500,
                reason: DEPOSIT.remarks, entry_date: DEPOSIT.transaction_date,
            } } });
            await expect(page.getByText('Deposit recorded', { exact: true })).toBeVisible();
            await expect(page.getByLabel('Amount', { exact: true })).toHaveValue('');
            await expectDepositRow(page, 15500);
            expect(evidence.attempts).toHaveLength(2);

            await page.reload();
            await expectDepositRow(page, 15500);
            expect(evidence.entries.filter(entry => entry.reason === DEPOSIT.remarks)).toHaveLength(1);
            expect(evidence.attempts).toHaveLength(2);
            await expectCapturedApiReceiptsSettled(evidence);
            expect(evidence.rejectedWrites).toEqual([]);
            expect(evidence.attempts).toEqual([
                {
                    method: 'POST', origin: ORIGIN, path: '/api/cash/deposit', query: '',
                    profileId: String(TEST_PORTFOLIO.id), payload: DEPOSIT,
                },
                {
                    method: 'POST', origin: ORIGIN, path: '/api/cash/deposit', query: '',
                    profileId: String(TEST_PORTFOLIO.id), payload: DEPOSIT,
                },
            ]);
        });
    });
}
