import { test, expect } from '@playwright/test';
import { installTosApiMocks } from './tosApiMocks.js';
import { TEST_PORTFOLIO, TEST_USER } from '../js/tos/fixtures/tosApi.js';

async function openDashboard(page, initialStatus) {
    await installTosApiMocks(page, { user: TEST_USER });
    let status = initialStatus;
    const actions = [];
    await page.route(/\/api\//, async (route) => {
        const request = route.request();
        const url = new URL(request.url());
        if (url.pathname.endsWith('/api/v1/broker/status')) {
            if (status === 'error') return route.fulfill({ status: 503, json: { message: 'unavailable' } });
            return route.fulfill({ json: { success: true, data: status } });
        }
        if (url.pathname.endsWith('/api/portfolios') && request.method() === 'GET') {
            return route.fulfill({ json: { data: [{ ...TEST_PORTFOLIO, execution_mode: 'automatic' }] } });
        }
        if (url.pathname.endsWith('/api/reconciliation')) {
            if (request.method() === 'POST') actions.push(['reconcile']);
            return route.fulfill({ json: { success: true, data: { status: {}, runs: [] } } });
        }
        if (url.pathname.endsWith('/api/v1/broker/kite/login-url')) {
            actions.push(['login', url.searchParams.get('return_to')]);
            return route.fulfill({ json: { success: true, data: { url: 'https://kite.example.test/login' } } });
        }
        if (url.pathname.endsWith('/api/v1/broker/kite/disconnect') && request.method() === 'POST') {
            actions.push(['disconnect']);
            status = { configured: true, connected: false, usable: false, expires_at: null };
            return route.fulfill({ json: { success: true, data: {} } });
        }
        return route.fallback();
    });
    await page.goto('/');
    const control = page.locator('.lido-kite-header-control');
    await expect(control.getByRole('button', { name: /Kite/ }).first()).toBeVisible();
    return { control, actions, setStatus: (next) => { status = next; } };
}

test('disconnected primary starts the dashboard return login flow', async ({ page }) => {
    const { control, actions } = await openDashboard(page, { configured: true, connected: false, usable: false, expires_at: null });
    await control.getByRole('button', { name: /Kite/ }).first().click();
    await expect.poll(() => actions).toEqual([['login', 'dashboard']]);
});

test('connected Kite menu confirms normal disconnect and refreshes status', async ({ page }) => {
    const { control, actions } = await openDashboard(page, { configured: true, connected: true, usable: true, expires_at: '2026-11-22T00:30:00Z' });
    page.on('dialog', (dialog) => dialog.accept());
    await control.getByRole('button', { name: /menu/i }).click();
    await page.getByRole('menuitem', { name: /Disconnect Kite/ }).click();
    await expect.poll(() => actions).toContainEqual(['disconnect']);
    expect(actions.some(([action]) => action === 'login')).toBe(false);
});

test('reconciliation stays disabled without usable Kite and runs when connected', async ({ page }) => {
    const { actions } = await openDashboard(page, { configured: true, connected: false, usable: false, expires_at: null });
    const card = page.getByText('Broker portfolio reconciliation').locator('..').locator('..');
    const primary = card.getByRole('button', { name: 'Reconcile excluding today', exact: true });
    await primary.scrollIntoViewIfNeeded();
    await expect(primary).toBeDisabled();
    await expect(card.getByText('Connect Kite to run portfolio reconciliation.')).toBeVisible();
    expect(actions.some(([action]) => action === 'reconcile')).toBe(false);
});

test('usable Kite enables reconciliation and preserves exclude-today as the primary choice', async ({ page }) => {
    const { actions } = await openDashboard(page, { configured: true, connected: true, usable: true, expires_at: '2026-11-22T00:30:00Z' });
    const card = page.getByText('Broker portfolio reconciliation').locator('..').locator('..');
    const primary = card.getByRole('button', { name: 'Reconcile excluding today', exact: true });
    await primary.scrollIntoViewIfNeeded();
    await expect(primary).toBeEnabled();
    await primary.click();
    await expect.poll(() => actions).toContainEqual(['reconcile']);
});

test('expired and unknown broker status remain neutral or fail closed', async ({ page }) => {
    const expired = await openDashboard(page, { configured: true, connected: true, usable: true, expires_at: '2020-01-01T00:00:00Z' });
    await expect(expired.control.getByRole('button', { name: 'Kite session expired · Connect Kite', exact: true })).toBeVisible();
    const card = page.getByText('Broker portfolio reconciliation').locator('..').locator('..');
    await expect(card.getByRole('button', { name: 'Reconcile excluding today', exact: true })).toBeDisabled();

    expired.setStatus('error');
    await page.reload();
    await expect(page.locator('.lido-kite-header-control').getByRole('button', { name: 'Kite status unknown · Refresh to check', exact: true })).toBeVisible();
    const unavailableCard = page.getByText('Broker portfolio reconciliation').locator('..').locator('..');
    await expect(unavailableCard.getByRole('button', { name: 'Reconcile excluding today', exact: true })).toBeDisabled();
});
