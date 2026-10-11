import { test, expect } from '@playwright/test';
import { journeyId, seedDeterministicJourney } from './journeyTestUtils.js';
import { installInvestorWorkflowApiMocks } from './investorWorkflowApiMocks.js';

for (const viewport of [{ name: 'mobile', width: 390, height: 844 }, { name: 'desktop', width: 1440, height: 900 }]) {
    test(`E2E-17 retries optional email preference saving and retains categories, delivery and quiet hours (${viewport.name})`, async ({ page }, testInfo) => {
        journeyId(testInfo, 'E2E-17'); await seedDeterministicJourney(page, `email-preferences-${viewport.name}`);
        await page.setViewportSize({ width: viewport.width, height: viewport.height }); await installInvestorWorkflowApiMocks(page);
        let prefs = { enabled: false, categories: { recommendation: false }, modes: { recommendation: 'immediate' }, catalogue: { recommendation: 'Recommendation updates' }, quiet_start: '22:00', quiet_end: '07:00', digest_time: '09:00', timezone: 'Asia/Kolkata' };
        let fail = true;
        await page.route('**/api/notification-settings', r => r.fulfill({ json: { data: [{ channel: 'in_app', enabled: true }], optional_email_preferences: prefs } }));
        await page.route('**/api/notification-settings/email-destinations', r => r.fulfill({ json: { data: [] } }));
        await page.route('**/api/notification-settings/optional-emails', r => {
            expect(r.request().method()).toBe('PUT');
            const body = r.request().postDataJSON(); expect(body).toMatchObject({ enabled: true, categories: { recommendation: true }, modes: { recommendation: 'digest' }, quiet_start: '21:00', digest_time: '08:00', timezone: 'Asia/Kolkata' });
            if (fail) { fail = false; return r.fulfill({ status: 503, json: { message: 'Synthetic preferences unavailable.' } }); }
            prefs = body; return r.fulfill({ json: { data: prefs } });
        });
        const rejected = [];
        await page.route('**/*', r => {
            const q = r.request(); const url = new URL(q.url());
            const allowed = ['GET', 'HEAD'].includes(q.method()) || (q.method() === 'PUT' && url.pathname === '/api/notification-settings/optional-emails') || (q.method() === 'POST' && url.pathname === '/api/logs/frontend');
            if (url.origin !== 'http://127.0.0.1:4177' || !allowed) { rejected.push(`${q.method()} ${url.pathname}`); return r.abort(); }
            return r.fallback();
        });
        await page.goto('/settings/notifications');
        await expect(page.getByRole('checkbox', { name: 'Enable optional emails', exact: true })).not.toBeChecked();
        await expect(page.getByText('Account and security messages remain enabled. Optional emails are off by default.', { exact: true })).toBeVisible();
        await page.getByRole('checkbox', { name: 'Enable optional emails', exact: true }).check();
        await page.getByRole('checkbox', { name: 'Recommendation updates', exact: true }).check();
        await page.getByLabel('Recommendation updates delivery', { exact: true }).selectOption('digest');
        await page.getByLabel('Quiet hours start', { exact: true }).fill('21:00'); await page.getByLabel('Daily digest time', { exact: true }).fill('08:00');
        await page.getByRole('button', { name: 'Save email preferences', exact: true }).click();
        await expect(page.getByRole('alert').filter({ hasText: 'Synthetic preferences unavailable.' })).toBeVisible(); await expect(page.getByLabel('Daily digest time')).toHaveValue('08:00');
        await page.getByRole('button', { name: 'Save email preferences', exact: true }).click(); await expect(page.getByRole('alert').filter({ hasText: 'Optional email preferences saved.' })).toBeVisible();
        await page.reload(); await expect(page.getByRole('checkbox', { name: 'Enable optional emails', exact: true })).toBeChecked(); await expect(page.getByRole('checkbox', { name: 'Recommendation updates', exact: true })).toBeChecked();
        await expect(page.getByLabel('Recommendation updates delivery')).toHaveValue('digest'); await expect(page.getByLabel('Quiet hours start')).toHaveValue('21:00'); await expect(page.getByLabel('Daily digest time')).toHaveValue('08:00'); expect(rejected).toEqual([]);
    });
}
