import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { journeyId, seedDeterministicJourney } from './journeyTestUtils.js';
import { installInvestorWorkflowApiMocks } from './investorWorkflowApiMocks.js';
import { TEST_USER } from '../js/tos/fixtures/tosApi.js';

const VIEWPORTS = [{ name: 'mobile', width: 390, height: 844 }, { name: 'desktop', width: 1440, height: 900 }];
const TOKEN = 'synthetic-invite-token';
async function prepare(page, viewport, writes, user = null) {
    await seedDeterministicJourney(page, `onboarding-${viewport.name}`);
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    await installInvestorWorkflowApiMocks(page, user ? { user } : { initiallyUnauthenticated: true });
    const rejected = [];
    const seal = () => page.route('**/*', route => {
        const req = route.request(); const url = new URL(req.url());
        const allowed = ['GET', 'HEAD'].includes(req.method()) || [['POST', '/api/logs/frontend'], ...writes].some(([method, path]) => req.method() === method && url.pathname === path);
        if (url.origin !== 'http://127.0.0.1:4177' || !allowed) { rejected.push(`${req.method()} ${url.pathname}`); return route.abort(); }
        return route.fallback();
    });
    return { rejected, seal };
}
for (const viewport of VIEWPORTS) {
    test.describe(`Account onboarding ${viewport.name}`, () => {
        test.use({ hasTouch: viewport.name === 'mobile' });
        test(`AUTH-06 corrects invitation validation and signs in only after accepted creation (${viewport.name})`, async ({ page }, testInfo) => {
            journeyId(testInfo, 'AUTH-06');
            const safety = await prepare(page, viewport, [['POST', '/api/invites/accept']]);
            let signedIn = false;
            let accepts = 0;
            await page.route('**/api/auth/me', r => r.fulfill({ status: signedIn ? 200 : 401, json: signedIn ? { user: TEST_USER } : { message: 'Unauthenticated.' } }));
            await page.route(`**/api/invites/${TOKEN}`, r => r.fulfill({ json: { data: { email: 'investor@example.test' } } }));
            await page.route('**/api/invites/accept', r => {
                const body = r.request().postDataJSON(); accepts++;
                expect(body.token).toBe(TOKEN); expect(body.name).toBe('Invited investor');
                if (body.password !== body.password_confirmation) return r.fulfill({ status: 422, json: { errors: { password: ['Confirmation does not match.'] } } });
                signedIn = true; return r.fulfill({ json: { data: { accepted: true } } });
            });
            await safety.seal(); await page.goto(`/invite/${TOKEN}`);
            await expect(page.getByLabel('Email', { exact: true })).toHaveValue('investor@example.test');
            await page.getByLabel('Your name', { exact: true }).fill('Invited investor');
            await page.getByLabel('Password', { exact: true }).fill('synthetic-password-1');
            await page.getByLabel('Confirm password', { exact: true }).fill('synthetic-password-2');
            const a11y = await new AxeBuilder({ page }).include('.login-card').withTags(['wcag2a', 'wcag2aa']).analyze(); expect(a11y.violations).toEqual([]);
            await page.getByRole('button', { name: 'Create account & sign in', exact: true }).click();
            await expect(page.getByRole('alert').filter({ hasText: 'Confirmation does not match.' })).toBeVisible();
            await expect(page.getByLabel('Password', { exact: true })).toHaveValue('synthetic-password-1');
            expect(signedIn).toBe(false);
            await page.getByLabel('Confirm password', { exact: true }).fill('synthetic-password-1');
            await page.getByRole('button', { name: 'Create account & sign in', exact: true }).click();
            await expect(page.getByRole('button', { name: /Profile menu for/ })).toBeVisible();
            expect(accepts).toBe(2); expect(safety.rejected).toEqual([]);
        });
        test(`AUTH-06 refuses an expired invitation without posting account creation (${viewport.name})`, async ({ page }, testInfo) => {
            journeyId(testInfo, 'AUTH-06');
            const safety = await prepare(page, viewport, []);
            await page.route(`**/api/invites/${TOKEN}`, r => r.fulfill({ status: 410, json: { message: 'Invitation expired. Request a new invitation.' } }));
            await safety.seal(); await page.goto(`/invite/${TOKEN}`);
            await expect(page.getByRole('alert')).toContainText('Invitation expired.');
            await expect(page.getByRole('button', { name: 'Create account & sign in', exact: true })).toHaveCount(0);
            await page.getByRole('link', { name: 'Back to sign in', exact: true }).click();
            await expect(page.getByRole('heading', { name: 'Login', exact: true })).toBeVisible();
            expect(safety.rejected).toEqual([]);
        });
        test(`AUTH-07 enforces human-verification UI, retries request failure, verifies the same request without login (${viewport.name})`, async ({ page }, testInfo) => {
            journeyId(testInfo, 'AUTH-07');
            const safety = await prepare(page, viewport, [['POST', '/api/auth/access-requests'], ['POST', '/api/auth/access-requests/verify/synthetic-verification-token']]);
            // A deterministic widget double, never a real provider challenge or qualification.
            await page.addInitScript(() => {
                window.turnstile = { render(container, options) { const b = document.createElement('button'); b.type = 'button'; b.textContent = 'Complete synthetic human verification'; b.onclick = () => options.callback('synthetic-human-check'); container.append(b); return 1; }, remove() {} };
            });
            let attempts = 0;
            await page.route('**/api/auth/access-requests/config', r => r.fulfill({ json: { data: { turnstile_site_key: 'synthetic-site-key' } } }));
            await page.route('**/api/auth/access-requests', r => {
                attempts++; expect(r.request().postDataJSON()).toEqual({ full_name: 'Requested investor', email: 'requester@example.test', captcha_token: 'synthetic-human-check' });
                if (attempts === 1) return r.fulfill({ status: 503, json: { message: 'Synthetic request unavailable.' } });
                return r.fulfill({ json: { message: 'If this email is eligible, an administrator can review the access request. If you already use StoX, please use the login page.' } });
            });
            await page.route('**/api/auth/access-requests/verify/synthetic-verification-token', r => r.fulfill({ json: { status: 'pending', message: 'Your email is verified. An administrator will review your request and contact you by email.' } }));
            await safety.seal(); await page.goto('/request-account');
            await page.getByLabel('Full name', { exact: true }).fill('Requested investor'); await page.getByLabel('Email', { exact: true }).fill('requester@example.test');
            await expect(page.getByRole('button', { name: 'Complete synthetic human verification' })).toBeVisible();
            await page.getByRole('button', { name: 'Submit access request', exact: true }).click();
            await expect(page.getByRole('alert').filter({ hasText: 'Please complete human verification' })).toBeVisible(); expect(attempts).toBe(0);
            await page.getByRole('button', { name: 'Complete synthetic human verification' }).click();
            await page.getByRole('button', { name: 'Submit access request', exact: true }).click();
            await expect(page.getByRole('alert').filter({ hasText: 'Synthetic request unavailable.' })).toBeVisible(); await expect(page.getByLabel('Full name')).toHaveValue('Requested investor');
            await page.getByRole('button', { name: 'Submit access request', exact: true }).click();
            await expect(page.getByRole('status').filter({ hasText: 'If this email is eligible' })).toBeVisible();
            await page.goto('/request-account/verify/synthetic-verification-token');
            await expect(page.getByRole('status').filter({ hasText: 'Your email is verified.' })).toBeVisible();
            await page.getByRole('link', { name: 'Back to login', exact: true }).click();
            await expect(page.getByRole('heading', { name: 'Login', exact: true })).toBeVisible(); expect(safety.rejected).toEqual([]);
        });
        test(`AUTH-08 preserves unverified approval confirmation and retries failed invitation creation (${viewport.name})`, async ({ page }, testInfo) => {
            journeyId(testInfo, 'AUTH-08');
            const safety = await prepare(page, viewport, [['POST', '/api/access-requests/51/create-invite']], { ...TEST_USER, is_admin: true });
            await page.addInitScript(() => { Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText: async text => { window.__SYNTHETIC_INVITE_COPY__ = text; } } }); });
            let request = { id: 51, full_name: 'Requested investor', email: 'requester@example.test', status: 'pending', verification_status: 'unverified', verification_email_delivery_status: 'failed' };
            let attempts = 0;
            for (const path of ['users', 'invites', 'password-reset-links', 'access-request-bans']) await page.route(`**/api/${path}`, r => r.fulfill({ json: { data: [] } }));
            await page.route('**/api/access-requests*', r => r.fulfill({ json: { data: request.status === 'pending' || new URL(r.request().url()).searchParams.get('pending_only') === 'false' ? [request] : [], pending_count: request.status === 'pending' ? 1 : 0 } }));
            await page.route('**/api/access-requests/51/create-invite', r => {
                attempts++; expect(r.request().postDataJSON()).toEqual({ confirm_unverified: true });
                if (attempts === 1) return r.fulfill({ status: 503, json: { message: 'Synthetic invitation creation unavailable.' } });
                request = { ...request, status: 'created', user_invite_id: 91, invite_email_delivery_status: 'failed' }; return r.fulfill({ json: { data: { invite: { id: 91 } } } });
            });
            let copyReads = 0;
            await page.route('**/api/access-requests/51/invitation-copy', r => { copyReads++; return r.fulfill({ json: { data: { invite_url: '/invite/synthetic-existing-token', invite_message: 'Requested investor: use /invite/synthetic-existing-token before its existing expiry.' } } }); });
            await safety.seal(); await page.goto('/settings/users');
            const row = page.getByRole('row').filter({ hasText: 'requester@example.test' }); await expect(row).toContainText('Unverified');
            page.once('dialog', async dialog => { expect(dialog.message()).toContain('Approve this request anyway?'); await dialog.dismiss(); });
            await row.getByRole('button', { name: 'Create invite', exact: true }).click(); expect(attempts).toBe(0);
            page.once('dialog', dialog => dialog.accept()); await row.getByRole('button', { name: 'Create invite', exact: true }).click();
            await expect(page.getByRole('alert').filter({ hasText: 'Synthetic invitation creation unavailable.' })).toBeVisible(); await expect(row).toContainText('Pending');
            page.once('dialog', dialog => dialog.accept()); await row.getByRole('button', { name: 'Create invite', exact: true }).click();
            await expect(row).toHaveCount(0); await page.getByRole('button', { name: 'Show all statuses', exact: true }).click();
            await expect(row).toContainText('Created'); await expect(row.getByRole('button', { name: 'Copy invitation email', exact: true })).toBeVisible();
            await row.getByRole('button', { name: 'Copy invitation email', exact: true }).click();
            await expect(page.getByRole('alert').filter({ hasText: 'Ready-to-send invitation email copied.' })).toBeVisible();
            expect(await page.evaluate(() => window.__SYNTHETIC_INVITE_COPY__)).toBe('Requested investor: use /invite/synthetic-existing-token before its existing expiry.');
            expect(copyReads).toBe(1);
            expect(request.invite_email_delivery_status).toBe('failed'); expect(attempts).toBe(2); expect(safety.rejected).toEqual([]);
        });
    });
}
