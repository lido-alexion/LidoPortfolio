import { test, expect } from '@playwright/test';
import { journeyId, seedDeterministicJourney } from './journeyTestUtils.js';
import { installTosApiMocks } from './tosApiMocks.js';
import { installInvestorWorkflowApiMocks } from './investorWorkflowApiMocks.js';
import { HOLD_INSIGHT, OPEN_BUY_RECOMMENDATION, WATCH_INSIGHT } from '../js/tos/fixtures/tosApi.js';

const VIEWPORTS = [
    { name: 'mobile', width: 390, height: 844 },
    { name: 'desktop', width: 1440, height: 900 },
];

test.describe('V9-UX-001 recommendation journeys', () => {
    for (const viewport of VIEWPORTS) {
        test('REC-01 runs the decision pipeline and inspects strategy-specific output (' + viewport.name + ')', async ({ page }, testInfo) => {
            journeyId(testInfo, 'REC-01');
            await seedDeterministicJourney(page, 'recommendation-rec01-' + viewport.name);
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            const generated = {
                ...OPEN_BUY_RECOMMENDATION,
                id: 701,
                strategy_name: 'Momentum Core',
                status: 'pending_review',
                lifecycle_status: 'pending_review',
            };
            await installTosApiMocks(page, {
                recommendations: [],
                pipelineRecommendations: [generated],
                pipelineStages: { discovery: { candidates: 4 }, evaluation: { results: 3 }, recommendation: { count: 1 } },
            });
            const observedRequests = [];
            page.on('request', (request) => observedRequests.push(new URL(request.url()).pathname));
            await page.goto('/recommendations');
            const pipelineRequest = page.waitForRequest((request) => (
                request.method() === 'POST' && new URL(request.url()).pathname.endsWith('/api/v1/pipeline/run')
            ));
            await page.getByRole('button', { name: 'Run decision pipeline' }).click();
            const request = await pipelineRequest;
            expect(new URL(request.url()).searchParams.get('notify')).toBe('1');
            expect(new URL(request.url()).searchParams.get('review')).toBe('1');
            await expect(page.getByText('strategy-specific recommendations 1')).toBeVisible();
            const recommendation = page.getByRole('row').filter({ hasText: 'Momentum Core' });
            await expect(recommendation).toContainText('INFY');
            await expect(recommendation).toContainText('pending_review');
            expect(observedRequests.some((path) => /\/orders(?:\/|$)/.test(path))).toBe(false);
        });

        test('REC-02 records a review decision and note without submitting an order (' + viewport.name + ')', async ({ page }, testInfo) => {
            journeyId(testInfo, 'REC-02');
            await seedDeterministicJourney(page, 'recommendation-rec02-' + viewport.name);
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            await installTosApiMocks(page, {
                recommendations: [{
                    ...OPEN_BUY_RECOMMENDATION,
                    id: 702,
                    strategy_name: 'Momentum Core',
                }],
            });
            const observedRequests = [];
            page.on('request', (request) => observedRequests.push(new URL(request.url()).pathname));
            await page.goto('/recommendations');
            const recommendation = page.getByRole('row').filter({ hasText: 'Momentum Core' });
            await recommendation.getByRole('button', { name: 'Review' }).click();
            const dialog = page.getByRole('dialog');
            await expect(dialog).toBeVisible();
            await expect(dialog).toContainText('Momentum Core');
            await dialog.getByLabel('Review notes (optional)').fill('Approved after checking strategy evidence.');
            const reviewRequest = page.waitForRequest((request) => (
                request.method() === 'POST' && new URL(request.url()).pathname.endsWith('/api/v1/recommendations/702/review')
            ));
            await dialog.getByRole('button', { name: 'Approve' }).click();
            const request = await reviewRequest;
            expect(request.postDataJSON()).toMatchObject({
                decision: 'approved',
                notes: 'Approved after checking strategy evidence.',
            });
            await expect(dialog).toHaveCount(0);
            await expect(page.getByText('No trade recommendations are actionable in the current view.')).toBeVisible();
            expect(observedRequests.some((path) => /\/orders(?:\/|$)/.test(path))).toBe(false);
        });
        test('REC-03 explains a recommendation with strategy score and evidence (' + viewport.name + ')', async ({ page }, testInfo) => {
            journeyId(testInfo, 'REC-03');
            await seedDeterministicJourney(page, 'recommendation-rec03-' + viewport.name);
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            await installTosApiMocks(page, { recommendations: [{ ...OPEN_BUY_RECOMMENDATION, id: 703, strategy_name: 'Momentum Core' }] });
            await page.goto('/recommendations');
            const row = page.getByRole('row').filter({ hasText: 'Momentum Core' });
            await row.getByRole('button', { name: 'Review' }).click();
            const dialog = page.getByRole('dialog');
            await expect(dialog).toContainText('Momentum Core');
            await expect(dialog.getByRole('heading', { name: 'Market opinion' })).toBeVisible();
            await expect(dialog.getByRole('heading', { name: 'Factor breakdown' })).toBeVisible();
            await expect(dialog).toContainText('trend_up');
            await expect(dialog.getByRole('heading', { name: 'Execution plan' })).toBeVisible();
            await dialog.getByRole('button', { name: 'Close' }).click();
            await expect(dialog).toHaveCount(0);
        });

        test('REC-04 presents OPEN, INCREASE, REDUCE, and EXIT as separate actions (' + viewport.name + ')', async ({ page }, testInfo) => {
            journeyId(testInfo, 'REC-04');
            await seedDeterministicJourney(page, 'recommendation-rec04-' + viewport.name);
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            const actionCases = [
                ['OPEN_POSITION', 'Open', 'Momentum Core'],
                ['INCREASE_POSITION', 'Increase', 'Quality Core'],
                ['REDUCE_POSITION', 'Reduce', 'Value Core'],
                ['EXIT_POSITION', 'Exit', 'Income Core'],
            ];
            await installTosApiMocks(page, { recommendations: actionCases.map(([action, label, strategy], index) => ({
                ...OPEN_BUY_RECOMMENDATION, id: 710 + index, recommendation_type: action, portfolio_action: action,
                ui_label: label, strategy_name: strategy,
            })) });
            await page.goto('/recommendations');
            for (const [, label, strategy] of actionCases) {
                const row = page.getByRole('row').filter({ hasText: strategy });
                await expect(row).toContainText('INFY');
                await expect(row).toContainText(label);
                await expect(row).toContainText('pending_review');
            }
        });

        test('REC-05 keeps WATCH and HOLD informational without approval controls (' + viewport.name + ')', async ({ page }, testInfo) => {
            journeyId(testInfo, 'REC-05');
            await seedDeterministicJourney(page, 'recommendation-rec05-' + viewport.name);
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            await installTosApiMocks(page, { recommendations: [
                { ...HOLD_INSIGHT, id: 720 },
                { ...WATCH_INSIGHT, id: 721 },
            ] });
            await page.goto('/recommendations');
            await page.getByLabel('Show HOLD insights').check();
            const hold = page.getByRole('row').filter({ hasText: 'Core Holdings Strategy' });
            const watch = page.getByRole('row').filter({ hasText: 'Swing Strategy' });
            await expect(hold).toContainText('Hold');
            await expect(watch).toContainText('Watch');
            await expect(hold.getByRole('button', { name: 'Review' })).toHaveCount(0);
            await expect(watch.getByRole('button', { name: 'Review' })).toHaveCount(0);
        });
        test('REC-06 previews one stock for the selected strategy without persisting a recommendation (' + viewport.name + ')', async ({ page }, testInfo) => {
            journeyId(testInfo, 'REC-06');
            await seedDeterministicJourney(page, 'recommendation-rec06-' + viewport.name);
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            await installInvestorWorkflowApiMocks(page);
            const observedRequests = [];
            page.on('request', (request) => observedRequests.push(new URL(request.url()).pathname));
            await page.goto('/watchlist/TCS');
            const previewRequest = page.waitForRequest((request) => (
                request.method() === 'GET' && new URL(request.url()).pathname.endsWith('/api/v1/analytics/stocks/42/recommendation-preview')
            ));
            await page.getByRole('button', { name: 'Recommendation Preview' }).click();
            const request = await previewRequest;
            expect(new URL(request.url()).searchParams.get('strategy_id')).toBe('7');
            await expect(page.getByText('Recommendation is not executable for this stock under the selected strategy.')).toBeVisible();
            expect(observedRequests.some((path) => path.endsWith('/api/v1/recommendations/run'))).toBe(false);
            expect(observedRequests.some((path) => path.includes('/orders'))).toBe(false);
        });
    }
});
