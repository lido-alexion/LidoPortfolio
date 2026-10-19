import { test, expect } from '@playwright/test';
import { journeyId, seedDeterministicJourney } from './journeyTestUtils.js';
import { installInvestorWorkflowApiMocks } from './investorWorkflowApiMocks.js';

const expired = {
    id: '11111111-1111-4111-8111-111111111111',
    profile_id: 1,
    objective: 'Create the Momentum watchlist',
    status: 'expired',
    plan_hash: '1'.repeat(64),
    approval_expires_at: '2000-01-01T00:00:00Z',
    plan: [{ tool: 'watchlist.create', arguments: { name: 'Momentum' }, reason: 'Create the requested list' }],
    preview: [{ tool: 'watchlist.create', arguments: { name: 'Momentum' }, side_effect: 'mutation', consequence: 'Create watchlist Momentum', reason: 'Prepare the requested list', validation: 'passed', changes: [{ field: 'name', before: null, after: 'Momentum' }] }],
    trace: [], steps: [], answer: null,
};

const stale = {
    id: '22222222-2222-4222-8222-222222222222',
    profile_id: 1,
    objective: 'Rename the Core watchlist',
    status: 'stale',
    plan_hash: '2'.repeat(64),
    approval_expires_at: '2099-01-01T00:00:00Z',
    plan: [{ tool: 'watchlist.rename', arguments: { id: 61, name: 'Core Plus' }, reason: 'Rename the selected list' }],
    preview: [{ tool: 'watchlist.rename', arguments: { id: 61, name: 'Core Plus' }, side_effect: 'mutation', consequence: 'Rename watchlist Core to Core Plus', reason: 'Update the selected list name', validation: 'passed', affected_object_id: 61, changes: [{ field: 'name', before: 'Core', after: 'Core Plus' }] }],
    trace: [], steps: [], answer: null,
};

const freshExpired = {
    ...expired,
    id: '33333333-3333-4333-8333-333333333333',
    status: 'awaiting_approval',
    plan_hash: '3'.repeat(64),
    approval_expires_at: '2099-01-01T00:00:00Z',
    plan: [{ tool: 'watchlist.create', arguments: { name: 'Momentum Research' }, reason: 'Use the current reviewed name' }],
    preview: [{ tool: 'watchlist.create', arguments: { name: 'Momentum Research' }, side_effect: 'mutation', consequence: 'Create revised Momentum Research list', reason: 'Use the current reviewed name', validation: 'passed', changes: [{ field: 'name', before: null, after: 'Momentum Research' }] }],
};

const freshStale = {
    ...stale,
    id: '44444444-4444-4444-8444-444444444444',
    status: 'awaiting_approval',
    plan_hash: '4'.repeat(64),
    approval_expires_at: '2099-01-01T00:00:00Z',
    plan: [{ tool: 'watchlist.rename', arguments: { id: 62, name: 'Core Plus' }, reason: 'Use the current selected list' }],
    preview: [{ tool: 'watchlist.rename', arguments: { id: 62, name: 'Core Plus' }, side_effect: 'mutation', consequence: 'Apply Core Plus name to current list', reason: 'Rebuild against the current selected list', validation: 'passed', affected_object_id: 62, changes: [{ field: 'name', before: 'Core', after: 'Core Plus' }] }],
};

const envelope = data => ({ success: true, data });
const clone = value => structuredClone(value);
const runPath = '/api/ai/assistant/runs';

async function expectSettledJson(response, expected) {
    expect(response).toBeTruthy();
    expect(response.status()).toBe(200);
    expect(await response.finished()).toBeNull();
    expect(await response.json()).toEqual(expected);
}

for (const viewport of [
    { name: 'mobile', width: 390, height: 844 },
    { name: 'desktop', width: 1440, height: 900 },
]) {
    test(`AI-05/06 expired and stale history requires a fresh reviewed plan (${viewport.name})`, async ({ page }, testInfo) => {
        journeyId(testInfo, 'AI-05');
        journeyId(testInfo, 'AI-06');
        await seedDeterministicJourney(page, `assistant-plan-recovery-${viewport.name}`);
        await page.setViewportSize({ width: viewport.width, height: viewport.height });
        await installInvestorWorkflowApiMocks(page);

        const initialHistory = [clone(expired), clone(stale)];
        const freshByOldId = new Map([[expired.id, clone(freshExpired)], [stale.id, clone(freshStale)]]);
        const currentByFreshId = new Map([[freshExpired.id, clone(freshExpired)], [freshStale.id, clone(freshStale)]]);
        const routePhases = [];

        await page.route('**/api/ai/assistant/runs**', async route => {
            const request = route.request();
            const url = new URL(request.url());
            const path = url.pathname.replace(/\/$/, '');
            const payload = request.method() === 'POST' ? request.postDataJSON() : null;
            let status = 200;
            let body;

            if (request.method() === 'GET' && path === runPath) {
                body = envelope(clone(initialHistory));
            } else if (request.method() === 'POST' && path === runPath) {
                const fresh = freshByOldId.get(payload.retry_run_id);
                if (!fresh) {
                    status = 422;
                    body = { success: false, error: { code: 'unexpected_retry_run' } };
                } else {
                    body = envelope(clone(fresh));
                }
            } else {
                const match = path.match(/^\/api\/ai\/assistant\/runs\/([^/]+)\/approve$/);
                const fresh = match ? currentByFreshId.get(match[1]) : null;
                if (request.method() !== 'POST' || !fresh) {
                    status = 404;
                    body = { success: false, error: { code: 'unexpected_assistant_run_request' } };
                } else {
                    const completed = {
                        ...clone(fresh),
                        status: 'completed',
                        answer: 'The explicitly reviewed changes were completed and verified.',
                        steps: [{ index: 0, tool: fresh.plan[0].tool, status: 'verified', affected_object_id: fresh.preview[0].affected_object_id ?? 77 }],
                    };
                    currentByFreshId.set(fresh.id, completed);
                    body = envelope(completed);
                }
            }

            routePhases.push({ request, status, body, payload });
            await route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) });
        });

        const allRequests = [];
        const allResponses = new Map();
        const finishedRequests = new Set();
        const failedRequests = new Map();
        page.on('request', request => allRequests.push(request));
        page.on('response', response => allResponses.set(response.request(), response));
        page.on('requestfinished', request => finishedRequests.add(request));
        page.on('requestfailed', request => failedRequests.set(request, request.failure()?.errorText || 'unknown failure'));

        await page.goto('/');
        const appOrigin = new URL(page.url()).origin;
        await page.getByRole('button', { name: 'Open StoX assistant' }).click();
        const drawer = page.getByRole('dialog', { name: 'StoX assistant' });
        await drawer.getByLabel('Assistant mode').selectOption('account');

        const historyResponse = page.waitForResponse(response =>
            response.request().method() === 'GET' && new URL(response.url()).pathname.replace(/\/$/, '') === runPath,
        );
        await drawer.getByRole('button', { name: 'Run history' }).click();
        await expectSettledJson(await historyResponse, envelope(initialHistory));
        await expect(drawer.getByText('Approval expired', { exact: true })).toBeVisible();
        await expect(drawer.getByText('State changed — a fresh plan is required', { exact: true })).toBeVisible();

        const recover = async (oldRun, freshRun) => {
            const oldCard = drawer.locator('article[aria-label="Assistant run"]').filter({ hasText: oldRun.preview[0].consequence });
            await expect(oldCard).toHaveCount(1);
            await oldCard.getByText(/Review field changes/).click();
            await expect(oldCard.getByText(`${oldRun.preview[0].changes[0].before ?? 'Unset'} → ${oldRun.preview[0].changes[0].after}`, { exact: true })).toBeVisible();
            if (oldRun.preview[0].affected_object_id) await expect(oldCard.getByText(`Object: ${oldRun.preview[0].affected_object_id}`, { exact: true })).toBeVisible();
            await expect(oldCard.getByRole('button', { name: 'Approve changes' })).toHaveCount(0);
            await expect(oldCard.getByRole('button', { name: 'Reject plan' })).toHaveCount(0);

            const retryResponse = page.waitForResponse(response => {
                const request = response.request();
                return request.method() === 'POST'
                    && new URL(response.url()).pathname.replace(/\/$/, '') === runPath;
            });
            await oldCard.getByRole('button', { name: 'Build a fresh plan' }).click();
            const retryBody = envelope(freshRun);
            await expectSettledJson(await retryResponse, retryBody);

            const freshCard = drawer.locator('article[aria-label="Assistant run"]').filter({ hasText: freshRun.preview[0].consequence });
            await expect(freshCard).toHaveCount(1);
            await expect(freshCard.getByText('Approval required', { exact: true })).toBeVisible();
            await freshCard.getByText(/Review field changes/).click();
            await expect(freshCard.getByText(`${freshRun.preview[0].changes[0].before ?? 'Unset'} → ${freshRun.preview[0].changes[0].after}`, { exact: true })).toBeVisible();
            await expect(freshCard.getByRole('button', { name: 'Approve changes' })).toBeEnabled();
            await expect(oldCard.getByRole('button', { name: 'Approve changes' })).toHaveCount(0);

            const approveResponse = page.waitForResponse(response => {
                const request = response.request();
                return request.method() === 'POST'
                    && new URL(response.url()).pathname === `${runPath}/${freshRun.id}/approve`;
            });
            await freshCard.getByRole('button', { name: 'Approve changes' }).click();
            const completed = {
                ...clone(freshRun),
                status: 'completed',
                answer: 'The explicitly reviewed changes were completed and verified.',
                steps: [{ index: 0, tool: freshRun.plan[0].tool, status: 'verified', affected_object_id: freshRun.preview[0].affected_object_id ?? 77 }],
            };
            await expectSettledJson(await approveResponse, envelope(completed));
            await expect(freshCard.getByText(completed.answer, { exact: true })).toBeVisible();
            await expect(freshCard.getByText(/— verified/)).toBeVisible();
            await expect(oldCard.getByRole('button', { name: 'Approve changes' })).toHaveCount(0);
        };

        await recover(expired, freshExpired);
        await recover(stale, freshStale);

        const httpRequests = allRequests.filter(request => /^https?:$/.test(new URL(request.url()).protocol));
        expect(httpRequests.every(request => new URL(request.url()).origin === appOrigin)).toBe(true);

        const mutations = httpRequests.filter(request => !['GET', 'HEAD', 'OPTIONS'].includes(request.method()));
        expect(mutations.map(request => ({
            method: request.method(),
            path: new URL(request.url()).pathname,
            body: request.url().includes('/api/ai/assistant/runs') ? request.postDataJSON() : null,
        }))).toEqual([
            { method: 'POST', path: runPath, body: { objective: expired.objective, retry_run_id: expired.id } },
            { method: 'POST', path: `${runPath}/${freshExpired.id}/approve`, body: { plan_hash: freshExpired.plan_hash } },
            { method: 'POST', path: runPath, body: { objective: stale.objective, retry_run_id: stale.id } },
            { method: 'POST', path: `${runPath}/${freshStale.id}/approve`, body: { plan_hash: freshStale.plan_hash } },
        ]);

        const explicitHistoryReads = routePhases.filter(phase => phase.request.method() === 'GET' && new URL(phase.request.url()).pathname.replace(/\/$/, '') === runPath);
        expect(explicitHistoryReads.length).toBeGreaterThan(0);
        expect(routePhases.filter(phase => phase.status !== 200)).toEqual([]);
        for (const phase of routePhases) {
            expect(failedRequests.has(phase.request), failedRequests.get(phase.request)).toBe(false);
            const response = allResponses.get(phase.request);
            expect(response).toBeTruthy();
            expect(await response.finished()).toBeNull();
            if (!finishedRequests.has(phase.request)) {
                await page.waitForEvent('requestfinished', { predicate: request => request === phase.request });
            }
            expect(finishedRequests.has(phase.request)).toBe(true);
            expect(response.status()).toBe(phase.status);
            expect(await response.json()).toEqual(phase.body);
        }
        expect(routePhases.filter(phase => phase.request.method() === 'POST').map(phase => phase.payload)).toEqual([
            { objective: expired.objective, retry_run_id: expired.id },
            { plan_hash: freshExpired.plan_hash },
            { objective: stale.objective, retry_run_id: stale.id },
            { plan_hash: freshStale.plan_hash },
        ]);
    });
}
