import { test, expect } from '@playwright/test';
import { journeyId, seedDeterministicJourney } from './journeyTestUtils.js';
import { installInvestorWorkflowApiMocks } from './investorWorkflowApiMocks.js';

const VIEWPORTS = [
    { name: 'mobile', width: 390, height: 844 },
    { name: 'desktop', width: 1440, height: 900 },
];
const STREAM_PATH = '/api/ai/assistant/stream';
const TEMPORARY_UNAVAILABLE = 'The assistant is temporarily unavailable. You can still use How do I? help.';
const QUESTION = 'How do I find maintained StoX documentation when the assistant is unavailable? Please explain how to create a screener, configure its criteria, build a strategy from it, review recommendations, understand portfolio analysis, find supported execution steps, and return to the current page. I need to keep the recovery context while scrolling the help drawer on a narrow screen.';
const CLOSE_QUESTION = 'Where can I browse maintained StoX help after the assistant becomes unavailable?';
const ESCAPE_QUESTION = 'Which documentation page explains how StoX help works during an outage?';
const EXPECTED_QUESTIONS = [QUESTION, CLOSE_QUESTION, ESCAPE_QUESTION];

async function askAndSettleUnavailable(page, drawer, question, payloads, mountedQuestions, settledResponses) {
    const input = drawer.getByRole('textbox', { name: 'Ask about StoX' });
    const responsePromise = page.waitForResponse((response) => {
        const request = response.request();
        const url = new URL(response.url());
        return request.method() === 'POST'
            && url.origin === new URL(page.url()).origin
            && url.pathname === STREAM_PATH
            && request.postDataJSON()?.question === question;
    });
    await input.fill(question);
    await drawer.getByRole('button', { name: 'Ask', exact: true }).click();

    const response = await responsePromise;
    expect(response.status()).toBe(503);
    expect(await response.finished()).toBeNull();
    const body = await response.body();
    expect(body.toString()).toContain('temporarily unavailable');
    settledResponses.push(response);

    const currentArticle = drawer.locator('article').filter({
        has: page.getByRole('heading', { name: question, exact: true }),
    });
    await expect(currentArticle).toHaveCount(1);
    await expect(currentArticle.getByText(TEMPORARY_UNAVAILABLE, { exact: true })).toBeVisible();
    await expect(currentArticle.getByText('Insufficient documentation', { exact: true })).toBeVisible();
    await expect(drawer.getByRole('navigation', { name: 'How do I? deterministic help' })).toBeVisible();
    expect(payloads.at(-1)?.question).toBe(question);
    mountedQuestions.push(question);
    for (const priorQuestion of mountedQuestions) {
        const priorArticle = drawer.locator('article').filter({
            has: page.getByRole('heading', { name: priorQuestion, exact: true }),
        });
        await expect(priorArticle).toHaveCount(1);
        await expect(priorArticle.getByText(TEMPORARY_UNAVAILABLE, { exact: true })).toBeVisible();
        await expect(priorArticle.getByText('Insufficient documentation', { exact: true })).toBeVisible();
    }
}

for (const viewport of VIEWPORTS) {
    test(`AI-03 recovers to deterministic documentation when assistant service is unavailable (${viewport.name})`, async ({ page }, testInfo) => {
        journeyId(testInfo, 'AI-03');
        await seedDeterministicJourney(page, `ai-03-unavailable-recovery-${viewport.name}`);
        await page.setViewportSize({ width: viewport.width, height: viewport.height });

        const allowedOrigin = new URL(process.env.STOX_E2E_BASE_URL || 'http://127.0.0.1:4177').origin;
        const blockedOrigins = [];
        const observedRequests = [];
        const payloads = [];
        const mountedQuestions = [];
        const settledResponses = [];

        await page.route('**/*', (route) => {
            const origin = new URL(route.request().url()).origin;
            if (origin !== allowedOrigin) {
                blockedOrigins.push(origin);
                return route.abort();
            }
            return route.fallback();
        });
        await installInvestorWorkflowApiMocks(page);
        page.on('request', (request) => {
            const url = new URL(request.url());
            observedRequests.push({ method: request.method(), origin: url.origin, path: url.pathname, request });
        });
        await page.route(`**${STREAM_PATH}`, async (route) => {
            const request = route.request();
            const url = new URL(request.url());
            expect(request.method()).toBe('POST');
            expect(url.origin).toBe(allowedOrigin);
            expect(url.pathname).toBe(STREAM_PATH);
            const payload = request.postDataJSON();
            const expectedQuestion = EXPECTED_QUESTIONS[payloads.length];
            expect(expectedQuestion).toBeDefined();
            expect(payload.question).toBe(expectedQuestion);
            expect(Array.isArray(payload.conversation)).toBe(true);
            payloads.push(payload);
            await route.fulfill({
                status: 503,
                contentType: 'application/json',
                body: JSON.stringify({ message: 'Runtime temporarily unavailable' }),
            });
        });

        await page.goto('/');
        const trigger = page.getByRole('button', { name: 'Open StoX assistant' });
        await trigger.click();
        let drawer = page.getByRole('dialog', { name: 'StoX assistant' });
        await expect(drawer).toBeVisible();
        await askAndSettleUnavailable(page, drawer, QUESTION, payloads, mountedQuestions, settledResponses);

        if (viewport.name === 'mobile') {
            const dimensions = await drawer.evaluate((element) => ({
                scrollHeight: element.scrollHeight,
                clientHeight: element.clientHeight,
                scrollTop: element.scrollTop,
            }));
            expect(dimensions.scrollHeight).toBeGreaterThan(dimensions.clientHeight);
            await drawer.hover();
            if (dimensions.scrollTop >= dimensions.scrollHeight - dimensions.clientHeight - 1) {
                await page.mouse.wheel(0, -640);
                await expect.poll(() => drawer.evaluate((element) => element.scrollTop)).toBeLessThan(dimensions.scrollTop);
                const beforeDownwardWheel = await drawer.evaluate((element) => element.scrollTop);
                await page.mouse.wheel(0, 640);
                await expect.poll(() => drawer.evaluate((element) => element.scrollTop)).toBeGreaterThan(beforeDownwardWheel);
            } else {
                await page.mouse.wheel(0, 640);
                await expect.poll(() => drawer.evaluate((element) => element.scrollTop)).toBeGreaterThan(dimensions.scrollTop);
            }
            await expect(drawer.getByRole('link', { name: 'Browse documentation' })).toBeInViewport();
        }

        await drawer.getByRole('link', { name: 'Browse documentation' }).click();
        await expect(page).toHaveURL(/\/docs\/overview\.html$/);
        await expect(page.getByRole('heading', { name: 'App documentation', exact: true })).toBeVisible();
        await expect(page.getByText('How contextual help works and how to browse topics for every screen.', { exact: true })).toBeVisible();

        // Re-enter the ordinary application route and verify close/focus recovery from the same unavailable state.
        await page.goto('/');
        mountedQuestions.length = 0;
        await trigger.click();
        drawer = page.getByRole('dialog', { name: 'StoX assistant' });
        await askAndSettleUnavailable(page, drawer, CLOSE_QUESTION, payloads, mountedQuestions, settledResponses);
        await drawer.getByRole('button', { name: 'Close assistant', exact: true }).click();
        await expect(drawer).not.toBeVisible();
        await expect(trigger).toBeFocused();

        await trigger.click();
        drawer = page.getByRole('dialog', { name: 'StoX assistant' });
        await askAndSettleUnavailable(page, drawer, ESCAPE_QUESTION, payloads, mountedQuestions, settledResponses);
        await page.keyboard.press('Escape');
        await expect(drawer).not.toBeVisible();
        await expect(trigger).toBeFocused();

        expect(settledResponses).toHaveLength(3);
        expect(payloads).toHaveLength(3);
        for (const response of settledResponses) {
            expect(response.status()).toBe(503);
            expect(await response.finished()).toBeNull();
            expect((await response.body()).toString()).toContain('temporarily unavailable');
        }
        expect(blockedOrigins).toEqual([]);
        const httpRequests = observedRequests.filter(({ request }) => /^https?:/i.test(request.url()));
        expect(httpRequests.length).toBeGreaterThan(0);
        expect(httpRequests.every(({ origin }) => origin === allowedOrigin)).toBe(true);
        const mutations = observedRequests.filter(({ method }) => /^(POST|PUT|PATCH|DELETE)$/.test(method));
        expect(mutations.map(({ method, origin, path }) => ({ method, origin, path }))).toEqual([
            { method: 'POST', origin: allowedOrigin, path: STREAM_PATH },
            { method: 'POST', origin: allowedOrigin, path: STREAM_PATH },
            { method: 'POST', origin: allowedOrigin, path: STREAM_PATH },
        ]);
        expect(payloads.map(({ question }) => question)).toEqual(EXPECTED_QUESTIONS);
        expect(payloads.every(({ conversation }) => Array.isArray(conversation))).toBe(true);
    });
}
