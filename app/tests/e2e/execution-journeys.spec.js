import { test, expect } from '@playwright/test';
import { OPEN_BUY_RECOMMENDATION } from '../js/tos/fixtures/tosApi.js';
import { journeyId, seedDeterministicJourney } from './journeyTestUtils.js';
import { installTosApiMocks } from './tosApiMocks.js';

const VIEWPORTS = [
    { name: 'mobile', width: 390, height: 844 },
    { name: 'desktop', width: 1440, height: 900 },
];

test.describe('V9-UX-001 execution journeys', () => {
    for (const viewport of VIEWPORTS) {
        test(`EXE-05 reviews the approved pending execution before action (${viewport.name})`, async ({ page }, testInfo) => {
            journeyId(testInfo, 'EXE-05');
            await seedDeterministicJourney(page, `execution-exe05-${viewport.name}`);
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            const recommendation = {
                ...OPEN_BUY_RECOMMENDATION,
                id: 805,
                status: 'pending_execution',
                lifecycle_status: 'pending_execution',
                execution_status: 'pending',
                order_side: 'buy',
                suggested_quantity: 5,
                suggested_investment_amount: 17500,
                reserved_amount: 17500,
                strategy_name: 'Momentum Core',
            };
            await installTosApiMocks(page, { recommendations: [recommendation] });
            const observedOrderRequests = [];
            page.on('request', (request) => {
                if (/\/api\/v1\/execution\/(submit-selected|orders?)(\/|$)/.test(new URL(request.url()).pathname)) {
                    observedOrderRequests.push(request.method());
                }
            });

            await page.goto('/transactions/pending');
            await expect(page.getByRole('heading', { name: 'Transactions' })).toBeVisible();
            await expect(page.getByText('Pending Execution', { exact: true }).first()).toBeVisible();
            const row = page.getByRole('row').filter({ hasText: 'INFY' });
            await expect(row).toBeVisible();
            await expect(row).toContainText('Open');
            await expect(row).toContainText('5');
            await expect(row).toContainText(/17,?500/);
            await expect(row.getByRole('button', { name: 'Execute manually' })).toBeVisible();
            await expect(row.getByRole('button', { name: 'Cancel' })).toBeVisible();
            expect(observedOrderRequests).toEqual([]);
        });
    }
});
