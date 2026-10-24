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

for (const width of [390, 1280]) {
test(`notification bell deep link survives read failure and reload, with a retry for detail failure (${width}px)`, async ({ page }) => {
    await page.setViewportSize({ width, height: 844 });
    await installCommMocks(page);
    let failDetail = false;
    let detailRequests = 0;
    const notification = { id: 17, attention_state: 'unread', condition_state: 'na', type: 'recommendation.changed', severity: 'info', title: 'Recommendation changed', message: 'A product update is ready.', occurrence_count: 1, timeline: [], deliveries: [] };
    // These fixtures exercise the deployed UI contract without any real account writes.
    await page.route(/\/api\/notification-center(?:\/17(?:\/read)?)?(?:\?|$)/, async (route) => {
        const { pathname } = new URL(route.request().url());
        if (pathname.endsWith('/notification-center/17/read') && route.request().method() === 'POST') {
            return route.fulfill({ status: 503, json: { message: 'Synthetic read-update failure' } });
        }
        if (pathname.endsWith('/notification-center/17') && route.request().method() === 'GET') {
            detailRequests += 1;
            return failDetail
                ? route.fulfill({ status: 503, json: { message: 'Synthetic detail failure' } })
                : route.fulfill({ json: { data: notification } });
        }
        if (pathname.endsWith('/notification-center') && route.request().method() === 'GET') {
            return route.fulfill({ json: { data: [notification], meta: { unread_count: 1, active_critical_count: 0 } } });
        }
        return route.fallback();
    });

    await page.goto('/notification-history');
    await page.getByRole('button', { name: '1 unread notifications', exact: true }).click();
    const pane = page.locator('.lido-notification-menu');
    await expect(pane).toBeVisible();
    const bounds = await pane.boundingBox();
    const viewportWidth = await page.evaluate(() => document.documentElement.clientWidth);
    expect(bounds.x).toBeGreaterThanOrEqual(0);
    expect(bounds.x + bounds.width).toBeLessThanOrEqual(viewportWidth);
    await pane.getByRole('link', { name: /Recommendation changed/ }).click();
    await expect(page).toHaveURL(/notification-history\?notification=17$/);
    const detail = page.getByRole('region', { name: 'Notification detail', exact: true });
    await expect(detail.getByText('A product update is ready.')).toBeVisible();
    await expect(detail.getByText('Notification loaded, but its read status could not be updated.')).toBeVisible();
    await page.reload();
    await expect(detail.getByText('A product update is ready.')).toBeVisible();
    await expectNoHorizontalOverflow(page);

    failDetail = true;
    await page.reload();
    await expect(page.getByText('Notification details unavailable')).toBeVisible();
    await expect(detail).toHaveCount(0);
    failDetail = false;
    const beforeRetry = detailRequests;
    await page.getByRole('button', { name: 'Retry notification', exact: true }).click();
    await expect(detail.getByText('A product update is ready.')).toBeVisible();
    expect(detailRequests).toBeGreaterThan(beforeRetry);
    await page.reload();
    await expect(detail.getByText('A product update is ready.')).toBeVisible();
});
}

test('notification channels follow save, test, then enable in a real browser', async ({ page }) => {
    await installInvestorWorkflowApiMocks(page);
    let telegram = { channel: 'telegram', configured: false, enabled: false, health_status: 'unverified', verified_at: null, last_test_status: null, bot_token_configured: false, chat_id: null };
    const email = { channel: 'email', configured: false, enabled: false, health_status: 'unverified', verified_at: null, account_email: 'person@example.test', account_email_verified: false };
    const webhook = { channel: 'webhook', configured: false, enabled: false, health_status: 'unverified', verified_at: null, url: null, signing_secret_configured: false };
    let providerTests = 0;
    let writes = [];
    await page.route(/\/api\/notification-settings/, async (route) => {
        const { pathname } = new URL(route.request().url());
        if (pathname.endsWith('/notification-settings/email-destinations')) {
            return route.fulfill({ json: { data: [{ id: 1, email: 'person@example.test', is_account_email: true, verified_at: null }] } });
        }
        if (pathname.endsWith('/notification-settings') && route.request().method() === 'GET') {
            return route.fulfill({ json: { data: [{ channel: 'in_app', enabled: true }, telegram, email, webhook], optional_email_preferences: settings } });
        }
        if (pathname.endsWith('/notification-settings/telegram') && route.request().method() === 'PUT') {
            const payload = route.request().postDataJSON();
            writes.push(payload);
            telegram = { ...telegram, configured: true, enabled: !!payload.enabled, chat_id: payload.chat_id, bot_token_configured: true, verified_at: payload.enabled ? telegram.verified_at : null, health_status: payload.enabled ? 'healthy' : 'unverified' };
            return route.fulfill({ json: { data: telegram } });
        }
        if (pathname.endsWith('/notification-settings/telegram/test') && route.request().method() === 'POST') {
            providerTests += 1;
            telegram = { ...telegram, verified_at: '2026-10-16T00:00:00Z', last_test_status: 'succeeded', health_status: 'healthy' };
            return route.fulfill({ json: { data: { successful: true, channel: 'telegram', message: 'Channel verified successfully.' } } });
        }
        return route.fallback();
    });

    await page.goto('/settings/notifications');
    const card = page.getByRole('region', { name: 'telegram' });
    await expect(card.getByRole('button', { name: 'Test channel' })).toHaveCount(0);
    const enabled = card.getByRole('checkbox', { name: 'Enabled' });
    await expect(enabled).toBeDisabled();
    await expect(card.getByText('Save these settings and test the channel before enabling delivery.')).toBeVisible();
    const help = card.locator('span[tabindex="0"]');
    await help.focus();
    await expect(help).toHaveAttribute('title', 'Save these settings and test the channel before enabling delivery.');

    await card.getByLabel('Bot token').fill('synthetic-test-token');
    await card.getByLabel('Chat ID').fill('synthetic-chat-42');
    await expect(card.getByRole('button', { name: 'Test channel' })).toHaveCount(0);
    await card.getByRole('button', { name: 'Save' }).click();
    await expect(card.getByRole('button', { name: 'Test channel' })).toBeVisible();
    await expect(card.getByLabel('Bot token')).toHaveValue('');
    await page.reload();
    const reloadedCard = page.getByRole('region', { name: 'telegram' });
    await expect(reloadedCard.getByRole('button', { name: 'Test channel' })).toBeVisible();
    await expect(reloadedCard.getByLabel('Bot token')).toHaveValue('');
    await expect(reloadedCard.getByLabel('Chat ID')).toHaveValue('synthetic-chat-42');
    await reloadedCard.getByRole('button', { name: 'Test channel' }).click();
    await expect.poll(() => providerTests).toBe(1);
    await expect(reloadedCard.getByLabel('Enabled')).toBeEnabled();
    await expect(reloadedCard.getByText('Test passed · ready to enable')).toBeVisible();
    await reloadedCard.getByLabel('Enabled').check();
    await reloadedCard.getByRole('button', { name: 'Save' }).click();
    await expect.poll(() => writes.length).toBe(2);
    expect(writes[0]).toMatchObject({ bot_token: 'synthetic-test-token', chat_id: 'synthetic-chat-42', enabled: false });
    expect(writes[1]).toMatchObject({ chat_id: 'synthetic-chat-42', enabled: true });
    expect(writes[1]).not.toHaveProperty('bot_token');
    expect(providerTests).toBe(1);
});
