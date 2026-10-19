import { test, expect } from '@playwright/test';
import { journeyId, seedDeterministicJourney } from './journeyTestUtils.js';
import { installInvestorWorkflowApiMocks } from './investorWorkflowApiMocks.js';

const VIEWPORTS = [
    { name: 'mobile', width: 390, height: 844 },
    { name: 'desktop', width: 1440, height: 900 },
];
const RUNS_PATH = '/api/ai/assistant/runs';
const OBJECTIVE = 'How concentrated are my holdings, and which prices are unavailable?';
const SAVED_RUN = {
    id: 'dc5f0b8c-ece8-4d17-a1fb-2a2b3b889e01',
    user_id: 1,
    account_id: 1,
    profile_id: 1,
    objective: OBJECTIVE,
    status: 'completed',
    plan: [],
    plan_hash: null,
    preview: [],
    approval_expires_at: null,
    approved_at: null,
    delegation_expires_at: '2026-10-19T00:15:00.000000Z',
    steps: [],
    trace: [
        { tool: 'portfolio.holdings', status: 'available', result_summary: '8 holdings' },
        { tool: 'portfolio.concentration', status: 'available', result_summary: 'Largest holding: 18%' },
        { tool: 'market.prices', status: 'partial', result_summary: '2 prices unavailable' },
    ],
    answer: 'The largest holding is 18% of the portfolio. Prices for two holdings are unavailable, so the price evidence is incomplete.',
    correlation_id: 'synthetic-ai04-recovery',
    created_at: '2026-10-19T00:00:00.000000Z',
    updated_at: '2026-10-19T00:00:01.000000Z',
};

function isRunRequest(response, method, origin) {
    const request = response.request();
    const url = new URL(response.url());
    return request.method() === method && url.origin === origin && url.pathname === RUNS_PATH;
}

for (const viewport of VIEWPORTS) {
    test(`AI-04/AI-06 recover an uncertain read-only investigation from run history (${viewport.name})`, async ({ page }, testInfo) => {
        journeyId(testInfo, 'AI-04');
        journeyId(testInfo, 'AI-06');
        await seedDeterministicJourney(page, `ai-04-history-recovery-${viewport.name}`);
        await page.setViewportSize({ width: viewport.width, height: viewport.height });
        const allowedOrigin = new URL(process.env.STOX_E2E_BASE_URL || 'http://127.0.0.1:4177').origin;
        const observedRequests = [];
        const investigationPayloads = [];
        let explicitHistoryReads = 0;

        page.on('request', (request) => {
            const url = new URL(request.url());
            observedRequests.push({ method: request.method(), origin: url.origin, path: url.pathname, request });
        });
        await installInvestorWorkflowApiMocks(page);
        await page.route('**/api/ai/assistant/runs**', async (route) => {
            const request = route.request();
            const url = new URL(request.url());
            expect(url.origin).toBe(allowedOrigin);
            expect(url.pathname).toBe(RUNS_PATH);

            if (request.method() === 'POST') {
                const payload = request.postDataJSON();
                expect(payload).toEqual({ objective: OBJECTIVE });
                investigationPayloads.push(payload);
                await route.fulfill({
                    status: 503,
                    contentType: 'application/json',
                    body: JSON.stringify({ success: false, error: { code: 'backend_unavailable' } }),
                });
                return;
            }

            expect(request.method()).toBe('GET');
            explicitHistoryReads += 1;
            if (explicitHistoryReads === 1) {
                await route.fulfill({
                    status: 503,
                    contentType: 'application/json',
                    body: JSON.stringify({ success: false, error: { code: 'backend_unavailable' } }),
                });
                return;
            }

            expect(explicitHistoryReads).toBe(2);
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({ success: true, data: [SAVED_RUN] }),
            });
        });

        await page.goto('/');
        await page.getByRole('button', { name: 'Open StoX assistant' }).click();
        const drawer = page.getByRole('dialog', { name: 'StoX assistant' });
        await drawer.getByLabel('Assistant mode').selectOption('account');
        await drawer.getByLabel('Ask about StoX').fill(OBJECTIVE);

        const investigationResponsePromise = page.waitForResponse((response) => isRunRequest(response, 'POST', allowedOrigin));
        await drawer.getByRole('button', { name: 'Ask', exact: true }).click();
        const investigationResponse = await investigationResponsePromise;
        expect(investigationResponse.status()).toBe(503);
        expect(await investigationResponse.finished()).toBeNull();
        expect(JSON.parse((await investigationResponse.body()).toString())).toEqual({ success: false, error: { code: 'backend_unavailable' } });
        expect(investigationResponse.request().postDataJSON()).toEqual({ objective: OBJECTIVE });
        await expect(drawer.getByText('The investigation could not finish. Check run history for its current state.', { exact: true })).toBeVisible();
        await expect(drawer.getByText(SAVED_RUN.answer, { exact: true })).toHaveCount(0);

        const firstHistoryResponsePromise = page.waitForResponse((response) => isRunRequest(response, 'GET', allowedOrigin));
        await drawer.getByRole('button', { name: 'Run history', exact: true }).click();
        const firstHistoryResponse = await firstHistoryResponsePromise;
        expect(firstHistoryResponse.status()).toBe(503);
        expect(await firstHistoryResponse.finished()).toBeNull();
        expect(JSON.parse((await firstHistoryResponse.body()).toString())).toEqual({ success: false, error: { code: 'backend_unavailable' } });
        await expect(drawer.getByText('Run history is temporarily unavailable.', { exact: true })).toBeVisible();
        await expect(drawer.getByText('The investigation could not finish. Check run history for its current state.', { exact: true })).toHaveCount(0);
        await expect(drawer.getByRole('article', { name: 'Assistant run' })).toHaveCount(0);

        const recoveredHistoryResponsePromise = page.waitForResponse((response) => isRunRequest(response, 'GET', allowedOrigin));
        await drawer.getByRole('button', { name: 'Run history', exact: true }).click();
        const recoveredHistoryResponse = await recoveredHistoryResponsePromise;
        expect(recoveredHistoryResponse.status()).toBe(200);
        expect(await recoveredHistoryResponse.finished()).toBeNull();
        expect(JSON.parse((await recoveredHistoryResponse.body()).toString())).toEqual({ success: true, data: [SAVED_RUN] });

        const savedRecord = drawer.getByRole('article', { name: 'Assistant run' });
        await expect(savedRecord).toBeVisible();
        await expect(savedRecord.getByRole('heading', { name: OBJECTIVE, exact: true })).toBeVisible();
        await expect(savedRecord).toContainText('Portfolio 1');
        await expect(savedRecord).toContainText('Completed');
        await expect(savedRecord).toContainText(SAVED_RUN.answer);
        await savedRecord.getByText('Investigation trace', { exact: true }).click();
        await expect(savedRecord).toContainText('portfolio holdings — available');
        await expect(savedRecord).toContainText('portfolio concentration — available');
        await expect(savedRecord).toContainText('market prices — partial');
        await expect(savedRecord.getByRole('region', { name: 'Mutation preview' })).toHaveCount(0);
        await expect(savedRecord.getByRole('button', { name: 'Approve changes', exact: true })).toHaveCount(0);
        await expect(savedRecord.getByRole('button', { name: 'Reject plan', exact: true })).toHaveCount(0);
        await expect(savedRecord.getByRole('list', { name: 'Action results' })).toHaveCount(0);

        expect(investigationPayloads).toEqual([{ objective: OBJECTIVE }]);
        expect(explicitHistoryReads).toBe(2);
        const httpRequests = observedRequests.filter(({ request }) => /^https?:/i.test(request.url()));
        expect(httpRequests.length).toBeGreaterThan(0);
        expect(httpRequests.every(({ origin }) => origin === allowedOrigin)).toBe(true);
        const mutations = observedRequests.filter(({ method }) => /^(POST|PUT|PATCH|DELETE)$/.test(method));
        expect(mutations.map(({ method, origin, path }) => ({ method, origin, path }))).toEqual([
            { method: 'POST', origin: allowedOrigin, path: RUNS_PATH },
        ]);
        expect(observedRequests.filter(({ method, origin, path }) => method === 'GET' && origin === allowedOrigin && path === RUNS_PATH)).toHaveLength(2);
    });
}
