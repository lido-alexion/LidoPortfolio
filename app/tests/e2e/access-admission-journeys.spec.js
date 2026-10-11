import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { journeyId, seedDeterministicJourney } from './journeyTestUtils.js';
import { installInvestorWorkflowApiMocks } from './investorWorkflowApiMocks.js';
import { TEST_USER } from '../js/tos/fixtures/tosApi.js';

const VIEWPORTS = [{ name: 'mobile', width: 390, height: 844 }, { name: 'desktop', width: 1440, height: 900 }];
const request = id => ({ id, full_name: `Synthetic applicant ${id}`, email: `applicant-${id}@example.test`, status: 'pending', verification_status: 'unverified', verification_email_delivery_status: 'failed' });
const unavailable = route => route.fulfill({ status: 503, json: { message: 'Synthetic admission unavailable.' } });
const region = page => page.getByRole('region', { name: 'Account access requests', exact: true });
const rowFor = (page, id) => region(page).getByRole('row').filter({ hasText: `applicant-${id}@example.test` });
async function prepare(page, viewport, allowed = [], admin = true) {
    await seedDeterministicJourney(page, `admission-${viewport.name}`);
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    await installInvestorWorkflowApiMocks(page, { user: { ...TEST_USER, is_admin: admin } });
    const writes = [], blocked = [];
    for (const path of ['users', 'invites', 'password-reset-links', 'access-request-bans']) await page.route(`**/api/${path}`, route => route.fulfill({ json: { data: [] } }));
    return { writes, blocked, seal: () => page.route('**/*', route => {
        const req = route.request(), url = new URL(req.url());
        const write = !['GET', 'HEAD'].includes(req.method());
        if (url.origin !== 'http://127.0.0.1:4177' || write && url.pathname !== '/api/logs/frontend' && !allowed.some(([method, path]) => method === req.method() && path === url.pathname)) { blocked.push(`${req.method()} ${url.pathname}`); return route.abort(); }
        if (write && url.pathname !== '/api/logs/frontend') writes.push({ path: url.pathname, body: req.postData() ? req.postDataJSON() : null });
        return route.fallback();
    }) };
}
async function lists(page, rows, bans = () => []) {
    await page.route('**/api/access-requests?*', route => route.fulfill({ json: { data: new URL(route.request().url()).searchParams.get('pending_only') === 'false' ? rows() : rows().filter(row => row.status === 'pending'), pending_count: rows().filter(row => row.status === 'pending').length } }));
    await page.route('**/api/access-request-bans', route => route.fulfill({ json: { data: bans() } }));
}
async function promptAction(page, target, answer, defaultValue) {
    const complete = new Promise((resolve, reject) => page.once('dialog', async dialog => {
        try {
            expect(dialog.message()).toBe('Optional internal reason (not shown to the applicant):');
            if (defaultValue !== undefined) expect(dialog.defaultValue()).toBe(defaultValue);
            if (answer === null) await dialog.dismiss(); else await dialog.accept(answer);
            resolve();
        } catch (error) { reject(error); }
    }));
    await target.click(); await complete;
}

for (const viewport of VIEWPORTS) test.describe(`Admission prerequisite ${viewport.name}`, () => {
    test.use({ hasTouch: viewport.name === 'mobile' });
    test(`AUTH-08 distinguishes unavailable admission reads and retries the selected review (${viewport.name})`, async ({ page }, testInfo) => {
        journeyId(testInfo, 'AUTH-08'); const safety = await prepare(page, viewport); const rows = [request(51)]; let failed = true, detailFailed = true;
        await lists(page, () => rows);
        await page.route('**/api/access-requests?*', route => failed ? unavailable(route) : route.fulfill({ json: { data: rows, pending_count: 1 } }));
        await page.route('**/api/access-requests/51', route => detailFailed ? unavailable(route) : route.fulfill({ json: { request: rows[0], history: [{ ...rows[0], created_at: '2026-10-01T00:00:00Z' }], audit_events: [{ id: 1, event_type: 'request_received', created_at: '2026-10-01T00:00:00Z' }] } }));
        await safety.seal(); await page.goto('/settings/users');
        await expect(region(page).getByRole('alert')).toContainText('Access requests could not be loaded'); await expect(region(page).getByText('No access requests in this view.')).toHaveCount(0);
        failed = false; await region(page).getByRole('button', { name: 'Retry access requests' }).click(); await expect(rowFor(page, 51)).toContainText('Unverified');
        failed = true; await region(page).getByRole('button', { name: 'Show all statuses' }).click(); await expect(region(page).getByRole('alert')).toContainText('Access requests could not be loaded'); await expect(rowFor(page, 51)).toBeVisible(); await expect(rowFor(page, 51).getByRole('button', { name: 'Create invite', exact: true })).toBeDisabled();
        failed = false; await region(page).getByRole('button', { name: 'Retry access requests' }).click(); await rowFor(page, 51).getByRole('button', { name: 'Review', exact: true }).click();
        await expect(region(page).getByRole('alert')).toContainText('Request details could not be loaded'); await expect(region(page).getByRole('heading', { name: 'Audit trail' })).toHaveCount(0);
        detailFailed = false; await region(page).getByRole('button', { name: 'Retry request details' }).click(); await expect(region(page).getByText(/request_received/)).toBeVisible();
        const a11y = await new AxeBuilder({ page }).include('.lido-access-requests-admin').withTags(['wcag2a', 'wcag2aa']).analyze(); expect(a11y.violations).toEqual([]);
        await region(page).getByRole('button', { name: 'Close', exact: true }).click(); await expect(region(page).getByRole('heading', { name: /Review —/ })).toHaveCount(0); expect(safety.writes).toEqual([]); expect(safety.blocked).toEqual([]);
    });
    for (const action of ['ignore', 'reject']) test(`AUTH-08 retries verification and explicit ${action} without another invitation (${viewport.name})`, async ({ page }, testInfo) => {
        journeyId(testInfo, 'AUTH-08'); const allowed = [['POST', '/api/access-requests/51/resend-verification'], ['POST', `/api/access-requests/51/${action}`], ['POST', '/api/access-request-bans/8/clear']]; const safety = await prepare(page, viewport, allowed);
        const rows = [request(51), request(52)]; const sibling = JSON.stringify(rows[1]); let bans = [], resends = 0, decisions = 0, clears = 0; await lists(page, () => rows, () => bans);
        await page.route('**/api/access-requests/51/resend-verification', route => { resends++; if (resends === 1) return unavailable(route); rows[0].verification_email_delivery_status = 'queued'; return route.fulfill({ json: { data: {} } }); });
        await page.route(`**/api/access-requests/51/${action}`, route => { decisions++; expect(route.request().postDataJSON()).toEqual({ reason: 'Private review' }); if (decisions === 1) return unavailable(route); rows[0].status = action === 'ignore' ? 'ignored' : 'rejected'; if (action === 'reject') bans = [{ id: 8, email: rows[0].email, active: true }]; return route.fulfill({ json: { data: {} } }); });
        await page.route('**/api/access-request-bans/8/clear', route => { clears++; if (clears === 1) return unavailable(route); bans = []; return route.fulfill({ json: { data: {} } }); });
        await safety.seal(); await page.goto('/settings/users'); const target = rowFor(page, 51); await expect(target).toContainText('Pending');
        await target.getByRole('button', { name: 'Resend verification', exact: true }).click(); await expect(region(page).getByRole('alert')).toContainText('Synthetic admission unavailable'); expect(rows[0].verification_email_delivery_status).toBe('failed');
        await target.getByRole('button', { name: 'Resend verification', exact: true }).click(); await expect(target).toContainText('queued'); await expect(target).toContainText('Unverified'); expect(rows).toHaveLength(2);
        const label = action === 'ignore' ? 'Ignore' : 'Reject'; await promptAction(page, target.getByRole('button', { name: label, exact: true }), null, ''); expect(decisions).toBe(0);
        await promptAction(page, target.getByRole('button', { name: label, exact: true }), '  Private review  ', ''); await expect(region(page).getByRole('alert')).toContainText('Synthetic admission unavailable'); await expect(target).toContainText('Pending');
        await promptAction(page, target.getByRole('button', { name: label, exact: true }), 'Private review', 'Private review'); await expect(target).toHaveCount(0);
        await region(page).getByRole('button', { name: 'Show all statuses' }).click(); await expect(target).toContainText(action === 'ignore' ? 'Ignored' : 'Rejected'); await expect(target.getByRole('button', { name: 'Create invite', exact: true })).toHaveCount(0);
        if (action === 'reject') { await region(page).getByRole('button', { name: 'Clear ban', exact: true }).click(); await expect(region(page).getByRole('alert')).toContainText('Synthetic admission unavailable'); expect(bans).toHaveLength(1); await region(page).getByRole('button', { name: 'Clear ban', exact: true }).click(); await expect(region(page).getByRole('heading', { name: 'Active request bans' })).toHaveCount(0); await expect(target).toContainText('Rejected'); }
        expect(JSON.stringify(rows[1])).toBe(sibling); expect(resends).toBe(2); expect(decisions).toBe(2); expect(safety.writes).toHaveLength(action === 'reject' ? 6 : 4); expect(safety.blocked).toEqual([]);
    });
    test(`AUTH-08 retains direct invitation failures and reuses its secure copy and email retry (${viewport.name})`, async ({ page }, testInfo) => {
        journeyId(testInfo, 'AUTH-08'); const safety = await prepare(page, viewport, [['POST', '/api/invites'], ['POST', '/api/invites/91/retry-email']]); let invites = [], creates = 0, retries = 0, copies = 0, parentUnavailable = true; await lists(page, () => []);
        const invitation = { id: 91, email: 'direct-applicant@example.test', status: 'pending', invite_url: '/invite/synthetic-admission-token', invite_message: 'SYNTHETIC_INVITATION_ONLY', expires_at: '2026-10-14T00:00:00Z', created_at: '2026-10-11T00:00:00Z', email_delivery_status: 'failed' };
        await page.addInitScript(() => Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText: async text => { window.__SYNTHETIC_ADMISSION_COPY__ = text; } } }));
        await page.route('**/api/invites', route => { if (route.request().method() === 'GET') return parentUnavailable ? unavailable(route) : route.fulfill({ json: { data: invites } }); creates++; expect(route.request().postDataJSON()).toEqual({ email: invitation.email }); if (creates === 1) return unavailable(route); invites = [{ ...invitation, invite_url: null, invite_message: null }]; return route.fulfill({ status: 201, json: { data: invitation } }); });
        await page.route('**/api/invites/91/copy', route => { copies++; return route.fulfill({ json: { data: { invite_url: invitation.invite_url, invite_message: invitation.invite_message } } }); });
        await page.route('**/api/invites/91/retry-email', route => { retries++; if (retries === 1) return unavailable(route); invites[0].email_delivery_status = 'queued'; return route.fulfill({ json: { data: {} } }); });
        await safety.seal(); await page.goto('/settings/users'); await expect(page.getByRole('alert').filter({ hasText: 'Synthetic admission unavailable.' })).toBeVisible(); await expect(page.getByText('No invites yet.', { exact: true })).toHaveCount(0); const form = page.getByLabel('Email address', { exact: true }).locator('..').locator('..'); await expect(form.getByRole('button', { name: 'Create invite', exact: true })).toBeDisabled(); expect(safety.writes).toEqual([]); parentUnavailable = false; await page.getByRole('button', { name: 'Retry user management' }).click(); await expect(form.getByRole('button', { name: 'Create invite', exact: true })).toBeEnabled(); await page.getByLabel('Email address', { exact: true }).fill(invitation.email); await form.getByRole('button', { name: 'Create invite', exact: true }).click(); await expect(page.getByRole('alert').filter({ hasText: 'Synthetic admission unavailable.' })).toBeVisible(); await expect(page.getByLabel('Email address', { exact: true })).toHaveValue(invitation.email); expect(invites).toEqual([]);
        await form.getByRole('button', { name: 'Create invite', exact: true }).click(); await expect(page.getByText('Active invitation URL', { exact: true })).toBeVisible(); await expect(page.getByLabel('Email address', { exact: true })).toHaveValue(''); await page.reload(); const target = page.getByRole('row').filter({ hasText: invitation.email }); await expect(target).toContainText('Pending');
        await target.getByRole('button', { name: 'Copy invitation email', exact: true }).click(); await expect.poll(() => page.evaluate(() => window.__SYNTHETIC_ADMISSION_COPY__)).toBe(invitation.invite_message);
        await target.getByRole('button', { name: 'Retry email', exact: true }).click(); await expect(page.getByRole('alert').filter({ hasText: 'Synthetic admission unavailable.' })).toBeVisible(); expect(invites[0].email_delivery_status).toBe('failed'); await target.getByRole('button', { name: 'Retry email', exact: true }).click(); await expect(page.getByRole('alert').filter({ hasText: 'Invitation email queued for retry.' })).toBeVisible(); await page.reload(); await expect(target).toContainText('queued');
        expect(invites).toHaveLength(1); expect(invites[0].expires_at).toBe(invitation.expires_at); expect(creates).toBe(2); expect(retries).toBe(2); expect(copies).toBe(1); expect(safety.writes).toHaveLength(4); expect(safety.blocked).toEqual([]);
    });
    test(`AUTH-04 issues the intended administrator reset link after explicit recovery (${viewport.name})`, async ({ page }, testInfo) => {
        journeyId(testInfo, 'AUTH-04'); const safety = await prepare(page, viewport, [['POST', '/api/password-reset-links']]); let links = [], creates = 0; await lists(page, () => []);
        const owner = { id: 42, name: 'Synthetic reset owner', email: 'reset-owner@example.test', is_admin: false };
        const link = { id: 81, user_id: owner.id, user_name: owner.name, email: owner.email, status: 'pending', reset_url: '/reset-password/synthetic-admin-reset-token', reset_message: 'SYNTHETIC_RESET_CAPABILITY_ONLY', expires_at: '2026-10-14T00:00:00Z', created_at: '2026-10-11T00:00:00Z' };
        await page.addInitScript(() => Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText: async text => { window.__SYNTHETIC_ADMISSION_COPY__ = text; } } }));
        await page.route('**/api/users', route => route.fulfill({ json: { data: [owner] } }));
        await page.route('**/api/password-reset-links', route => { if (route.request().method() === 'GET') return route.fulfill({ json: { data: links } }); creates++; expect(route.request().postDataJSON()).toEqual({ user_id: owner.id }); if (creates === 1) return unavailable(route); links = [link]; return route.fulfill({ status: 201, json: { data: link } }); });
        await safety.seal(); await page.goto('/settings/users'); const target = page.getByLabel('User', { exact: true }); await target.selectOption(String(owner.id)); const form = target.locator('..').locator('..'); await expect(form.getByRole('button', { name: 'Generate reset link', exact: true })).toBeEnabled();
        await form.getByRole('button', { name: 'Generate reset link', exact: true }).click(); await expect(page.getByRole('alert').filter({ hasText: 'Synthetic admission unavailable.' })).toBeVisible(); await expect(target).toHaveValue(String(owner.id)); expect(links).toEqual([]);
        await form.getByRole('button', { name: 'Generate reset link', exact: true }).click(); const issued = page.getByRole('row').filter({ hasText: owner.email }).filter({ hasText: 'Pending' }); await expect(issued).toBeVisible(); await expect(target).toHaveValue('');
        await issued.getByRole('button', { name: 'Copy link', exact: true }).click(); await expect.poll(() => page.evaluate(() => window.__SYNTHETIC_ADMISSION_COPY__)).toBe(link.reset_url); await issued.getByRole('button', { name: 'Copy message', exact: true }).click(); await expect.poll(() => page.evaluate(() => window.__SYNTHETIC_ADMISSION_COPY__)).toBe(link.reset_message);
        expect(creates).toBe(2); expect(links).toHaveLength(1); expect(links[0].user_id).toBe(owner.id); expect(safety.writes).toHaveLength(2); expect(safety.blocked).toEqual([]);
    });
    test(`AUTH-08 withholds admission administration from an Investor (${viewport.name})`, async ({ page }, testInfo) => {
        journeyId(testInfo, 'AUTH-08'); const safety = await prepare(page, viewport, [], false); let reads = 0; await page.route('**/api/access-requests*', route => { reads++; return route.fulfill({ status: 403, json: { message: 'Forbidden' } }); }); await safety.seal(); await page.goto('/settings/users'); await expect(page).toHaveURL('/settings/portfolio'); await expect(region(page)).toHaveCount(0); expect(reads).toBe(0); expect(safety.writes).toEqual([]); expect(safety.blocked).toEqual([]);
    });
});
