import { test, expect } from '@playwright/test';
import { installTosApiMocks } from './tosApiMocks.js';

const REPRESENTATIVE_ROUTES = [
    { path: '/', label: 'Dashboard' },
    { path: '/holdings', label: 'Holdings' },
    { path: '/recommendations', label: 'Recommendations' },
    { path: '/transactions/pending', label: 'Pending Execution' },
    { path: '/cash', label: 'Cash' },
    { path: '/strategy', label: 'Strategies' },
    { path: '/knowledge-board', label: 'Knowledge Board' },
];

async function installAndOpen(page, path = '/recommendations', options = {}) {
    await installTosApiMocks(page, options);
    await page.goto(path);
    await expect(page.locator('.lido-page-title')).toBeVisible();
}

async function assertNoDocumentOverflow(page) {
    const overflow = await page.evaluate(() => ({
        scrollWidth: document.documentElement.scrollWidth,
        clientWidth: document.documentElement.clientWidth,
    }));
    expect(overflow.scrollWidth).toBeLessThanOrEqual(overflow.clientWidth + 2);
}

async function assertRouteArchetypes(page) {
    for (const route of REPRESENTATIVE_ROUTES) {
        await page.goto(route.path);
        await expect(page.locator('.lido-page-title')).toBeVisible();
        await expect(page.locator('.lido-page-title')).toContainText(route.label);
        await assertNoDocumentOverflow(page);
    }
}

test.describe('responsive shell and representative page archetypes', () => {
    test('mobile shell keeps navigation, utilities, search, help and profile reachable', async ({ page }) => {
        test.skip((page.viewportSize()?.width || 0) >= 768, 'mobile-only shell assertions');
        await installAndOpen(page);

        await expect(page.locator('#lido-primary-sidebar')).toHaveAttribute('aria-hidden', 'true');
        const sidebarToggle = page.getByRole('button', { name: 'Open navigation' });
        await expect(sidebarToggle).toBeVisible();
        await sidebarToggle.click();
        await expect(page.locator('#lido-primary-sidebar')).not.toHaveAttribute('aria-hidden', 'true');
        await expect(page.getByRole('link', { name: 'Holdings', exact: true })).toBeVisible();
        await page.keyboard.press('Escape');
        await expect(page.locator('#lido-primary-sidebar')).toHaveAttribute('aria-hidden', 'true');
        expect(await page.evaluate(() => document.body.style.overflow)).toBe('');

        await expect(page.getByRole('button', { name: 'Open global search' })).toBeVisible();
        await expect(page.getByText('Normal', { exact: true })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Emergency Kite disconnect' })).toBeVisible();
        await page.getByRole('button', { name: 'Open global search' }).click();
        await expect(page.getByRole('dialog', { name: 'Search StoX' })).toBeVisible();
        await page.keyboard.press('Escape');
        await expect(page.getByRole('dialog', { name: 'Search StoX' })).toHaveCount(0);

        await expect(page.getByRole('button', { name: 'Open page history' })).toBeVisible();
        await page.getByRole('button', { name: 'Open page history' }).click();
        await expect(page.getByRole('dialog', { name: 'History' })).toBeVisible();
        await page.keyboard.press('Escape');
        await expect(page.getByRole('dialog', { name: 'History' })).toHaveCount(0);

        await page.getByRole('button', { name: 'Open contextual notes' }).click();
        await expect(page.getByRole('dialog', { name: 'Notes' })).toBeVisible();
        await page.keyboard.press('Escape');
        await expect(page.getByRole('dialog', { name: 'Notes' })).toHaveCount(0);

        await expect(page.getByRole('button', { name: 'Open documentation for this page' })).toBeVisible();
        const profileToggle = page.locator('.lido-profile-toggle');
        await profileToggle.scrollIntoViewIfNeeded();
        await profileToggle.click();
        await expect(page.getByRole('link', { name: /Smoke Tester/ }).first()).toBeVisible();
        await assertNoDocumentOverflow(page);
    });

    test('mobile representative routes preserve page access and dense-content containment', async ({ page }) => {
        test.skip((page.viewportSize()?.width || 0) >= 768, 'mobile-only route assertions');
        await installAndOpen(page, '/');
        await assertRouteArchetypes(page);

        await page.goto('/holdings');
        const tableContainers = page.locator('.table-responsive, [role="table"]');
        if (await tableContainers.count()) {
            await expect(tableContainers.first()).toBeVisible();
        }
        await assertNoDocumentOverflow(page);
    });

    test('tablet keeps primary workspace usable with overlay navigation and utilities', async ({ page }) => {
        const width = page.viewportSize()?.width || 0;
        test.skip(width < 768 || width >= 1200, 'tablet-only shell assertions');
        await installAndOpen(page, '/cash');
        await expect(page.locator('#lido-primary-sidebar')).toHaveAttribute('aria-hidden', 'true');
        await page.getByRole('button', { name: 'Open navigation' }).click();
        await expect(page.locator('.lido-sidebar-backdrop')).toBeVisible();
        await expect(page.getByRole('link', { name: 'Recommendations', exact: true })).toBeVisible();
        await page.locator('.lido-sidebar-backdrop').click();
        await expect(page.locator('.lido-sidebar-backdrop')).toHaveCount(0);
        await expect(page.locator('.lido-main')).toBeVisible();
        await assertNoDocumentOverflow(page);
    });

    test('desktop shell exposes persistent workspace and coordinated utility rail', async ({ page }) => {
        const width = page.viewportSize()?.width || 0;
        test.skip(width < 1200 || width >= 1600, 'standard desktop shell assertions');
        await installAndOpen(page, '/recommendations');
        await expect(page.locator('#lido-primary-sidebar')).toBeVisible();
        await expect(page.locator('#lido-primary-sidebar')).not.toHaveAttribute('aria-hidden', 'true');
        await expect(page.getByRole('navigation', { name: 'Page visit history' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Open contextual notes' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Open global search' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Collapse sidebar' })).toBeVisible();
        await page.getByRole('button', { name: 'Collapse sidebar' }).click();
        await expect(page.getByRole('button', { name: 'Expand sidebar' })).toBeVisible();
        await assertNoDocumentOverflow(page);
    });

    test('large displays retain bounded content and reachable shell utilities', async ({ page }) => {
        test.skip((page.viewportSize()?.width || 0) < 1600, 'large-display shell assertions');
        await installAndOpen(page, '/strategy');
        const geometry = await page.locator('.lido-main').evaluate((element) => {
            const rect = element.getBoundingClientRect();
            return { width: rect.width, viewport: window.innerWidth };
        });
        expect(geometry.width).toBeGreaterThan(900);
        expect(geometry.width).toBeLessThanOrEqual(geometry.viewport);
        await expect(page.getByRole('button', { name: 'Open global search' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Open documentation for this page' })).toBeVisible();
        await assertNoDocumentOverflow(page);
    });

    test('admin shell remains reachable at representative desktop width', async ({ page }) => {
        test.skip((page.viewportSize()?.width || 0) < 1200, 'desktop admin shell assertions');
        await installAndOpen(page, '/settings/users', {
            user: {
                id: 2,
                name: 'Admin Tester',
                email: 'admin@example.com',
                is_admin: true,
                default_portfolio_id: 1,
            },
        });
        await expect(page.locator('.lido-page-title')).toContainText('Users');
        await expect(page.getByRole('navigation', { name: 'Primary' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Open documentation for this page' })).toBeVisible();
        await assertNoDocumentOverflow(page);
    });
});
