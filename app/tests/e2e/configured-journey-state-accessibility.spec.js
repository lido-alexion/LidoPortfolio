import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { CAPITAL_RESOLUTION, OPEN_BUY_RECOMMENDATION, apiEnvelope } from '../js/tos/fixtures/tosApi.js';
import { journeyId, seedDeterministicJourney } from './journeyTestUtils.js';
import { installInvestorWorkflowApiMocks } from './investorWorkflowApiMocks.js';
import { installTosApiMocks } from './tosApiMocks.js';

const VIEWPORTS = [
    { name: 'mobile', width: 390, height: 844 },
    { name: 'desktop', width: 1440, height: 900 },
];

const ORIGIN = new URL(process.env.STOX_E2E_BASE_URL || 'http://127.0.0.1:4177').origin;
const REVIEW_RECOMMENDATION = {
    ...OPEN_BUY_RECOMMENDATION,
    strategy_id: 7,
    strategy_version_id: 12,
    strategy_version: 3,
    evidence: {
        ...OPEN_BUY_RECOMMENDATION.evidence,
        screener_id: 31,
        screener_version: 4,
    },
};
const EXECUTION_STATE_RESPONSE = apiEnvelope({
    execution_state: 'normal',
    broker_connected: false,
    active_orders: 0,
});
const CAPITAL_RESOLUTION_RESPONSE = apiEnvelope(CAPITAL_RESOLUTION);
const NOTIFICATION_CENTER_RESPONSE = {
    data: [{
        id: 17,
        attention_state: 'read',
        condition_state: 'na',
        type: 'recommendation.changed',
        severity: 'info',
        title: 'Recommendation changed',
        message: 'A product update is ready.',
        latest_activity_at: '2026-10-04T10:00:00Z',
        deliveries: [],
    }],
    meta: { unread_count: 0, active_critical_count: 0 },
};
const COMMON_READS = new Set([
    '/api/auth/csrf-token',
    '/api/auth/me',
    '/api/portfolios',
    '/api/guided-tour',
    '/api/dashboard',
    '/api/patterns/scan',
    '/api/v1/protections',
    '/api/indexes',
    '/api/v1/execution/state',
    '/api/notification-center',
    '/sanctum/csrf-cookie',
]);

async function installNotificationCenterShellMock(page) {
    await page.route(/\/api\/notification-center\?per_page=5(?:&|$)/, async (route) => {
        if (route.request().method() !== 'GET') return route.fallback();
        return route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify(NOTIFICATION_CENTER_RESPONSE),
        });
    });
}

async function installReadOnlyBoundary(page, pageReads) {
    const requests = [];
    const blocked = [];
    page.on('request', (request) => requests.push({ request, response: null }));
    page.on('response', (response) => {
        const entry = requests.find((item) => item.request === response.request());
        if (entry) entry.response = response;
    });

    // This guard runs before the existing route mocks and falls through only for
    // explicitly allowed same-origin GET fixtures. Unknown API requests are blocked.
    await page.route('**/*', async (route) => {
        const request = route.request();
        const url = new URL(request.url());
        if (!['http:', 'https:'].includes(url.protocol)) {
            return route.fallback();
        }
        if (url.origin !== ORIGIN) {
            blocked.push(`${request.method()} ${url.origin}${url.pathname}`);
            return route.abort();
        }
        if (request.method() !== 'GET') {
            blocked.push(`${request.method()} ${url.origin}${url.pathname}`);
            return route.abort();
        }
        if (url.pathname.startsWith('/api/') || url.pathname.startsWith('/sanctum/')) {
            const allowed = COMMON_READS.has(url.pathname) || pageReads.has(url.pathname);
            if (!allowed) {
                blocked.push(`${request.method()} ${url.origin}${url.pathname}`);
                return route.abort();
            }
        }
        return route.fallback();
    });

    return async () => {
        expect(blocked).toEqual([]);
        const httpEntries = requests.filter(({ request }) => ['http:', 'https:'].includes(new URL(request.url()).protocol));
        for (const { request } of httpEntries) {
            const url = new URL(request.url());
            expect(url.origin, `${request.method()} ${url.pathname} uses the fixture origin`).toBe(ORIGIN);
            expect(request.method(), `${url.origin}${url.pathname} is read-only`).toBe('GET');
        }
        const apiEntries = httpEntries.filter(({ request }) => {
            const url = new URL(request.url());
            return url.pathname.startsWith('/api/') || url.pathname.startsWith('/sanctum/');
        });
        const terminalResponses = [];
        for (const { request, response } of apiEntries) {
            expect(request.method()).toBe('GET');
            expect(response, `${request.method()} ${new URL(request.url()).pathname} has a terminal response`).not.toBeNull();
            expect(await response.finished()).toBeNull();
            expect(response.status(), `${request.method()} ${new URL(request.url()).pathname}`).toBeLessThan(300);
            const body = await response.body();
            terminalResponses.push({
                path: new URL(request.url()).pathname,
                status: response.status(),
                body: body.length ? JSON.parse(body.toString('utf8')) : null,
            });
        }
        return terminalResponses;
    };
}

async function tabTo(page, locator, description) {
    for (let index = 0; index < 160; index += 1) {
        if (await locator.evaluate((element) => element === document.activeElement)) return;
        await page.keyboard.press('Tab');
    }
    throw new Error(`Keyboard Tab navigation did not reach ${description}`);
}

async function expectNoScopedViolations(page, selector) {
    const results = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa'])
        .include(selector)
        .analyze();
    expect(results.violations).toEqual([]);
}

test.describe('V9-UX-001 configured journey state accessibility', () => {
    for (const viewport of VIEWPORTS) {
        test(`STR-06 opened recommendation-threshold panel has no WCAG 2A/2AA violations (${viewport.name})`, async ({ page }, testInfo) => {
            journeyId(testInfo, 'STR-06');
            await seedDeterministicJourney(page, `configured-state-a11y-str06-${viewport.name}`);
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            await installInvestorWorkflowApiMocks(page);
            await installNotificationCenterShellMock(page);
            const finishBoundary = await installReadOnlyBoundary(page, new Set([
                '/api/screeners',
                '/api/screeners/meta',
                '/api/v1/strategy',
                '/api/v1/strategy-registry',
            ]));

            await page.goto('/strategy?strategy_id=7');
            await expect(page.getByRole('heading', { name: 'Strategy', exact: true })).toBeVisible();
            await expect(page.getByRole('combobox', { name: 'Select strategy to edit' })).toHaveValue('7');

            const thresholdsTab = page.getByRole('button', { name: 'Recommendation Thresholds', exact: true });
            await tabTo(page, thresholdsTab, 'the Recommendation Thresholds tab');
            await page.keyboard.press('Enter');

            const thresholdPanel = page.locator('.card.card-body').filter({
                has: page.getByText(/These cutoffs are compared against the strategy's overall score/),
            });
            await expect(thresholdPanel).toHaveCount(1);
            await expect(thresholdPanel).toBeVisible();
            const values = [35, 70, 80, 40, 25, 55];
            const inputs = thresholdPanel.locator('.lido-number-input-field');
            await expect(inputs).toHaveCount(values.length);
            await tabTo(page, inputs.first(), 'the first threshold input');
            for (const [index, value] of values.entries()) {
                await inputs.nth(index).fill(String(value));
                await expect(inputs.nth(index)).toHaveValue(String(value));
            }
            const labels = [
                'Minimum Overall Score', 'Open Position', 'Increase Position',
                'Reduce Position', 'Exit Position', 'Watch',
            ];
            for (const [index, label] of labels.entries()) {
                await expect(thresholdPanel.getByText(label, { exact: true })).toBeVisible();
                const field = thresholdPanel.getByLabel(label, { exact: true });
                await expect(field).toHaveCount(1);
                await expect(field).toHaveValue(String(values[index]));
            }

            const axePanel = page.locator('.card.card-body:has(> p.text-muted.small.mb-3)');
            await expect(axePanel).toHaveCount(1);
            await expect(axePanel).toBeVisible();
            await expect(axePanel.getByText(/These cutoffs are compared against the strategy's overall score/)).toHaveCount(1);
            expect(await thresholdPanel.evaluate((panel) => panel.matches('.card.card-body:has(> p.text-muted.small.mb-3)'))).toBe(true);
            await expectNoScopedViolations(page, '.card.card-body:has(> p.text-muted.small.mb-3)');
            const responses = await finishBoundary();
            const strategyReads = responses.filter((item) => item.path === '/api/v1/strategy');
            expect(strategyReads.length).toBeGreaterThan(0);
            for (const item of strategyReads) {
                expect(item.status).toBe(200);
                expect(item.body).toEqual({
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
                        eligibility_sources: [],
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
                });
            }
            const executionStateReads = responses.filter((item) => item.path === '/api/v1/execution/state');
            expect(executionStateReads.length).toBeGreaterThan(0);
            for (const item of executionStateReads) {
                expect(item.status).toBe(200);
                expect(item.body).toEqual(EXECUTION_STATE_RESPONSE);
            }
            const notificationReads = responses.filter((item) => item.path === '/api/notification-center');
            expect(notificationReads.length).toBeGreaterThan(0);
            for (const item of notificationReads) {
                expect(item.status).toBe(200);
                expect(item.body).toEqual(NOTIFICATION_CENTER_RESPONSE);
            }
        });

        test(`REC-02 opened recommendation review dialog has no WCAG 2A/2AA violations (${viewport.name})`, async ({ page }, testInfo) => {
            journeyId(testInfo, 'REC-02');
            await seedDeterministicJourney(page, `configured-state-a11y-rec02-${viewport.name}`);
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            await installTosApiMocks(page, { recommendations: [REVIEW_RECOMMENDATION] });
            await installNotificationCenterShellMock(page);
            const capitalResolutionPath = `/api/v1/recommendations/${REVIEW_RECOMMENDATION.id}/capital-resolution`;
            const finishBoundary = await installReadOnlyBoundary(page, new Set([
                '/api/v1/recommendations',
                `/api/v1/recommendations/${REVIEW_RECOMMENDATION.id}`,
                capitalResolutionPath,
            ]));

            await page.goto('/recommendations');
            await expect(page.getByRole('heading', { name: 'Recommendations' }).first()).toBeVisible();
            const recommendationRow = page.getByRole('row').filter({
                has: page.getByText('INFY', { exact: true }),
            });
            await expect(recommendationRow).toHaveCount(1);
            await expect(recommendationRow).toBeVisible();
            const reviewButton = recommendationRow.getByRole('button', { name: 'Review', exact: true });
            await expect(reviewButton).toHaveCount(1);
            await tabTo(page, reviewButton, 'the recommendation Review action');
            await page.keyboard.press('Enter');

            const dialog = page.getByRole('dialog', { name: 'Open INFY', exact: true });
            await expect(dialog).toHaveCount(1);
            await expect(dialog).toBeVisible();
            await expect(dialog.getByRole('heading', { name: 'Open INFY', exact: true })).toBeVisible();
            const marketOpinion = dialog.getByRole('heading', { name: 'Market opinion', exact: true })
                .locator('xpath=following-sibling::p[1]');
            await expect(marketOpinion).toHaveText('bullish · strong · Confidence 82% · Score 78 · Minervini Strategy · Version 3');
            await expect(dialog.getByTestId('recommendation-provenance')).toHaveText('Source screener #31 · Version 4 · Strategy #7 · Strategy version #12');
            await expectNoScopedViolations(page, '.lido-tos-review-modal');

            const closeButton = dialog.getByRole('button', { name: 'Close', exact: true }).last();
            await tabTo(page, closeButton, 'the dialog Close action');
            await page.keyboard.press('Enter');
            await expect(dialog).toBeHidden();
            const responses = await finishBoundary();
            const listReads = responses.filter((item) => item.path === '/api/v1/recommendations');
            const detailReads = responses.filter((item) => item.path === `/api/v1/recommendations/${REVIEW_RECOMMENDATION.id}`);
            expect(listReads.length).toBeGreaterThan(0);
            expect(detailReads.length).toBeGreaterThan(0);
            for (const item of listReads) {
                expect(item.status).toBe(200);
                expect(item.body).toEqual({ success: true, data: [REVIEW_RECOMMENDATION], meta: {} });
            }
            for (const item of detailReads) {
                expect(item.status).toBe(200);
                expect(item.body).toEqual({ success: true, data: REVIEW_RECOMMENDATION, meta: {} });
            }
            const capitalResolutionReads = responses.filter((item) => item.path === capitalResolutionPath);
            expect(capitalResolutionReads.length).toBeGreaterThan(0);
            for (const item of capitalResolutionReads) {
                expect(item.status).toBe(200);
                expect(item.body).toEqual(CAPITAL_RESOLUTION_RESPONSE);
            }
            const executionStateReads = responses.filter((item) => item.path === '/api/v1/execution/state');
            expect(executionStateReads.length).toBeGreaterThan(0);
            for (const item of executionStateReads) {
                expect(item.status).toBe(200);
                expect(item.body).toEqual(EXECUTION_STATE_RESPONSE);
            }
            const notificationReads = responses.filter((item) => item.path === '/api/notification-center');
            expect(notificationReads.length).toBeGreaterThan(0);
            for (const item of notificationReads) {
                expect(item.status).toBe(200);
                expect(item.body).toEqual(NOTIFICATION_CENTER_RESPONSE);
            }
        });
    }
});
