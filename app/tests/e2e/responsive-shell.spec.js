import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { TEST_PORTFOLIO } from '../js/tos/fixtures/tosApi.js';
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

test.describe('responsive shell and representative page archetypes', () => {
    test('mobile help search opens selected topic and restores it through refresh and history', async ({ page }) => {
        test.skip((page.viewportSize()?.width || 0) >= 768, 'mobile-only help journey');
        await installAndOpen(page);
        await page.getByRole('button', { name: 'Open global search' }).click();
        const searchA11y = await new AxeBuilder({ page }).include('.lido-global-search-surface').analyze();
        expect(searchA11y.violations).toEqual([]);
        const input = page.getByRole('searchbox', { name: 'Search pages, stocks, or help' });
        await input.fill('create screener');
        await page.getByRole('link', { name: /How do I create a screener/i }).click();
        await expect(page).toHaveURL(/documentation\?journey=SCR-01/);
        await expect(page.getByTestId('journey-help-topic')).toContainText('Start creation of a new screener');
        await expect(page.getByRole('link', { name: 'View full guide' })).toHaveAttribute('href', /01-screeners\.html#scr-01/);
        await expect(page.getByRole('button', { name: 'Helpful', exact: true })).toBeVisible();
        await page.goBack();
        await expect(page).toHaveURL(/recommendations/);
        await page.goForward();
        await expect(page.getByTestId('journey-help-topic')).toBeVisible();
        await page.reload();
        await expect(page.getByTestId('journey-help-topic')).toBeVisible();
        await assertNoDocumentOverflow(page);
    });

    test('desktop Ctrl/Cmd+K keyboard selection opens a help topic', async ({ page }) => {
        test.skip((page.viewportSize()?.width || 0) < 1200, 'desktop-only help shortcut');
        await installAndOpen(page);
        await page.keyboard.press('Control+k');
        const input = page.getByRole('searchbox', { name: 'Search pages, stocks, or help' });
        await expect(input).toBeFocused();
        await input.fill('create screener');
        await expect(page.getByRole('link', { name: /How do I create a screener/i })).toBeVisible();
        let activeId = '';
        for (let attempt = 0; attempt < 12 && activeId !== 'global-search-result-help-SCR-01'; attempt += 1) {
            await page.keyboard.press('ArrowDown');
            activeId = await input.getAttribute('aria-activedescendant') || '';
        }
        expect(activeId).toBe('global-search-result-help-SCR-01');
        await page.keyboard.press('Enter');
        await expect(page).toHaveURL(/documentation\?journey=SCR-01/);
        await expect(page.getByTestId('journey-help-topic')).toBeVisible();
    });

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

    for (const route of REPRESENTATIVE_ROUTES) {
        test(`mobile representative route ${route.label} preserves page access and containment`, async ({ page }) => {
            test.skip((page.viewportSize()?.width || 0) >= 768, 'mobile-only route assertions');
            await installAndOpen(page, route.path);
            await expect(page.locator('.lido-page-title')).toContainText(route.label);
            await assertNoDocumentOverflow(page);
        });
    }

    test('mobile Holdings preserves dense-content containment', async ({ page }) => {
        test.skip((page.viewportSize()?.width || 0) >= 768, 'mobile-only route assertions');
        await installAndOpen(page, '/holdings');
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
        await expect(page.getByRole('searchbox', { name: 'Search pages, stocks, or help' })).toBeVisible();
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
        await expect(page.getByRole('searchbox', { name: 'Search pages, stocks, or help' })).toBeVisible();
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

// Resolve translucent control backgrounds against their ancestors before checking contrast.
async function contrastRatio(locator) {
    return locator.evaluate((element) => {
        const rgba = (value) => {
            const srgb = value.match(/^color\(srgb\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)(?:\s*\/\s*([\d.]+))?\)$/);
            if (srgb) return [Number(srgb[1]) * 255, Number(srgb[2]) * 255, Number(srgb[3]) * 255, srgb[4] === undefined ? 1 : Number(srgb[4])];
            return value.match(/[\d.]+/g).map(Number);
        };
        const blend = (foreground, background) => foreground.slice(0, 3).map(
            (channel, index) => channel * (foreground[3] ?? 1) + background[index] * (1 - (foreground[3] ?? 1)),
        );
        const ancestors = [];
        for (let node = element; node; node = node.parentElement) ancestors.unshift(node);
        const background = ancestors.reduce(
            (color, node) => blend(rgba(getComputedStyle(node).backgroundColor), color), [255, 255, 255],
        );
        const foreground = blend(rgba(getComputedStyle(element).color), background);
        const luminance = (color) => color.map((channel) => {
            const value = channel / 255;
            return value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
        }).reduce((sum, value, index) => sum + value * [0.2126, 0.7152, 0.0722][index], 0);
        const values = [luminance(foreground), luminance(background)].sort((a, b) => b - a);
        return (values[0] + 0.05) / (values[1] + 0.05);
    });
}

test('header theme keeps shell, text and controls readable across theme changes', async ({ page, isMobile }) => {
    test.setTimeout(60_000);
    await page.addInitScript(() => localStorage.setItem('lido-theme', 'system'));
    await page.emulateMedia({ colorScheme: 'light' });
    await installTosApiMocks(page);
    await page.route('**/api/portfolios', (route) => route.fulfill({ json: {
        data: [TEST_PORTFOLIO, { ...TEST_PORTFOLIO, id: 2, name: 'Second portfolio' }],
    } }));
    await page.goto('/recommendations');
    const header = page.locator('.lido-header');
    await expect(header.locator('.lido-portfolio-switcher')).toBeVisible();
    await expect(header.locator('.lido-execution-safety-state')).toBeVisible();

    for (const theme of ['light', 'dark']) {
        await page.emulateMedia({ colorScheme: theme });
        await expect(page.locator('html')).toHaveAttribute('data-theme', theme);
        await expect(header).toHaveCSS('background-color', theme === 'light'
            ? 'color(srgb 0.898353 0.901961 0.905569)'
            : 'rgb(0, 0, 0)');
        await expect(header).toHaveCSS('border-bottom-color', 'rgb(169, 169, 169)');
        // Theme attributes/background update before Bootstrap text-color transitions finish.
        // Wait for the same settled state that the contrast assertions below measure.
        await expect.poll(() => header.evaluate(element => element.getAnimations({ subtree: true })
            .filter(animation => 'transitionProperty' in animation && animation.playState === 'running').length)).toBe(0);
        const results = await new AxeBuilder({ page }).include('.lido-header').withRules(['color-contrast']).analyze();
        expect(results.violations).toEqual([]);

        // Axe checks text; explicitly cover icon-only buttons as well.
        const controls = header.locator('button, .lido-header-help');
        for (const control of await controls.all()) {
            if (await control.isVisible()) {
                await expect.poll(() => contrastRatio(control)).toBeGreaterThanOrEqual(3);
            }
        }
        for (const selector of ['.lido-profile-toggle', '.lido-header-help', '.lido-sidebar-toggle', '.lido-portfolio-switcher']) {
            const control = header.locator(selector);
            if (!await control.count()) continue;
            if (!isMobile) {
                await control.hover();
                await expect.poll(() => contrastRatio(control)).toBeGreaterThanOrEqual(4.5);
                await page.mouse.move(0, 0);
            }
            await page.keyboard.press('Tab');
            await control.focus();
            await expect.poll(() => contrastRatio(control)).toBeGreaterThanOrEqual(4.5);
            if (theme === 'light') {
                await expect.poll(() => contrastRatio(header.locator('.lido-profile-avatar'))).toBeGreaterThanOrEqual(4.5);
            }
            await control.evaluate((element) => element.blur());
        }
    }
});
