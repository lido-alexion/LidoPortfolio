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


test.describe('V9-UX-001 actual transaction recording', () => {
    for (const viewport of VIEWPORTS) {
        test(`EXE-02 records actual fill details in the ledger (${viewport.name})`, async ({ page }, testInfo) => {
            journeyId(testInfo, 'EXE-02');
            await seedDeterministicJourney(page, `execution-exe02-${viewport.name}`);
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            await installTosApiMocks(page);
            let savedTransaction = null;
            await page.route('**/api/stocks/validate', (route) => route.fulfill({
                json: { source: 'test fixture', data: { id: 42, symbol: 'INFY', name: 'Infosys Limited', exchange: 'NSE' }, meta: { cached: false } },
            }));
            await page.route('**/api/settings', (route) => route.fulfill({ json: { data: { fee_components: [] } } }));
            await page.route('**/api/transactions*', async (route) => {
                if (route.request().method() === 'POST') {
                    savedTransaction = route.request().postDataJSON();
                    await route.fulfill({ json: { message: 'Transaction saved', data: { id: 420 } } });
                    return;
                }
                await route.fulfill({ json: { data: [] } });
            });

            await page.goto('/transactions');
            await page.getByLabel('Stock symbol').fill('INFY');
            await page.getByRole('button', { name: 'Validate symbol' }).click();
            await expect(page.getByText(/Validated via test fixture/)).toBeVisible();
            await page.getByLabel('Quantity').fill('2');
            await page.getByLabel('Price').fill('3500');
            await page.getByRole('button', { name: 'Save Transaction' }).click();
            await expect(page.getByText('Transaction saved')).toBeVisible();
            expect(savedTransaction).toMatchObject({
                stock_id: 42,
                type: 'buy',
                quantity: 2,
                price: 3500,
                transaction_date: expect.any(String),
            });
        });
    }
});


async function installSemiAutomaticMocks(page, recommendation) {
    await installTosApiMocks(page, { recommendations: [] });
    await page.route('**/api/v1/execution/mode', (route) => route.fulfill({
        json: { success: true, data: {
            execution_mode: 'semi_automatic',
            execution_code_label: 'StoX execution code — Microsoft Authenticator',
            blockers: [],
            can_submit_semi_automatic: true,
            can_submit_automatic: false,
        } },
    }));
    await page.route('**/api/v1/recommendations/pending-execution', (route) => route.fulfill({
        json: { success: true, data: [recommendation], meta: { cash: { cash_balance: 50000, reserved_cash: 17500, available_investable_cash: 32500 } } },
    }));
}

test.describe('V9-UX-001 semi-automatic authorization', () => {
    for (const viewport of VIEWPORTS) {
        test(`EXE-03 submits only the selected approved intent after explicit authorization (${viewport.name})`, async ({ page }, testInfo) => {
            journeyId(testInfo, 'EXE-03');
            journeyId(testInfo, 'EXE-06');
            await seedDeterministicJourney(page, `execution-exe03-${viewport.name}`);
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            const recommendation = {
                ...OPEN_BUY_RECOMMENDATION,
                id: 903,
                status: 'pending_execution',
                execution_status: 'pending',
                order_side: 'buy',
                suggested_quantity: 5,
                suggested_investment_amount: 17500,
                reserved_amount: 17500,
            };
            await installSemiAutomaticMocks(page, recommendation);
            let submitPayload = null;
            await page.route('**/api/v1/execution/submit-selected', async (route) => {
                submitPayload = route.request().postDataJSON();
                await route.fulfill({ json: { success: true, data: { submitted: 1, blocked: 0, skipped: 0, results: [] } } });
            });

            await page.goto('/transactions/pending');
            await page.getByLabel('Select INFY').check();
            await page.getByLabel('StoX execution code — Microsoft Authenticator').fill('123456');
            await page.getByRole('button', { name: 'Accept / Execute Selected' }).click();
            await expect(page.getByText('Submitted to broker')).toBeVisible();
            expect(submitPayload).toEqual({ recommendation_ids: [903], totp: '123456' });
        });

        test(`EXE-04 identifies the StoX authenticator code and separates it from recovery codes (${viewport.name})`, async ({ page }, testInfo) => {
            journeyId(testInfo, 'EXE-04');
            await seedDeterministicJourney(page, `execution-exe04-${viewport.name}`);
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            const recommendation = {
                ...OPEN_BUY_RECOMMENDATION,
                id: 904,
                status: 'pending_execution',
                execution_status: 'pending',
                order_side: 'buy',
            };
            await installSemiAutomaticMocks(page, recommendation);
            let submitCalls = 0;
            await page.route('**/api/v1/execution/submit-selected', async (route) => {
                submitCalls++;
                await route.fulfill({ json: { success: true, data: { submitted: 0, blocked: 1, skipped: 0, results: [] } } });
            });

            await page.goto('/transactions/pending');
            await expect(page.getByLabel('StoX execution code — Microsoft Authenticator')).toBeVisible();
            await expect(page.getByText(/recovery codes are one-time backups and are not accepted here/)).toBeVisible();
            await expect(page.getByRole('button', { name: 'Accept / Execute Selected' })).toBeDisabled();
            expect(submitCalls).toBe(0);
        });
    }
});


test.describe('V9-UX-001 strategy-owned SELL execution', () => {
    for (const viewport of VIEWPORTS) {
        test(`EXE-07 submits only the strategy-owned EXIT quantity (${viewport.name})`, async ({ page }, testInfo) => {
            journeyId(testInfo, 'EXE-07');
            await seedDeterministicJourney(page, `execution-exe07-${viewport.name}`);
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            const recommendation = {
                ...OPEN_BUY_RECOMMENDATION,
                id: 907,
                status: 'pending_execution',
                execution_status: 'pending',
                order_side: 'sell',
                portfolio_action: 'EXIT',
                ui_label: 'EXIT',
                strategy_id: 17,
                strategy_name: 'Momentum Core',
                holding_episode_id: 'momentum-core-lot-17',
                suggested_quantity: 10,
                suggested_investment_amount: 35000,
                reference_price: 3500,
            };
            await installSemiAutomaticMocks(page, recommendation);
            let submitPayload = null;
            await page.route('**/api/v1/execution/submit-selected', async (route) => {
                submitPayload = route.request().postDataJSON();
                await route.fulfill({ json: { success: true, data: { submitted: 1, blocked: 0, skipped: 0, results: [] } } });
            });

            await page.goto('/transactions/pending');
            const row = page.getByRole('row').filter({ hasText: 'INFY' });
            await expect(row).toContainText('Momentum Core');
            await expect(row).toContainText('EXIT');
            await expect(row).toContainText('10');
            await row.getByLabel('Select INFY').check();
            await page.getByLabel('StoX execution code — Microsoft Authenticator').fill('123456');
            await page.getByRole('button', { name: 'Accept / Execute Selected' }).click();
            await expect(page.getByText('Submitted to broker')).toBeVisible();
            expect(submitPayload).toEqual({ recommendation_ids: [907], totp: '123456' });
        });
    }
});


test.describe('V9-UX-001 broker final and partial outcomes', () => {
    for (const viewport of VIEWPORTS) {
        test(`EXE-10 does not record a transaction for a final unfilled cancellation (${viewport.name})`, async ({ page }, testInfo) => {
            journeyId(testInfo, 'EXE-10');
            await seedDeterministicJourney(page, `execution-exe10-${viewport.name}`);
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            await installTosApiMocks(page);
            const order = { id: 910, symbol: 'INFY', side: 'buy', quantity: 5, status: 'pending', broker_status: 'cancelled', filled_quantity: 0 };
            await page.route('**/api/v1/review/dashboard', (route) => route.fulfill({ json: { success: true, data: { portfolio: {}, actionable_counts: {}, informational_counts: {}, outcomes: [], informational_outcomes: [], recent_reviews: [] } } }));
            await page.route('**/api/v1/orders', (route) => route.fulfill({ json: { success: true, data: [order] } }));

            await page.goto('/review');
            const row = page.getByRole('row').filter({ hasText: 'INFY' });
            await expect(row).toContainText('cancelled');
            await expect(row).toContainText('0');
            await expect(row.getByRole('button', { name: 'Add transaction' })).toHaveCount(0);
        });

        test(`EXE-11 shows a partial fill and remaining in-flight state without a duplicate ledger action (${viewport.name})`, async ({ page }, testInfo) => {
            journeyId(testInfo, 'EXE-11');
            await seedDeterministicJourney(page, `execution-exe11-${viewport.name}`);
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            await installTosApiMocks(page);
            const order = { id: 911, symbol: 'INFY', side: 'buy', quantity: 5, status: 'pending', broker_status: 'partial', filled_quantity: 2, average_fill_price: 3499 };
            await page.route('**/api/v1/review/dashboard', (route) => route.fulfill({ json: { success: true, data: { portfolio: {}, actionable_counts: {}, informational_counts: {}, outcomes: [], informational_outcomes: [], recent_reviews: [] } } }));
            await page.route('**/api/v1/orders', (route) => route.fulfill({ json: { success: true, data: [order] } }));

            await page.goto('/review');
            const row = page.getByRole('row').filter({ hasText: 'INFY' });
            await expect(row).toContainText('partial');
            await expect(row).toContainText('2');
            await expect(row.getByRole('button', { name: 'Reconcile' })).toBeVisible();
            await expect(row.getByRole('button', { name: 'Add transaction' })).toHaveCount(0);
        });

        test(`EXE-12 reports broker rejection reason and does not create a transaction (${viewport.name})`, async ({ page }, testInfo) => {
            journeyId(testInfo, 'EXE-12');
            await seedDeterministicJourney(page, `execution-exe12-${viewport.name}`);
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            await installTosApiMocks(page);
            const order = { id: 912, symbol: 'INFY', side: 'buy', quantity: 5, status: 'pending', broker_status: 'rejected', broker_error_message: 'Insufficient funds', filled_quantity: 0 };
            await page.route('**/api/v1/review/dashboard', (route) => route.fulfill({ json: { success: true, data: { portfolio: {}, actionable_counts: {}, informational_counts: {}, outcomes: [], informational_outcomes: [], recent_reviews: [] } } }));
            await page.route('**/api/v1/orders', (route) => route.fulfill({ json: { success: true, data: [order] } }));

            await page.goto('/review');
            const row = page.getByRole('row').filter({ hasText: 'INFY' });
            await expect(row).toContainText('rejected');
            await expect(row).toContainText('Insufficient funds');
            await expect(row).toContainText('0');
            await expect(row.getByRole('button', { name: 'Add transaction' })).toHaveCount(0);
        });
    }
});
