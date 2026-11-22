import { test, expect } from '@playwright/test';
import { installTosApiMocks } from './tosApiMocks.js';
import { TEST_PORTFOLIO, TEST_USER } from '../js/tos/fixtures/tosApi.js';

// Keep usable-session fixtures valid regardless of when CI executes.
const activeSessionExpiry = () => new Date(Date.now() + 24 * 60 * 60 * 1000).toISOString();

async function openDashboard(page, initialStatus, user = TEST_USER) {
    await installTosApiMocks(page, { user });
    let status = initialStatus;
    const actions = [];
    let statusReads = 0;
    await page.route(/\/api\//, async (route) => {
        const request = route.request();
        const url = new URL(request.url());
        if (url.pathname.endsWith('/api/v1/broker/status')) {
            statusReads += 1;
            if (status === 'error') return route.fulfill({ status: 503, json: { message: 'unavailable' } });
            return route.fulfill({ json: { success: true, data: status } });
        }
        if (url.pathname.endsWith('/api/portfolios') && request.method() === 'GET') {
            return route.fulfill({ json: { data: [{ ...TEST_PORTFOLIO, execution_mode: 'automatic' }] } });
        }
        if (url.pathname.endsWith('/api/reconciliation/trades/sync') && request.method() === 'POST') {
            actions.push(['trade-sync']);
            return route.fulfill({ json: { success: true, data: { imported: 0, matched: 0, review: 0 } } });
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
    if (!user.is_admin) await expect(control.getByRole('button', { name: /Kite/ }).first()).toBeVisible();
    return { control, actions, statusReads: () => statusReads, setStatus: (next) => { status = next; } };
}

test('administrator shell has no investor Kite header control or broker status read', async ({ page }) => {
    const admin = { ...TEST_USER, is_admin: true };
    const { statusReads } = await openDashboard(page, { configured: true, connected: false, usable: false, expires_at: null }, admin);
    await expect(page.locator('.lido-header')).toBeVisible();
    await expect(page.locator('.lido-kite-header-control')).toHaveCount(0);
    expect(statusReads()).toBe(0);
});

test('disconnected primary starts the dashboard return login flow', async ({ page }) => {
    const { control, actions } = await openDashboard(page, { configured: true, connected: false, usable: false, expires_at: null });
    await control.getByRole('button', { name: /Kite/ }).first().click();
    await expect.poll(() => actions).toEqual([['login', 'dashboard']]);
});

test('connected Kite menu confirms normal disconnect and refreshes status', async ({ page }) => {
    const { control, actions } = await openDashboard(page, { configured: true, connected: true, usable: true, expires_at: activeSessionExpiry() });
    page.on('dialog', (dialog) => dialog.accept());
    await control.getByRole('button', { name: /menu/i }).click();
    await page.getByRole('menuitem', { name: /Disconnect Kite/ }).click();
    await expect.poll(() => actions).toContainEqual(['disconnect']);
    expect(actions.some(([action]) => action === 'login')).toBe(false);
});

test('reconciliation stays disabled without usable Kite and runs when connected', async ({ page }) => {
    const { actions } = await openDashboard(page, { configured: true, connected: false, usable: false, expires_at: null });
    const card = page.getByText('Broker portfolio reconciliation').locator('..').locator('..');
    const primary = card.getByRole('button', { name: 'Reconcile', exact: true });
    await primary.scrollIntoViewIfNeeded();
    await expect(primary).toBeDisabled();
    await expect(card.getByText('Connect Kite to run portfolio reconciliation.')).toBeVisible();
    expect(actions.some(([action]) => action === 'reconcile')).toBe(false);
});

test('usable Kite enables one settlement-aware reconciliation action', async ({ page }) => {
    const { actions } = await openDashboard(page, { configured: true, connected: true, usable: true, expires_at: activeSessionExpiry() });
    const card = page.getByText('Broker portfolio reconciliation').locator('..').locator('..');
    const primary = card.getByRole('button', { name: 'Reconcile', exact: true });
    await primary.scrollIntoViewIfNeeded();
    await expect(primary).toBeEnabled();
    await primary.click();
    await expect.poll(() => actions).toContainEqual(['reconcile']);
});

test('expired and unknown broker status remain neutral or fail closed', async ({ page }) => {
    const expired = await openDashboard(page, { configured: true, connected: true, usable: true, expires_at: '2020-01-01T00:00:00Z' });
    await expect(expired.control.getByRole('button', { name: 'Kite session expired · Connect Kite', exact: true })).toBeVisible();
    const card = page.getByText('Broker portfolio reconciliation').locator('..').locator('..');
    await expect(card.getByRole('button', { name: 'Reconcile', exact: true })).toBeDisabled();

    expired.setStatus('error');
    await page.reload();
    await expect(page.locator('.lido-kite-header-control').getByRole('button', { name: 'Kite status unknown · Refresh to check', exact: true })).toBeVisible();
    const unavailableCard = page.getByText('Broker portfolio reconciliation').locator('..').locator('..');
    await expect(unavailableCard.getByRole('button', { name: 'Reconcile', exact: true })).toBeDisabled();
});

test('connected Kite read-only split menu opens and closes without broker mutations', async ({ page }) => {
    const { control, actions } = await openDashboard(page, { configured: true, connected: true, usable: true, expires_at: activeSessionExpiry() });
    const primary = control.getByRole('button').first();
    const menu = control.locator('button[aria-haspopup="menu"]');
    await expect(menu).toHaveCount(1);
    await expect(menu).toBeEnabled();
    await expect(menu).toHaveAttribute('aria-label', `${await primary.getAttribute('aria-label')} menu`);
    await menu.click();
    await expect(menu).toHaveAttribute('aria-expanded', 'true');
    await expect(page.getByRole('menuitem', { name: 'Kite settings', exact: true })).toBeVisible();
    await expect(page.getByRole('menuitem', { name: 'Disconnect Kite…', exact: true })).toBeVisible();
    await menu.click();
    await expect(menu).toHaveAttribute('aria-expanded', 'false');
    expect(actions).toEqual([]);
});
