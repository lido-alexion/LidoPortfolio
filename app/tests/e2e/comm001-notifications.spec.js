import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { installInvestorWorkflowApiMocks } from './investorWorkflowApiMocks.js';

const settings = {
    enabled: false,
    categories: { recommendation: false, order_execution: false, connection: false, operations: false },
    modes: { recommendation: 'immediate', order_execution: 'immediate', connection: 'immediate', operations: 'immediate' },
    catalogue: { recommendation: 'Recommendation updates', order_execution: 'Order and execution updates', connection: 'Broker connection issues', operations: 'Scheduled job and data issues' },
    quiet_start: '22:00', quiet_end: '07:00', digest_time: '09:00', timezone: 'Asia/Kolkata',
};

async function installCommMocks(page) {
    await installInvestorWorkflowApiMocks(page);
    await page.route(/\/api\/notification-(settings|center)/, async (route) => {
        const { pathname, searchParams } = new URL(route.request().url());
        if (pathname.endsWith('/api/notification-settings') && route.request().method() === 'GET') {
            return route.fulfill({ json: { data: [{ channel: 'in_app', enabled: true }, { channel: 'email', enabled: false, health_status: 'unverified' }, { channel: 'telegram', enabled: false }, { channel: 'webhook', enabled: false }], optional_email_preferences: settings } });
        }
        if (pathname.endsWith('/api/notification-settings/email-destinations')) return route.fulfill({ json: { data: [] } });
        if (pathname.endsWith('/api/notification-center') && route.request().method() === 'GET') {
            return route.fulfill({ json: { data: [{ id: 17, attention_state: 'read', condition_state: 'na', type: 'recommendation.changed', severity: 'info', title: 'Recommendation changed', message: 'A product update is ready.', latest_activity_at: '2026-10-04T10:00:00Z', deliveries: [] }], meta: { unread_count: 0, active_critical_count: 0 } } });
        }
        if (pathname.endsWith('/api/notification-center/17/unread') && route.request().method() === 'POST') return route.fulfill({ json: { data: { attention_state: 'unread' } } });
        if (pathname.endsWith('/api/notification-center/mark-all-read') && route.request().method() === 'POST') return route.fulfill({ json: { data: { updated: 1 } } });
        if (/\/api\/notification-center\/\d+$/.test(pathname) && route.request().method() === 'GET') return route.fulfill({ json: { data: { id: 17, attention_state: 'read', condition_state: 'na', type: 'recommendation.changed', severity: 'info', title: 'Recommendation changed', message: 'A product update is ready.', timeline: [], deliveries: [] } } });
        return route.fallback();
    });
}

async function expectNoHorizontalOverflow(page) {
    const dimensions = await page.evaluate(() => ({ viewport: document.documentElement.clientWidth, content: document.documentElement.scrollWidth }));
    expect(dimensions.content).toBeLessThanOrEqual(dimensions.viewport);
}

test('optional email settings remain usable at mobile width with accessible controls', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await installCommMocks(page);
    await page.goto('/settings/notifications');
    await expect(page.getByRole('heading', { name: 'Optional product emails' })).toBeVisible();
    await expect(page.getByRole('checkbox', { name: 'Enable optional emails' })).not.toBeChecked();
    await expect(page.getByLabel('Recommendation updates delivery')).toBeVisible();
    await expect(page.getByLabel('Quiet hours start')).toBeVisible();
    await expect(page.getByLabel('Daily digest time')).toBeVisible();
    await expectNoHorizontalOverflow(page);
    const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).include('.lido-main').analyze();
    expect(results.violations.filter((item) => item.impact === 'critical')).toEqual([]);
});

test('notification history filters and read controls fit mobile screens', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await installCommMocks(page);
    await page.goto('/notification-history');
    await expect(page.getByRole('heading', { name: 'Notification Center' })).toBeVisible({ timeout: 15_000 });
    await expect(page.getByLabel('Search notifications')).toBeVisible();
    await expect(page.getByLabel('Delivery status')).toBeVisible();
    await page.getByLabel('Search notifications').fill('recommendation');
    await page.getByRole('button', { name: 'Apply filters' }).click();
    await expect(page.getByText('Recommendation changed')).toBeVisible();
    await page.getByRole('button', { name: 'Mark unread' }).click();
    await expectNoHorizontalOverflow(page);
});
