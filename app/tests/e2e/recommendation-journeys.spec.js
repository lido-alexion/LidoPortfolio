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
            await dialog.getByRole('button', { name: 'Close' }).last().click();
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
        test('REC-07 approves a capital-ready recommendation into pending execution without placing an order (' + viewport.name + ')', async ({ page }, testInfo) => {
            journeyId(testInfo, 'REC-07');
            await seedDeterministicJourney(page, 'recommendation-rec07-' + viewport.name);
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            await installTosApiMocks(page, {
                recommendations: [{
                    ...OPEN_BUY_RECOMMENDATION,
                    id: 707,
                    strategy_name: 'Momentum Core',
                    capital_allocation_status: 'funded',
                    can_review: true,
                    suggested_quantity: 10,
                    suggested_investment_amount: 50000,
                }],
                retainApprovedRecommendations: true,
            });
            const observedRequests = [];
            page.on('request', (request) => observedRequests.push({
                method: request.method(),
                path: new URL(request.url()).pathname,
            }));
            await page.goto('/recommendations');
            let row = page.getByRole('row').filter({ hasText: 'Momentum Core' });
            await row.getByRole('button', { name: 'Review' }).click();
            let dialog = page.getByRole('dialog');
            await expect(dialog).toContainText('Momentum Core');
            await expect(dialog).toContainText('Funded');
            await expect(dialog).toContainText('Resolved at actual amount');
            await expect(dialog).toContainText('10 shares');
            await expect(dialog).toContainText('₹50000');
            await dialog.getByLabel('Review notes (optional)').fill('Capital readiness verified.');
            const approvalRequest = page.waitForRequest((request) => (
                request.method() === 'POST' && new URL(request.url()).pathname.endsWith('/api/v1/recommendations/707/review')
            ));
            await dialog.getByRole('button', { name: 'Approve' }).click();
            expect((await approvalRequest).postDataJSON()).toMatchObject({
                decision: 'approved',
                notes: 'Capital readiness verified.',
            });
            await expect(dialog).toHaveCount(0);
            row = page.getByRole('row').filter({ hasText: 'Momentum Core' });
            await expect(row).toContainText('pending_execution');
            await row.getByRole('button', { name: 'Review' }).click();
            dialog = page.getByRole('dialog');
            await expect(dialog.getByRole('link', { name: 'Go to Pending Execution' })).toHaveAttribute('href', '/transactions/pending');
            expect(observedRequests.some(({ method, path }) => method === 'POST' && /\/orders(?:\/|$)/.test(path))).toBe(false);
        });

        test('REC-08 rejects an actionable recommendation with a note and keeps it out of the review queue (' + viewport.name + ')', async ({ page }, testInfo) => {
            journeyId(testInfo, 'REC-08');
            await seedDeterministicJourney(page, 'recommendation-rec08-' + viewport.name);
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            await installTosApiMocks(page, { recommendations: [{ ...OPEN_BUY_RECOMMENDATION, id: 708, strategy_name: 'Momentum Core' }] });
            await page.goto('/recommendations');
            const row = page.getByRole('row').filter({ hasText: 'Momentum Core' });
            await row.getByRole('button', { name: 'Review' }).click();
            const dialog = page.getByRole('dialog');
            await dialog.getByLabel('Review notes (optional)').fill('Reject until the price trend improves.');
            const reviewRequest = page.waitForRequest((request) => (
                request.method() === 'POST' && new URL(request.url()).pathname.endsWith('/api/v1/recommendations/708/review')
            ));
            await dialog.getByRole('button', { name: 'Reject' }).click();
            expect((await reviewRequest).postDataJSON()).toMatchObject({
                decision: 'rejected',
                notes: 'Reject until the price trend improves.',
            });
            await expect(dialog).toHaveCount(0);
            await expect(page.getByText('No trade recommendations are actionable in the current view.')).toBeVisible();
        });

        test('REC-10 shows the actual execution amount when capital is partially funded (' + viewport.name + ')', async ({ page }, testInfo) => {
            journeyId(testInfo, 'REC-10');
            await seedDeterministicJourney(page, 'recommendation-rec10-' + viewport.name);
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            await installTosApiMocks(page, {
                recommendations: [{ ...OPEN_BUY_RECOMMENDATION, id: 710, strategy_name: 'Momentum Core', can_review: false }],
                capitalResolution: {
                    capital_resolution_state: 'closed_at_actual_with_shortfall',
                    requested_investment_amount: 50000,
                    own_capital_used: 20000,
                    recalled_capital_requested: 30000,
                    recalled_capital_received: 10000,
                    bridge_capital_used: 0,
                    total_immediately_available: 30000,
                    actual_execution_amount: 30000,
                    unresolved_amount: 20000,
                },
            });
            const observedRequests = [];
            page.on('request', (request) => observedRequests.push(new URL(request.url()).pathname));
            await page.goto('/recommendations');
            const row = page.getByRole('row').filter({ hasText: 'Momentum Core' });
            await row.getByRole('button', { name: 'Review' }).click();
            const dialog = page.getByRole('dialog');
            await expect(dialog.getByRole('heading', { name: 'Capital resolution' })).toBeVisible();
            await expect(dialog).toContainText('Open INFY');
            await expect(dialog).toContainText('Closed at actual (shortfall remains)');
            await expect(dialog.getByRole('button', { name: 'Approve' })).toHaveCount(0);
            const actualAmount = dialog.getByRole('row').filter({ hasText: 'Actual execution amount' });
            await expect(actualAmount).toContainText('₹30,000');
            await expect(dialog.getByRole('row').filter({ hasText: 'Unresolved' })).toContainText('₹20,000');
            expect(observedRequests.some((path) => /\/orders(?:\/|$)/.test(path))).toBe(false);
        });

        test('REC-11 explains an unfunded recommendation without presenting a positive execution amount (' + viewport.name + ')', async ({ page }, testInfo) => {
            journeyId(testInfo, 'REC-11');
            await seedDeterministicJourney(page, 'recommendation-rec11-' + viewport.name);
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            await installTosApiMocks(page, {
                recommendations: [{ ...OPEN_BUY_RECOMMENDATION, id: 711, strategy_name: 'Momentum Core', can_review: false }],
                capitalResolution: {
                    capital_resolution_state: 'unfunded',
                    requested_investment_amount: 50000,
                    own_capital_used: 0,
                    recalled_capital_requested: 0,
                    recalled_capital_received: 0,
                    bridge_capital_used: 0,
                    total_immediately_available: 0,
                    actual_execution_amount: 0,
                    unresolved_amount: 50000,
                },
            });
            const observedRequests = [];
            page.on('request', (request) => observedRequests.push(new URL(request.url()).pathname));
            await page.goto('/recommendations');
            const row = page.getByRole('row').filter({ hasText: 'Momentum Core' });
            await row.getByRole('button', { name: 'Review' }).click();
            const dialog = page.getByRole('dialog');
            await expect(dialog.getByRole('heading', { name: 'Capital resolution' })).toBeVisible();
            await expect(dialog).toContainText('Open INFY');
            await expect(dialog).toContainText('Unfunded');
            await expect(dialog.getByRole('button', { name: 'Approve' })).toHaveCount(0);
            await expect(dialog.getByRole('row').filter({ hasText: 'Actual execution amount' })).toContainText('₹0');
            await expect(dialog.getByRole('row').filter({ hasText: 'Unresolved' })).toContainText('₹50,000');
            expect(observedRequests.some((path) => /\/orders(?:\/|$)/.test(path))).toBe(false);
        });

        test('REC-12 routes superseded intent to its replacement (' + viewport.name + ')', async ({ page }, testInfo) => {
            journeyId(testInfo, 'REC-12');
            await seedDeterministicJourney(page, 'recommendation-rec12-' + viewport.name);
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            const superseded = {
                ...OPEN_BUY_RECOMMENDATION,
                id: 712,
                strategy_name: 'Momentum Core',
                status: 'superseded',
                lifecycle_status: 'superseded',
                review_status: 'superseded',
                can_review: false,
                superseded_by_id: 713,
                target_amount: 50000,
            };
            const replacement = {
                ...OPEN_BUY_RECOMMENDATION,
                id: 713,
                strategy_name: 'Momentum Core',
                strategy_version: 2,
                position_target_amount: 75000,
                status: 'pending_review',
                lifecycle_status: 'pending_review',
                review_status: 'pending_review',
                can_review: true,
                target_amount: 75000,
                suggested_quantity: 5,
                suggested_investment_amount: 37500,
                reasoning: 'Replacement after the strategy target changed.',
                evidence: {
                    ...OPEN_BUY_RECOMMENDATION.evidence,
                    strategy_version: 2,
                },
            };
            await installTosApiMocks(page, { recommendations: [superseded, replacement] });
            const observedRequests = [];
            page.on('request', (request) => observedRequests.push(new URL(request.url()).pathname));
            await page.goto('/recommendations');
            const oldRow = page.getByRole('row').filter({ hasText: 'Momentum Core' }).filter({ hasText: 'superseded' });
            await expect(oldRow).toHaveCount(0);
            const historyRequest = page.waitForRequest((request) => (
                request.method() === 'GET'
                && new URL(request.url()).pathname.endsWith('/api/v1/recommendations')
                && new URL(request.url()).searchParams.get('all') === '1'
            ));
            await page.getByLabel('Include closed history').check();
            await historyRequest;
            await expect(oldRow).toBeVisible();
            await oldRow.getByRole('button', { name: 'Review' }).click();
            let dialog = page.getByRole('dialog');
            await expect(dialog).toContainText('superseded');
            await expect(dialog).toContainText('no longer a current execution instruction');
            await expect(dialog.getByRole('button', { name: 'View replacement recommendation' })).toBeVisible();
            await expect(dialog.getByRole('button', { name: 'Approve' })).toHaveCount(0);
            const replacementRequest = page.waitForRequest((request) => (
                request.method() === 'GET' && new URL(request.url()).pathname.endsWith('/api/v1/recommendations/713')
            ));
            await dialog.getByRole('button', { name: 'View replacement recommendation' }).click();
            await replacementRequest;
            dialog = page.getByRole('dialog');
            await expect(dialog).toContainText('Open INFY');
            await expect(dialog).toContainText('pending_review');
            await expect(dialog).toContainText('Momentum Core');
            await expect(dialog).toContainText('Target ₹75000');
            await expect(dialog).toContainText('Version 2');
            await expect(dialog).toContainText('5 shares');
            await expect(dialog).toContainText('Replacement after the strategy target changed.');
            expect(observedRequests.some((path) => /\/orders(?:\/|$)/.test(path))).toBe(false);
        });

        test('REC-09 defers a recommendation and reopens it for review later (' + viewport.name + ')', async ({ page }, testInfo) => {
            journeyId(testInfo, 'REC-09');
            await seedDeterministicJourney(page, 'recommendation-rec09-' + viewport.name);
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            await installTosApiMocks(page, { recommendations: [{ ...OPEN_BUY_RECOMMENDATION, id: 709, strategy_name: 'Momentum Core' }] });
            await page.goto('/recommendations');
            let row = page.getByRole('row').filter({ hasText: 'Momentum Core' });
            await row.getByRole('button', { name: 'Review' }).click();
            let dialog = page.getByRole('dialog');
            await dialog.getByLabel('Review notes (optional)').fill('Wait for the next earnings update.');
            const deferRequest = page.waitForRequest((request) => (
                request.method() === 'POST' && new URL(request.url()).pathname.endsWith('/api/v1/recommendations/709/review')
            ));
            await dialog.getByRole('button', { name: 'Defer' }).click();
            expect((await deferRequest).postDataJSON()).toMatchObject({
                decision: 'deferred',
                notes: 'Wait for the next earnings update.',
            });
            await expect(dialog).toHaveCount(0);
            row = page.getByRole('row').filter({ hasText: 'Momentum Core' });
            await expect(row).toContainText('deferred');
            await row.getByRole('button', { name: 'Review' }).click();
            dialog = page.getByRole('dialog');
            const reopenRequest = page.waitForRequest((request) => (
                request.method() === 'POST' && new URL(request.url()).pathname.endsWith('/api/v1/recommendations/709/reopen')
            ));
            await dialog.getByRole('button', { name: 'Undo decision — reopen for review' }).click();
            await reopenRequest;
            await expect(dialog).toContainText('pending_review');
            await expect(dialog.getByRole('button', { name: 'Undo decision — reopen for review' })).toHaveCount(0);
        });
    }
});
