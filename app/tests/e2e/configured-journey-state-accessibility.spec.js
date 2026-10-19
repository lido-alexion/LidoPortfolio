import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { OPEN_BUY_RECOMMENDATION } from '../js/tos/fixtures/tosApi.js';
import { journeyId, seedDeterministicJourney } from './journeyTestUtils.js';
import { installInvestorWorkflowApiMocks } from './investorWorkflowApiMocks.js';
import { installTosApiMocks } from './tosApiMocks.js';

const VIEWPORTS = [
    { name: 'mobile', width: 390, height: 844 },
    { name: 'desktop', width: 1440, height: 900 },
];

const ORIGIN = new URL(process.env.STOX_E2E_BASE_URL || 'http://127.0.0.1:4177').origin;
const COMMON_READS = new Set([
    '/api/auth/csrf-token',
    '/api/auth/me',
    '/api/portfolios',
    '/api/guided-tour',
    '/api/dashboard',
    '/api/patterns/scan',
    '/api/v1/protections',
    '/api/indexes',
    '/sanctum/csrf-cookie',
]);

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
        if (url.origin !== ORIGIN) {
            blocked.push(`${request.method()} ${url.origin}${url.pathname}`);
            return route.abort();
        }
        if (url.pathname.startsWith('/api/') || url.pathname.startsWith('/sanctum/')) {
            const allowed = request.method() === 'GET'
                && (COMMON_READS.has(url.pathname) || pageReads.has(url.pathname));
            if (!allowed) {
                blocked.push(`${request.method()} ${url.pathname}`);
                return route.abort();
            }
        }
        return route.fallback();
    });

    return async () => {
        expect(blocked).toEqual([]);
        const apiEntries = requests.filter(({ request }) => {
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
            for (const label of [
                'Minimum Overall Score', 'Open Position', 'Increase Position',
                'Reduce Position', 'Exit Position', 'Watch',
            ]) {
                await expect(thresholdPanel.getByText(label, { exact: true })).toBeVisible();
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
        });

        test(`REC-02 opened recommendation review dialog has no WCAG 2A/2AA violations (${viewport.name})`, async ({ page }, testInfo) => {
            journeyId(testInfo, 'REC-02');
            await seedDeterministicJourney(page, `configured-state-a11y-rec02-${viewport.name}`);
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            await installTosApiMocks(page, { recommendations: [OPEN_BUY_RECOMMENDATION] });
            const finishBoundary = await installReadOnlyBoundary(page, new Set([
                '/api/v1/recommendations',
                `/api/v1/recommendations/${OPEN_BUY_RECOMMENDATION.id}`,
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

            const dialog = page.locator('.lido-tos-review-modal');
            await expect(dialog).toBeVisible();
            await expect(dialog.getByRole('heading', { name: /Open INFY/ })).toBeVisible();
            await expect(dialog.getByText('Minervini Strategy', { exact: true })).toBeVisible();
            await expectNoScopedViolations(page, '.lido-tos-review-modal');

            const closeButton = dialog.getByRole('button', { name: 'Close', exact: true }).last();
            await tabTo(page, closeButton, 'the dialog Close action');
            await page.keyboard.press('Enter');
            await expect(dialog).toBeHidden();
            const responses = await finishBoundary();
            const listReads = responses.filter((item) => item.path === '/api/v1/recommendations');
            const detailReads = responses.filter((item) => item.path === `/api/v1/recommendations/${OPEN_BUY_RECOMMENDATION.id}`);
            expect(listReads.length).toBeGreaterThan(0);
            expect(detailReads.length).toBeGreaterThan(0);
            for (const item of listReads) {
                expect(item.status).toBe(200);
                expect(item.body).toEqual({ success: true, data: [OPEN_BUY_RECOMMENDATION], meta: {} });
            }
            for (const item of detailReads) {
                expect(item.status).toBe(200);
                expect(item.body).toEqual({ success: true, data: OPEN_BUY_RECOMMENDATION, meta: {} });
            }
        });
    }
});
