import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { journeyId, seedDeterministicJourney } from './journeyTestUtils.js';
import { installInvestorWorkflowApiMocks } from './investorWorkflowApiMocks.js';
import { TEST_USER } from '../js/tos/fixtures/tosApi.js';

const VIEWPORTS = [{ name: 'mobile', width: 390, height: 844 }, { name: 'desktop', width: 1440, height: 900 }];
const TOKEN = 'deterministic-reset-token';
async function prepare(page, viewport) {
    await seedDeterministicJourney(page, `reset-recovery-${viewport.name}`);
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    const external = [];
    await page.route('**/*', route => {
        if (new URL(route.request().url()).origin !== 'http://127.0.0.1:4177') {
            external.push(route.request().url());
            return route.abort();
        }
        return route.fallback();
    });
    await installInvestorWorkflowApiMocks(page, { initiallyUnauthenticated: true });
    const trading = [];
    page.on('request', request => {
        if (/^(POST|PUT|PATCH|DELETE)$/.test(request.method()) && /\/(orders|transactions|holdings)(?:\/|$)|\/execution\/|\/pipeline\//.test(new URL(request.url()).pathname)) trading.push(request.url());
    });
    return { external, trading };
}
for (const viewport of VIEWPORTS) {
    test(`AUTH-04 corrects rejected reset credentials and returns to the account (${viewport.name})`, async ({ page }, testInfo) => {
        journeyId(testInfo, 'AUTH-04');
        const evidence = await prepare(page, viewport);
        let signedIn = false;
        let attempts = 0;
        await page.route('**/api/auth/me', route => route.fulfill({ status: signedIn ? 200 : 401, json: signedIn ? { user: TEST_USER } : { message: 'Unauthenticated.' } }));
        await page.route(`**/api/reset-password/${TOKEN}`, route => route.fulfill({ json: { data: { name: 'Test investor', email: 'investor@example.test' } } }));
        await page.route('**/api/reset-password/accept', route => {
            const body = route.request().postDataJSON();
            expect(body.token).toBe(TOKEN);
            attempts++;
            if (body.password !== body.password_confirmation) return route.fulfill({ status: 422, json: { errors: { password: ['The password confirmation does not match.'] } } });
            signedIn = true;
            return route.fulfill({ json: { data: { accepted: true } } });
        });
        await page.goto(`/reset-password/${TOKEN}`);
        await expect(page.getByLabel('Account', { exact: true })).toHaveValue('Test investor (investor@example.test)');
        const accessibility = await new AxeBuilder({ page }).include('.login-card').withTags(['wcag2a', 'wcag2aa']).analyze();
        expect(accessibility.violations).toEqual([]);
        await page.getByLabel('New password', { exact: true }).fill('synthetic-password-1');
        await page.getByLabel('Confirm new password', { exact: true }).fill('synthetic-password-2');
        await page.getByRole('button', { name: 'Update password & sign in' }).click();
        await expect(page.getByRole('alert').filter({ hasText: 'The password confirmation does not match.' })).toBeVisible();
        await expect(page.getByLabel('New password', { exact: true })).toHaveValue('synthetic-password-1');
        await expect(page.getByRole('button', { name: 'Update password & sign in' })).toBeEnabled();
        await page.getByLabel('Confirm new password', { exact: true }).fill('synthetic-password-1');
        await page.getByRole('button', { name: 'Update password & sign in' }).click();
        await expect(page).toHaveURL(/\/$/);
        await expect(page.getByRole('button', { name: /Profile menu for/ })).toBeVisible();
        expect(attempts).toBe(2);
        expect(evidence.external).toEqual([]);
        expect(evidence.trading).toEqual([]);
    });

    test(`AUTH-04 refuses an expired reset link and offers the sign-in return (${viewport.name})`, async ({ page }, testInfo) => {
        journeyId(testInfo, 'AUTH-04');
        const evidence = await prepare(page, viewport);
        let accepts = 0;
        await page.route(`**/api/reset-password/${TOKEN}`, route => route.fulfill({ status: 410, json: { message: 'This reset link has expired. Contact your administrator for a new link.' } }));
        await page.route('**/api/reset-password/accept', route => { accepts++; return route.fulfill({ status: 410, json: { message: 'Expired.' } }); });
        await page.goto(`/reset-password/${TOKEN}`);
        await expect(page.getByRole('alert')).toContainText('This reset link has expired.');
        await expect(page.getByRole('button', { name: 'Update password & sign in' })).toHaveCount(0);
        await page.getByRole('link', { name: 'Back to sign in' }).click();
        await expect(page.getByRole('heading', { name: 'Login', exact: true })).toBeVisible();
        expect(accepts).toBe(0);
        expect(evidence.external).toEqual([]);
        expect(evidence.trading).toEqual([]);
    });
}
