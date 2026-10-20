import { test, expect } from '@playwright/test';
import { journeyId, seedDeterministicJourney } from './journeyTestUtils.js';
import { installInvestorWorkflowApiMocks } from './investorWorkflowApiMocks.js';
import { installApiFailureDiagnosticBoundaryForPage, isApiFailureDiagnosticRequest, registerApiFailureDiagnosticFixtureHooks } from './apiFailureDiagnosticBoundary.js';

registerApiFailureDiagnosticFixtureHooks(test);

const VIEWPORTS = [
    { name: 'mobile', width: 390, height: 844 },
    { name: 'desktop', width: 1440, height: 900 },
];
const STREAM_PATH = '/api/ai/assistant/stream';

function deferred() {
    let resolve;
    const promise = new Promise((yes) => { resolve = yes; });
    return { promise, resolve };
}

function completedStream(text, requestId, sourceId) {
    return [
        `event: message.delta\ndata: ${JSON.stringify({ text })}`,
        `event: message.completed\ndata: ${JSON.stringify({
            request_id: requestId,
            grounding: 'grounded',
            provenance: [{
                source_id: sourceId,
                title: `${sourceId} source`,
                section: 'Reviewed section',
                snippet: `${text} support`,
                url: `/docs/${sourceId}.html`,
            }],
        })}`,
    ].join('\n\n') + '\n\n';
}

for (const viewport of VIEWPORTS) {
    test(`AI-02 clears a pending documentation answer in the mounted session (${viewport.name})`, async ({ page }, testInfo) => {
        journeyId(testInfo, 'AI-02');
        await seedDeterministicJourney(page, `ai-02-pending-clear-${viewport.name}`);
        await page.setViewportSize({ width: viewport.width, height: viewport.height });

        const allowedOrigin = new URL(process.env.STOX_E2E_BASE_URL || 'http://127.0.0.1:4177').origin;
        const blockedOrigins = [];
        const requests = [];
        const assistantPayloads = [];
        const firstGate = deferred();
        const firstHandlerDone = deferred();
        let firstRequest = null;
        let streamHandlerCount = 0;

        await page.route('**/*', async (route) => {
            const url = new URL(route.request().url());
            if (url.origin !== allowedOrigin) {
                blockedOrigins.push(url.origin);
                return route.abort();
            }
            return route.fallback();
        });
        await installInvestorWorkflowApiMocks(page);
        page.on('request', (request) => {
            const url = new URL(request.url());
            requests.push({ method: request.method(), origin: url.origin, path: url.pathname, request });
        });
        await page.route(`**${STREAM_PATH}`, async (route) => {
            const request = route.request();
            expect(request.method()).toBe('POST');
            expect(new URL(request.url()).origin).toBe(allowedOrigin);
            const body = request.postDataJSON();
            assistantPayloads.push(body);
            streamHandlerCount++;

            if (streamHandlerCount === 1) {
                firstRequest = request;
                expect(body.question).toBe('First pending documentation question');
                expect(body.conversation).toEqual([]);
                try {
                    await firstGate.promise;
                    // Clear aborts the browser fetch. Do not fabricate a response for that cancelled stream.
                    try { await route.abort(); } catch (error) {
                        if (!request.failure()) throw error;
                    }
                } finally {
                    firstHandlerDone.resolve();
                }
                return;
            }

            expect(streamHandlerCount).toBe(2);
            expect(body.question).toBe('Current question after clear');
            expect(body.conversation).toEqual([]);
            await route.fulfill({
                status: 200,
                contentType: 'text/event-stream',
                body: completedStream('Current answer remains authoritative', 'current-request', 'current'),
            });
        });

        // Register after the shared API mocks so the strict diagnostic handler
        // is the first matching route for reports from this fixture.
        await installApiFailureDiagnosticBoundaryForPage(page);

        await page.goto('/');
        await page.getByRole('button', { name: 'Open StoX assistant' }).click();
        const drawer = page.getByRole('dialog', { name: 'StoX assistant' });
        const question = drawer.getByLabel('Ask about StoX');

        await question.fill('First pending documentation question');
        const firstRequestPromise = page.waitForRequest((request) => {
            const url = new URL(request.url());
            return request.method() === 'POST' && url.origin === allowedOrigin && url.pathname === STREAM_PATH;
        });
        await drawer.getByRole('button', { name: 'Ask', exact: true }).click();
        firstRequest = await firstRequestPromise;
        await expect(drawer.getByText('Checking documentation', { exact: true })).toBeVisible();
        await expect(drawer.getByText('First pending documentation question', { exact: true })).toBeVisible();
        const firstFailurePromise = page.waitForEvent('requestfailed', (request) => (
            request.method() === 'POST'
            && request.url() === firstRequest.url()
            && request.postDataJSON().question === 'First pending documentation question'
        ));

        await drawer.getByRole('button', { name: 'Clear conversation' }).click();
        await expect(drawer.getByText('Conversation cleared.', { exact: true })).toBeVisible();
        await expect(drawer.locator('article')).toHaveCount(0);
        await firstFailurePromise;
        expect(firstRequest.failure()).not.toBeNull();
        expect(await firstRequest.response()).toBeNull();
        await expect(drawer.getByText('The assistant is temporarily unavailable. You can still use How do I? help.', { exact: true })).toHaveCount(0);
        firstGate.resolve();
        await firstHandlerDone.promise;

        await question.fill('Current question after clear');
        const currentResponsePromise = page.waitForResponse((response) => {
            const request = response.request();
            const url = new URL(response.url());
            return request.method() === 'POST' && url.origin === allowedOrigin && url.pathname === STREAM_PATH
                && request.postDataJSON().question === 'Current question after clear';
        });
        await drawer.getByRole('button', { name: 'Ask', exact: true }).click();
        const currentResponse = await currentResponsePromise;
        expect(currentResponse.status()).toBe(200);
        expect(await currentResponse.finished()).toBeNull();
        const responseBody = await currentResponse.body();
        expect(responseBody.toString()).toContain('Current answer remains authoritative');

        await expect(drawer.getByText('Current answer remains authoritative', { exact: true })).toBeVisible();
        await expect(drawer.getByText('Grounded', { exact: true })).toBeVisible();
        await drawer.getByText('Sources used (1)', { exact: true }).click();
        await expect(drawer.getByRole('link', { name: 'current source — Reviewed section' })).toHaveAttribute('href', /docs\/current\.html/);
        await expect(drawer.getByText('Current answer remains authoritative support', { exact: true })).toBeVisible();
        await expect(drawer.getByText('First pending documentation question', { exact: true })).toHaveCount(0);
        await expect(drawer.getByText('Current question after clear', { exact: true })).toBeVisible();

        const assistantRequests = requests.filter((entry) => entry.path?.startsWith('/api/ai/assistant/'));
        expect(assistantRequests.map((entry) => ({ method: entry.method, path: entry.path }))).toEqual([
            { method: 'POST', path: STREAM_PATH },
            { method: 'POST', path: STREAM_PATH },
        ]);
        expect(assistantRequests.filter((entry) => entry.path !== STREAM_PATH)).toEqual([]);
        expect(assistantPayloads).toHaveLength(2);
        expect(assistantPayloads.map((body) => body.question)).toEqual([
            'First pending documentation question',
            'Current question after clear',
        ]);
        expect(blockedOrigins).toEqual([]);

        // The frozen session contract is in-memory: a normal page refresh starts with a new conversation.
        await page.reload();
        await page.getByRole('button', { name: 'Open StoX assistant' }).click();
        const refreshedDrawer = page.getByRole('dialog', { name: 'StoX assistant' });
        await expect(refreshedDrawer.getByLabel('Ask about StoX')).toHaveValue('');
        await expect(refreshedDrawer.locator('article')).toHaveCount(0);
        await expect(refreshedDrawer.getByText('First pending documentation question', { exact: true })).toHaveCount(0);
        await expect(refreshedDrawer.getByText('Current question after clear', { exact: true })).toHaveCount(0);
        await expect(refreshedDrawer.getByText('Current answer remains authoritative', { exact: true })).toHaveCount(0);

        const mutatingRequests = requests.filter((entry) => /^(POST|PUT|PATCH|DELETE)$/.test(entry.method)
            && !isApiFailureDiagnosticRequest(entry.request, allowedOrigin));
        expect(mutatingRequests.filter((entry) => entry.path !== STREAM_PATH)).toEqual([]);
        expect(streamHandlerCount).toBe(2);
    });
}
