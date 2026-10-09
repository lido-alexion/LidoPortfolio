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


test.describe('V9-UX-001 manual execution journeys', () => {
    for (const viewport of VIEWPORTS) {
        test(`EXE-01 opens a broker fill form prefilled from the approved recommendation (${viewport.name})`, async ({ page }, testInfo) => {
            journeyId(testInfo, 'EXE-01');
            await seedDeterministicJourney(page, `execution-exe01-${viewport.name}`);
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            const recommendation = {
                ...OPEN_BUY_RECOMMENDATION,
                id: 801,
                status: 'pending_execution',
                lifecycle_status: 'pending_execution',
                execution_status: 'pending',
                can_execute_manually: true,
                order_side: 'buy',
                suggested_quantity: 5,
                suggested_investment_amount: 17500,
                reserved_amount: 17500,
                reference_price: 3500,
            };
            await installTosApiMocks(page, { recommendations: [recommendation] });
            await page.route('**/api/transactions*', (route) => route.fulfill({ json: { data: [] } }));
            await page.route('**/api/settings', (route) => route.fulfill({ json: { data: { fee_components: [] } } }));
            let ledgerWrites = 0;
            page.on('request', (request) => {
                if (request.method() === 'POST' && new URL(request.url()).pathname.endsWith('/api/transactions')) ledgerWrites++;
            });

            await page.goto('/transactions/pending');
            const row = page.getByRole('row').filter({ hasText: 'INFY' });
            await row.getByRole('button', { name: 'Execute manually' }).click();
            await expect(page).toHaveURL('/transactions');
            await expect(page.getByText('Recording actual broker fill for recommendation #801.')).toBeVisible();
            await expect(page.getByLabel('Stock symbol')).toHaveValue('INFY');
            await expect(page.getByLabel('Quantity')).toHaveValue('5');
            await expect(page.getByLabel('Price')).toHaveValue('3500');
            expect(ledgerWrites).toBe(0);
        });
    }
});


test.describe('V9-UX-001 cancellation before broker submission', () => {
    for (const viewport of VIEWPORTS) {
        test(`EXE-08 cancels a pending intent without creating a broker order (${viewport.name})`, async ({ page }, testInfo) => {
            journeyId(testInfo, 'EXE-08');
            await seedDeterministicJourney(page, `execution-exe08-${viewport.name}`);
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            const recommendation = {
                ...OPEN_BUY_RECOMMENDATION,
                id: 808,
                status: 'pending_execution',
                lifecycle_status: 'pending_execution',
                execution_status: 'pending',
                can_execute_manually: true,
                order_side: 'buy',
                suggested_quantity: 5,
                suggested_investment_amount: 17500,
                reserved_amount: 17500,
            };
            let cancelled = false;
            let cancelPayload = null;
            let brokerRequests = 0;
            await installTosApiMocks(page, { recommendations: [recommendation] });
            await page.route('**/api/v1/recommendations/pending-execution', (route) => route.fulfill({
                json: {
                    success: true,
                    data: cancelled ? [] : [recommendation],
                    meta: { cash: { cash_balance: 17500, reserved_cash: cancelled ? 0 : 17500, available_investable_cash: cancelled ? 17500 : 0 } },
                },
            }));
            await page.route('**/api/v1/recommendations/808/cancel-execution', async (route) => {
                cancelPayload = route.request().postDataJSON();
                cancelled = true;
                await route.fulfill({ json: { success: true, data: { status: 'cancelled' } } });
            });
            page.on('request', (request) => {
                if (/\/api\/v1\/(execution\/submit-selected|orders?)(\/|$)/.test(new URL(request.url()).pathname)) brokerRequests++;
            });

            await page.goto('/transactions/pending');
            const row = page.getByRole('row').filter({ hasText: 'INFY' });
            await row.getByRole('button', { name: 'Cancel' }).click();
            await page.getByRole('button', { name: 'Confirm cancel' }).click();
            await expect(page.getByText('No recommendations awaiting execution.')).toBeVisible();
            await expect(page.getByText(/Reserved:\s*0/)).toBeVisible();
            await expect(page.getByText(/Available:\s*17,?500/)).toBeVisible();
            expect(cancelPayload).toMatchObject({ reason: 'other' });
            expect(brokerRequests).toBe(0);
        });
    }
});


test.describe('V9-UX-001 broker reconciliation', () => {
    for (const viewport of VIEWPORTS) {
        test(`EXE-13 reconciles an uncertain broker order before any retry (${viewport.name})`, async ({ page }, testInfo) => {
            journeyId(testInfo, 'EXE-13');
            await seedDeterministicJourney(page, `execution-exe13-${viewport.name}`);
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            await installTosApiMocks(page);
            let reconcileCalls = 0;
            const order = {
                id: 813,
                symbol: 'INFY',
                side: 'buy',
                quantity: 5,
                status: 'pending',
                broker_status: 'unknown',
                filled_quantity: 0,
                broker_order_id: 'mock-order-813',
            };
            await page.route('**/api/v1/review/dashboard', (route) => route.fulfill({
                json: { success: true, data: { portfolio: {}, actionable_counts: {}, informational_counts: {}, outcomes: [], informational_outcomes: [], decisions: [] } },
            }));
            await page.route('**/api/v1/orders', (route) => route.fulfill({ json: { success: true, data: [order] } }));
            await page.route('**/api/v1/orders/813/reconcile', async (route) => {
                reconcileCalls++;
                await route.fulfill({ json: { success: true, data: order } });
            });
            const unsafeRequests = [];
            page.on('request', (request) => {
                const path = new URL(request.url()).pathname;
                if (path.endsWith('/api/v1/execution/submit-selected') || path.endsWith('/api/v1/orders/813/execute')) {
                    unsafeRequests.push(path);
                }
            });

            await page.goto('/review');
            const orderRow = page.getByRole('row').filter({ hasText: 'INFY' });
            await expect(orderRow).toContainText('unknown');
            await expect(orderRow).toContainText('0');
            await expect(orderRow.getByRole('button', { name: 'Reconcile' })).toBeVisible();
            await expect(orderRow.getByRole('button', { name: 'Add transaction' })).toHaveCount(0);
            await orderRow.getByRole('button', { name: 'Reconcile' }).click();
            await expect(page.getByText('Broker status remains unknown. Do not retry this order yet.')).toBeVisible();
            expect(reconcileCalls).toBe(1);
            expect(unsafeRequests).toEqual([]);
        });
    }
});


test.describe('V9-UX-001 broker cancellation lifecycle', () => {
    for (const viewport of VIEWPORTS) {
        test(`EXE-09 keeps cancellation requested distinct from broker-confirmed cancellation (${viewport.name})`, async ({ page }, testInfo) => {
            journeyId(testInfo, 'EXE-09');
            await seedDeterministicJourney(page, `execution-exe09-${viewport.name}`);
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            await installTosApiMocks(page);
            let cancellationCalls = 0;
            const submittedOrder = {
                id: 909,
                symbol: 'INFY',
                side: 'buy',
                quantity: 5,
                status: 'pending',
                broker_status: 'open',
                filled_quantity: 0,
            };
            await page.route('**/api/v1/review/dashboard', (route) => route.fulfill({
                json: { success: true, data: { portfolio: {}, actionable_counts: {}, informational_counts: {}, outcomes: [], informational_outcomes: [], recent_reviews: [] } },
            }));
            await page.route('**/api/v1/orders', (route) => route.fulfill({ json: { success: true, data: [submittedOrder] } }));
            await page.route('**/api/v1/orders/909/cancel', async (route) => {
                cancellationCalls++;
                await route.fulfill({ json: { success: true, data: { cancellation_status: 'pending' } } });
            });

            await page.goto('/review');
            const row = page.getByRole('row').filter({ hasText: 'INFY' });
            await expect(row).toContainText('open');
            await row.getByRole('button', { name: 'Cancel' }).click();
            await expect(page.getByText('Cancellation requested; waiting for Kite confirmation.')).toBeVisible();
            await expect(row).toContainText('open');
            expect(cancellationCalls).toBe(1);
        });
    }
});
