import { test, expect } from '@playwright/test';
import { journeyId, seedDeterministicJourney } from './journeyTestUtils.js';
import { installTosApiMocks } from './tosApiMocks.js';
import { OPEN_BUY_RECOMMENDATION } from '../js/tos/fixtures/tosApi.js';

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
    }
});
