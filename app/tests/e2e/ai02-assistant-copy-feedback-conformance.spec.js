import { test, expect } from '@playwright/test';
import { journeyId, seedDeterministicJourney } from './journeyTestUtils.js';
import { installInvestorWorkflowApiMocks } from './investorWorkflowApiMocks.js';
import { installApiFailureDiagnosticBoundaryForPage, isApiFailureDiagnosticRequest, registerApiFailureDiagnosticFixtureHooks } from './apiFailureDiagnosticBoundary.js';

registerApiFailureDiagnosticFixtureHooks(test);

const answer = 'A screener filters stocks using conditions you choose.';
const source = {
    source_id: 'journey-screener',
    title: 'Screeners',
    section: 'Create a screener',
    snippet: 'Choose conditions, then run the screener.',
    url: '/docs/screeners.html#creation',
};
const requestId = 'doc-answer-42';
const feedbackPath = '/api/ai/assistant/feedback';
const streamPath = '/api/ai/assistant/stream';

for (const { width, height } of [{ width: 390, height: 844 }, { width: 1440, height: 900 }]) {
    test(`AI-02 copies grounded answer and saves answer-linked feedback at ${width}x${height}`, async ({ page }, testInfo) => {
        journeyId(testInfo, 'AI-02');
        await seedDeterministicJourney(page, 'ai-001-assistant');
        await page.setViewportSize({ width, height });
        await installInvestorWorkflowApiMocks(page);

        await page.addInitScript(() => {
            window.__ux001ClipboardCalls = [];
            Object.defineProperty(navigator, 'clipboard', {
                configurable: true,
                value: { writeText: async text => { window.__ux001ClipboardCalls.push(text); } },
            });
        });
        const allowedOrigin = new URL(process.env.STOX_E2E_BASE_URL || 'http://127.0.0.1:4177').origin;
        const blockedOrigins = [];
        await page.route('**/*', async route => {
            const url = new URL(route.request().url());
            if (url.origin !== allowedOrigin) { blockedOrigins.push(url.origin); return route.abort(); }
            return route.fallback();
        });
        const observed = [];
        const forbiddenAccountCalls = [];
        page.on('request', request => {
            const url = new URL(request.url());
            if (url.pathname.startsWith('/api/')) {
                observed.push({ request, origin: url.origin, path: url.pathname, method: request.method() });
                if (/^(POST|PUT|PATCH|DELETE)$/.test(request.method())
                    && !isApiFailureDiagnosticRequest(request, allowedOrigin)
                    && !['/api/ai/assistant/stream', feedbackPath].includes(url.pathname)) {
                    forbiddenAccountCalls.push(url.pathname);
                }
            }
        });

        const responses = [];
        page.on('response', response => {
            const url = new URL(response.url());
            if (url.pathname === streamPath || url.pathname === feedbackPath) responses.push(response);
        });
        await page.route(`**${streamPath}`, async route => {
            const request = route.request();
            expect(request.method()).toBe('POST');
            expect(request.postDataJSON()).toMatchObject({ question: 'How do I create a screener?' });
            await route.fulfill({
                status: 200,
                contentType: 'text/event-stream',
                body: `event: message.delta\ndata: ${JSON.stringify({ text: answer })}\n\nevent: message.completed\ndata: ${JSON.stringify({
                    grounding: 'grounded',
                    request_id: requestId,
                    provenance: [source],
                })}\n\n`,
            });
        });

        const feedbackPayloads = [];
        await page.route(`**${feedbackPath}`, async route => {
            const request = route.request();
            expect(request.method()).toBe('POST');
            const payload = request.postDataJSON();
            feedbackPayloads.push(payload);
            await route.fulfill(feedbackPayloads.length === 2
                ? { status: 503, contentType: 'application/json', body: JSON.stringify({ message: 'Temporary fixture failure' }) }
                : { status: 200, contentType: 'application/json', body: JSON.stringify({ data: { saved: true } }) });
        });

        // Keep generated 503 diagnostics within the fixture's strict boundary.
        await installApiFailureDiagnosticBoundaryForPage(page);

        await page.goto('/');
        await page.getByRole('button', { name: 'Open StoX assistant' }).click();
        const drawer = page.getByRole('dialog', { name: 'StoX assistant' });
        await drawer.getByLabel('Ask about StoX').fill('How do I create a screener?');
        await drawer.getByRole('button', { name: 'Ask', exact: true }).click();
        await expect(drawer.getByText(answer, { exact: true })).toBeVisible();
        await expect(drawer.getByText('Grounded', { exact: true })).toBeVisible();
        await drawer.getByText('Sources used (1)').click();
        const sourceLink = drawer.getByRole('link', { name: 'Screeners — Create a screener' });
        await expect(sourceLink).toHaveAttribute('href', source.url);
        await expect(drawer.getByText(source.snippet)).toBeVisible();

        await drawer.getByRole('button', { name: 'Copy response' }).click();
        const expectedCopy = `${answer}\n\n${source.title}: ${new URL(source.url, page.url()).href}`;
        await expect.poll(() => page.evaluate(() => window.__ux001ClipboardCalls)).toEqual([expectedCopy]);
        await expect(drawer.getByRole('status')).toHaveText('Response copied.');

        await drawer.getByRole('button', { name: 'Helpful', exact: true }).click();
        await expect(drawer.getByRole('status')).toHaveText('Feedback saved.');
        await drawer.getByText('Not helpful').click();
        await drawer.getByLabel('Optional comment').fill('The steps need a clearer prerequisite.');
        await drawer.getByRole('button', { name: 'Send feedback' }).click();
        await expect(drawer.getByRole('status')).toHaveText('Feedback could not be saved. Please try again.');
        await drawer.getByRole('button', { name: 'Send feedback' }).click();
        await expect(drawer.getByRole('status')).toHaveText('Feedback saved.');

        expect(feedbackPayloads).toEqual([
            { request_id: requestId, helpful: true, comment: '' },
            { request_id: requestId, helpful: false, comment: 'The steps need a clearer prerequisite.' },
            { request_id: requestId, helpful: false, comment: 'The steps need a clearer prerequisite.' },
        ]);
        expect(observed.filter(item => item.path === streamPath)).toHaveLength(1);
        expect(observed.filter(item => item.path === feedbackPath)).toHaveLength(3);
        expect(observed.every(item => item.origin === new URL(page.url()).origin)).toBe(true);
        expect(forbiddenAccountCalls).toEqual([]);
        expect(blockedOrigins).toEqual([]);
        const mutatingRequests = observed.filter(item => /^(POST|PUT|PATCH|DELETE)$/.test(item.method)
            && !isApiFailureDiagnosticRequest(item.request, allowedOrigin));
        expect(mutatingRequests.every(item => item.method === 'POST')).toBe(true);
        expect(mutatingRequests.map(item => item.path).sort()).toEqual([feedbackPath, feedbackPath, feedbackPath, streamPath].sort());
        expect(responses).toHaveLength(4);
        for (const response of responses) {
            expect(await response.finished()).toBeNull();
            const body = await response.body();
            expect(body.length).toBeGreaterThan(0);
        }
        expect(responses.map(response => response.status())).toEqual([200, 200, 503, 200]);
    });
}
