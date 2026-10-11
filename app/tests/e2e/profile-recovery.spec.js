import { test, expect } from '@playwright/test';
import { journeyId, seedDeterministicJourney } from './journeyTestUtils.js';
import { installInvestorWorkflowApiMocks } from './investorWorkflowApiMocks.js';
import { TEST_USER } from '../js/tos/fixtures/tosApi.js';

const VIEWPORTS = [{ name: 'mobile', width: 390, height: 844 }, { name: 'desktop', width: 1440, height: 900 }];
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aXZkAAAAASUVORK5CYII=', 'base64');
async function prepare(page, viewport) {
    await seedDeterministicJourney(page, `profile-recovery-${viewport.name}`);
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    const external = [];
    await page.route('**/*', route => {
        if (new URL(route.request().url()).origin !== 'http://127.0.0.1:4177') { external.push(route.request().url()); return route.abort(); }
        return route.fallback();
    });
    await installInvestorWorkflowApiMocks(page);
    let user = { ...TEST_USER, profile_photo_url: null };
    await page.route('**/api/auth/me', route => route.fulfill({ json: { user } }));
    const trading = [];
    page.on('request', request => {
        if (/^(POST|PUT|PATCH|DELETE)$/.test(request.method()) && /\/(orders|transactions|holdings)(?:\/|$)|\/execution\/|\/pipeline\//.test(new URL(request.url()).pathname)) trading.push(request.url());
    });
    return { external, trading, update: values => { user = { ...user, ...values }; } };
}
function assertSafe(evidence) { expect(evidence.external).toEqual([]); expect(evidence.trading).toEqual([]); }

for (const viewport of VIEWPORTS) {
    test(`AUTH-05 retries profile-name failure and retains the displayed identity after refresh (${viewport.name})`, async ({ page }, testInfo) => {
        journeyId(testInfo, 'AUTH-05');
        const evidence = await prepare(page, viewport);
        let fail = true;
        await page.route('**/api/profile', route => {
            expect(route.request().method()).toBe('PUT');
            if (fail) { fail = false; return route.fulfill({ status: 503, json: { message: 'Profile temporarily unavailable.' } }); }
            evidence.update({ name: route.request().postDataJSON().name });
            return route.fulfill({ json: { data: {} } });
        });
        await page.goto('/profile');
        await page.getByLabel('Display name', { exact: true }).fill('Journey investor');
        await page.getByRole('button', { name: 'Save name', exact: true }).click();
        await expect(page.getByText('Profile temporarily unavailable.', { exact: true })).toBeVisible();
        await expect(page.getByLabel('Display name', { exact: true })).toHaveValue('Journey investor');
        await page.getByRole('button', { name: 'Save name', exact: true }).click();
        await expect(page.getByRole('button', { name: 'Profile menu for Journey investor', exact: true })).toBeVisible();
        await page.reload();
        await expect(page.getByLabel('Display name', { exact: true })).toHaveValue('Journey investor');
        await expect(page.getByLabel('Username', { exact: true })).toBeDisabled();
        assertSafe(evidence);
    });

    test(`AUTH-05 corrects the current password and clears credential inputs after accepted change (${viewport.name})`, async ({ page }, testInfo) => {
        journeyId(testInfo, 'AUTH-05');
        const evidence = await prepare(page, viewport);
        let attempts = 0;
        await page.route('**/api/profile/password', route => {
            const body = route.request().postDataJSON();
            attempts++;
            if (body.current_password !== 'synthetic-correct-current') return route.fulfill({ status: 422, json: { errors: { current_password: ['Current password does not match.'] } } });
            expect(body.password).toBe('synthetic-new-password');
            expect(body.password_confirmation).toBe(body.password);
            return route.fulfill({ json: { data: {} } });
        });
        await page.goto('/profile');
        await page.getByLabel('Current password', { exact: true }).fill('synthetic-incorrect-current');
        await page.getByLabel('New password', { exact: true }).fill('synthetic-new-password');
        await page.getByLabel('Confirm new password', { exact: true }).fill('synthetic-new-password');
        await page.getByRole('button', { name: 'Update password', exact: true }).click();
        await expect(page.getByText('Current password does not match.', { exact: true })).toBeVisible();
        await expect(page.getByLabel('New password', { exact: true })).toHaveValue('synthetic-new-password');
        await page.getByLabel('Current password', { exact: true }).fill('synthetic-correct-current');
        await page.getByRole('button', { name: 'Update password', exact: true }).click();
        await expect(page.getByText('Password updated. Other devices have been signed out. This device remains signed in.', { exact: true })).toBeVisible();
        await expect(page.getByLabel('Current password', { exact: true })).toHaveValue('');
        await expect(page.getByLabel('New password', { exact: true })).toHaveValue('');
        await expect(page.getByLabel('Confirm new password', { exact: true })).toHaveValue('');
        expect(attempts).toBe(2);
        assertSafe(evidence);
    });

    test(`AUTH-05 rejects unsupported photos, uploads a local PNG, and removes it (${viewport.name})`, async ({ page }, testInfo) => {
        journeyId(testInfo, 'AUTH-05');
        const evidence = await prepare(page, viewport);
        let uploads = 0;
        let removes = 0;
        await page.route('**/api/profile/photo**', route => {
            const request = route.request();
            if (request.method() === 'GET') return route.fulfill({ contentType: 'image/png', body: PNG });
            if (request.method() === 'POST') {
                uploads++;
                expect(request.headers()['content-type']).toContain('multipart/form-data');
                evidence.update({ profile_photo_url: '/api/profile/photo?version=1' });
            } else if (request.method() === 'DELETE') { removes++; evidence.update({ profile_photo_url: null }); }
            else throw new Error('Unexpected profile photo method');
            return route.fulfill({ json: { data: {} } });
        });
        await page.goto('/profile');
        const upload = page.getByRole('button', { name: 'Upload profile picture', exact: true });
        let chooser = page.waitForEvent('filechooser');
        await upload.click();
        await (await chooser).setFiles({ name: 'unsupported.txt', mimeType: 'text/plain', buffer: Buffer.from('synthetic file') });
        await expect(page.getByText('Only JPEG and PNG images are supported.', { exact: true })).toBeVisible();
        expect(uploads).toBe(0);
        chooser = page.waitForEvent('filechooser');
        await upload.click();
        await (await chooser).setFiles({ name: 'avatar.png', mimeType: 'image/png', buffer: PNG });
        await expect(page.getByRole('button', { name: 'Change profile picture', exact: true })).toBeVisible();
        await page.reload();
        await expect(page.getByRole('button', { name: 'Change profile picture', exact: true })).toBeVisible();
        await page.getByRole('button', { name: 'Remove photo', exact: true }).click();
        await expect(upload).toBeVisible();
        expect(uploads).toBe(1);
        expect(removes).toBe(1);
        assertSafe(evidence);
    });
}
