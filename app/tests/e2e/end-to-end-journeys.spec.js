import { test, expect } from '@playwright/test';
import { OPEN_BUY_RECOMMENDATION } from '../js/tos/fixtures/tosApi.js';
import { seedDeterministicJourney, journeyId } from './journeyTestUtils.js';
import { installTosApiMocks } from './tosApiMocks.js';

const VIEWPORTS = [
    { name: 'mobile', width: 390, height: 844 },
    { name: 'desktop', width: 1440, height: 900 },
];

test.describe('V9-UX-001 composite execution journeys', () => {
    for (const viewport of VIEWPORTS) {
        test(`E2E-05 approves, cancels, reconciles zero fills, and safely retries the same intent (${viewport.name})`, async ({ page }, testInfo) => {
            journeyId(testInfo, 'E2E-05');
            await seedDeterministicJourney(page, `e2e05-cancel-retry-${viewport.name}`);
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            const recommendation = {
                ...OPEN_BUY_RECOMMENDATION,
                id: 905,
                status: 'pending_execution',
                execution_status: 'pending',
                order_side: 'buy',
                strategy_name: 'Momentum Core',
                suggested_quantity: 5,
                suggested_investment_amount: 17500,
                reserved_amount: 17500,
            };
            await installTosApiMocks(page, { recommendations: [], orders: [] });
            await page.route('**/api/v1/execution/mode', (route) => route.fulfill({
                json: { success: true, data: { execution_mode: 'semi_automatic', execution_code_label: 'StoX execution code — Authenticator app', blockers: [], can_submit_semi_automatic: true } },
            }));
            await page.route('**/api/v1/recommendations/pending-execution', (route) => route.fulfill({
                json: { success: true, data: [recommendation], meta: { cash: { cash_balance: 50000, reserved_cash: 17500, available_investable_cash: 32500 } } },
            }));
            await page.route('**/api/v1/review/dashboard', (route) => route.fulfill({
                json: { success: true, data: { portfolio: {}, actionable_counts: {}, informational_counts: {}, outcomes: [], informational_outcomes: [], recent_reviews: [] } },
            }));
            const attempts = [];
            let orders = [];
            await page.route('**/api/v1/orders', (route) => route.fulfill({ json: { success: true, data: orders } }));
            await page.route('**/api/v1/execution/submit-selected', async (route) => {
                const payload = route.request().postDataJSON();
                attempts.push(payload);
                orders = [...orders, {
                    id: 950 + attempts.length,
                    recommendation_id: 905,
                    symbol: 'INFY',
                    side: 'buy',
                    quantity: 5,
                    status: 'pending',
                    broker_status: attempts.length === 1 ? 'open' : 'submitted',
                    filled_quantity: 0,
                }];
                await route.fulfill({ json: { success: true, data: { submitted: 1, blocked: 0, skipped: 0, results: [] } } });
            });
            await page.route('**/api/v1/orders/951/cancel', async (route) => {
                await route.fulfill({ json: { success: true, data: { cancellation_status: 'pending' } } });
            });
            await page.route('**/api/v1/orders/951/reconcile', async (route) => {
                orders = orders.map((order) => order.id === 951 ? { ...order, broker_status: 'cancelled', filled_quantity: 0 } : order);
                await route.fulfill({ json: { success: true, data: orders.find((order) => order.id === 951) } });
            });

            await page.goto('/transactions/pending');
            await page.getByLabel('Select INFY').check();
            await page.getByLabel('StoX execution code — Authenticator app').fill('123456');
            await page.getByRole('button', { name: 'Accept / Execute Selected' }).click();
            await expect(page.getByText('Submitted to broker')).toBeVisible();
            expect(attempts[0]).toEqual({ recommendation_ids: [905], totp: '123456' });
            await expect(page.getByLabel('Select INFY')).toBeDisabled();

            await page.goto('/review');
            const orderRow = page.getByRole('row').filter({ hasText: 'INFY' });
            await expect(orderRow).toContainText('open');
            await orderRow.getByRole('button', { name: 'Cancel' }).click();
            await expect(page.getByText('Cancellation requested; waiting for Kite confirmation.')).toBeVisible();
            await orderRow.getByRole('button', { name: 'Reconcile' }).click();
            await expect(page.getByText('Broker confirms cancelled. Check the filled quantity before retrying.')).toBeVisible();
            await expect(orderRow).toContainText('cancelled');
            await expect(orderRow).toContainText('0');

            await page.goto('/transactions/pending');
            const pendingRow = page.getByRole('row').filter({ hasText: 'INFY' });
            await expect(pendingRow).toContainText('Previous order: cancelled; filled across attempts 0/5');
            await pendingRow.getByLabel('Select INFY').check();
            await page.getByLabel('StoX execution code — Authenticator app').fill('654321');
            await page.getByRole('button', { name: 'Accept / Execute Selected' }).click();
            await expect(page.getByText('Submitted to broker')).toBeVisible();
            expect(attempts).toEqual([
                { recommendation_ids: [905], totp: '123456' },
                { recommendation_ids: [905], totp: '654321' },
            ]);
            expect(orders.filter((order) => order.recommendation_id === 905)).toHaveLength(2);
        });
    }
});
