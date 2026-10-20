import { test, expect } from '@playwright/test';
import { journeyId, seedDeterministicJourney } from './journeyTestUtils.js';
import { installInvestorWorkflowApiMocks } from './investorWorkflowApiMocks.js';
import { TEST_PORTFOLIO } from '../js/tos/fixtures/tosApi.js';

const ORIGIN = 'http://127.0.0.1:4177';
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
    const apiRequests = new Set();
    const finishedRequests = new Set();
    const failedRequests = [];
    let committed = false;

    page.on('request', request => {
        const url = new URL(request.url());
        if (url.origin === ORIGIN && url.pathname.startsWith('/api/')) apiRequests.add(request);
    });
    page.on('requestfinished', request => finishedRequests.add(request));
    page.on('requestfailed', request => {
        if (apiRequests.has(request)) {
            failedRequests.push(request.url().split('?')[0]);
            finishedRequests.add(request);
        }
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
            rejectedWrites.push(`${request.method()} ${url.origin}${url.pathname}`);
            return route.abort();
        }
        if (url.origin !== ORIGIN) {
            rejectedWrites.push(`${request.method()} ${url.origin}${url.pathname}`);
            return route.abort();
        }
        if (url.pathname === '/api/ops/api-failures') {
            if (diagnosticPayloadIsValid(request, url)) {
                const body = { data: { accepted: true } };
                diagnostics.push({ request, path: url.pathname, status: 202, body: JSON.parse(JSON.stringify(body)) });
                return route.fulfill({ status: 202, contentType: 'application/json', body: JSON.stringify(body) });
            }
            malformedDiagnostics.push(`${request.method()} ${url.pathname}`);
            rejectedWrites.push(`${request.method()} ${url.origin}${url.pathname}`);
            return route.abort();
        }
        if (!mutation) return route.fallback();

        if (request.method() === 'POST' && url.pathname === '/api/cash/deposit' && url.search === '') {
            return route.fallback();
        }
        rejectedWrites.push(`${request.method()} ${url.origin}${url.pathname}`);
        return route.abort();
    });

    return { entries, attempts, rejectedWrites, malformedDiagnostics, diagnostics, cashReceipts, apiRequests, finishedRequests, failedRequests };
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
    for (const request of evidence.apiRequests) {
        const response = await request.response();
        expect(response).not.toBeNull();
        expect(response.status() < 400 || response.status() === 503).toBe(true);
        expect(await response.finished()).toBeNull();
    }
    for (const receipt of [...evidence.cashReceipts, ...evidence.diagnostics]) {
        const response = await receipt.request.response();
        expect(response).not.toBeNull();
        expect(response.status()).toBe(receipt.status);
        expect(await response.finished()).toBeNull();
        expect(await response.json()).toEqual(receipt.body);
        if (evidence.diagnostics.includes(receipt)) {
            expect(receipt.request.postDataJSON()).toMatchObject({ method: 'post', endpoint: '/api/cash/deposit', status: 503 });
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
