import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import path from 'node:path';

const password = 'Disposable-E2E-Password-418!';
const apiCall = (page, path, { method = 'GET', context = 'investor', body, token, profile, user } = {}) => page.evaluate(async (args) => {
    const headers = { Accept: 'application/json', 'X-Stox-Auth-Context': args.context };
    if (args.token) headers['X-CSRF-TOKEN'] = args.token;
    if (args.profile) headers['X-Profile-Id'] = String(args.profile);
    if (args.user) headers['X-Stox-Auth-User'] = String(args.user);
    if (args.body) headers['Content-Type'] = 'application/json';
    const response = await fetch(args.path, {
        method: args.method,
        credentials: 'same-origin',
        headers,
        body: args.body ? JSON.stringify(args.body) : undefined,
    });
    return { status: response.status, body: await response.json().catch(() => null) };
}, { path, method, context, body, token, profile, user });

async function csrf(page, context) {
    await page.evaluate((authContext) => fetch('/sanctum/csrf-cookie', {
        credentials: 'same-origin',
        headers: { 'X-Stox-Auth-Context': authContext },
    }), context);
    const result = await apiCall(page, '/api/auth/csrf-token', { context });
    expect(result.status).toBe(200);
    return result.body.token;
}

async function login(page, email, context = 'investor') {
    const token = await csrf(page, context);
    const result = await apiCall(page, '/api/auth/login', {
        method: 'POST', context, token,
        body: { email, password, remember: false },
    });
    expect(result.status, JSON.stringify(result.body)).toBe(200);
    return result.body.user;
}

test('real cookie sessions, independent role contexts, CSRF writes and portfolio ownership', async ({ browser }) => {
    const context = await browser.newContext();
    const tabA = await context.newPage();
    const tabB = await context.newPage();
    await Promise.all([tabA.goto('/'), tabB.goto('/')]);

    const investor = await login(tabA, 'investor-a@auth-e2e.example.test');
    const admin = await login(tabB, 'admin@auth-e2e.example.test', 'admin');
    expect(investor.is_admin).toBeFalsy();
    expect(admin.is_admin).toBeTruthy();

    // Both independently named cookies must coexist in the same browser jar.
    const cookies = await context.cookies();
    const investorSession = cookies.find((cookie) => cookie.name.endsWith('-session'));
    const adminSession = cookies.find((cookie) => cookie.name.endsWith('-session-admin'));
    expect(investorSession).toBeTruthy();
    expect(adminSession).toBeTruthy();
    expect(investorSession.httpOnly).toBeTruthy();
    expect(adminSession.httpOnly).toBeTruthy();
    expect((await apiCall(tabA, '/api/auth/me')).body.user.id).toBe(investor.id);
    expect((await apiCall(tabB, '/api/auth/me', { context: 'admin' })).body.user.id).toBe(admin.id);

    const investorList = await apiCall(tabA, '/api/portfolios', { user: investor.id });
    expect(investorList.status).toBe(200);
    const ownedProfile = investorList.body.data[0].id;
    const secondOwnedProfile = investorList.body.data[1].id;

    // Drive the real switcher in both same-identity tabs. Selection is
    // sessionStorage state, so choosing a portfolio in one tab stays local.
    await tabA.evaluate(() => sessionStorage.setItem('stox_auth_session_context', 'investor'));
    await tabB.evaluate(() => sessionStorage.setItem('stox_auth_session_context', 'investor'));
    await Promise.all([tabA.goto('/holdings'), tabB.goto('/holdings')]);
    const switcherA = tabA.locator('.lido-portfolio-switcher');
    const switcherB = tabB.locator('.lido-portfolio-switcher');
    await expect(switcherA).toBeVisible();
    await expect(switcherB).toBeVisible();
    await switcherA.click();
    await tabA.getByRole('button', { name: /Investor A Secondary/ }).click();
    await expect(switcherA).toContainText('Investor A Secondary');
    await expect(switcherB).toContainText('Investor A Default');
    await tabA.reload();
    await expect(switcherA).toContainText('Investor A Secondary');
    await tabB.evaluate(() => window.open(location.href, '_blank'));
    const duplicateTab = context.pages().at(-1);
    await expect(duplicateTab.locator('.lido-portfolio-switcher')).toContainText('Investor A Default');

    const csrfToken = await csrf(tabA, 'investor');
    const missingCsrf = await apiCall(tabA, `/api/portfolios/${ownedProfile}`, {
        method: 'PUT', profile: ownedProfile, user: investor.id,
        body: { name: 'Missing CSRF' },
    });
    expect([419, 403]).toContain(missingCsrf.status);
    const wrongContextCsrf = await apiCall(tabA, `/api/portfolios/${ownedProfile}`, {
        method: 'PUT', context: 'admin', token: csrfToken, profile: ownedProfile,
        user: investor.id, body: { name: 'Wrong context CSRF' },
    });
    expect([401, 403, 419]).toContain(wrongContextCsrf.status);

    // A second independent login is revocable from the active browser session.
    const revocationContext = await browser.newContext();
    const revocationPage = await revocationContext.newPage();
    await revocationPage.goto('/');
    await login(revocationPage, 'investor-a@auth-e2e.example.test');
    const sessions = await apiCall(tabA, '/api/auth/sessions', { user: investor.id });
    expect(sessions.status).toBe(200);
    const remoteSession = sessions.body.data.find((session) => !session.is_current);
    expect(remoteSession).toBeTruthy();
    const revokeToken = await csrf(tabA, 'investor');
    const revoked = await apiCall(tabA, `/api/auth/sessions/${remoteSession.id}`, {
        method: 'DELETE', token: revokeToken, user: investor.id,
    });
    expect(revoked.status).toBe(200);
    expect((await apiCall(revocationPage, '/api/portfolios')).status).toBe(401);
    await revocationContext.close();

    // Real browser requests share one investor cookie and CSRF session, while
    // independent tabs send overlapping writes scoped to their selected profile.
    const concurrentToken = await csrf(tabA, 'investor');
    const concurrentWrites = await Promise.all([
        apiCall(tabA, `/api/portfolios/${ownedProfile}`, {
            method: 'PUT', token: concurrentToken, profile: ownedProfile, user: investor.id,
            body: { name: 'E2E Concurrent Alpha' },
        }),
        apiCall(tabB, `/api/portfolios/${secondOwnedProfile}`, {
            method: 'PUT', token: concurrentToken, profile: secondOwnedProfile, user: investor.id,
            body: { name: 'E2E Concurrent Beta' },
        }),
    ]);
    expect(concurrentWrites.map((response) => response.status)).toEqual([200, 200]);
    await tabB.reload();
    expect((await apiCall(tabB, '/api/auth/me')).body.user.id).toBe(investor.id);

    const otherUser = await login(tabA, 'investor-b@auth-e2e.example.test');
    const otherList = await apiCall(tabA, '/api/portfolios', { user: otherUser.id });
    const foreignProfile = investorList.body.data[0].id;
    const denied = await apiCall(tabA, `/api/portfolios/${foreignProfile}`, { user: otherUser.id });
    expect(denied.status).toBe(404);

    // The shared investor cookie now belongs to investor B, so an explicitly
    // stale investor-A mutation from the other tab must be rejected.
    const staleToken = await csrf(tabB, 'investor');
    const staleWrite = await apiCall(tabB, '/api/portfolios', {
        method: 'POST', token: staleToken, profile: ownedProfile,
        user: investor.id, body: { name: 'Stale identity write' },
    });
    expect(staleWrite.status).toBe(401);

    expect((await apiCall(tabB, '/api/auth/me')).body.user.id).toBe(otherUser.id);
    const adminStillActive = await apiCall(tabB, '/api/auth/me', { context: 'admin' });
    expect(adminStillActive.body.user.id).toBe(admin.id);
    const adminNoInvestorRoute = await apiCall(tabB, '/api/portfolios', { context: 'admin', user: admin.id });
    expect([401, 403]).toContain(adminNoInvestorRoute.status);

    // Logout affects the selected investor context only; admin remains live.
    const investorLogoutToken = await csrf(tabA, 'investor');
    const logout = await apiCall(tabA, '/api/auth/logout', { method: 'POST', token: investorLogoutToken });
    expect(logout.status).toBe(200);
    expect((await apiCall(tabB, '/api/auth/me', { context: 'admin' })).body.user.id).toBe(admin.id);

    // Age the real disposable database session row so the next request proves
    // server-side expiry instead of merely deleting a browser cookie.
    const expiryContext = await browser.newContext();
    const expiryPage = await expiryContext.newPage();
    await expiryPage.goto('/');
    const expiryUser = await login(expiryPage, 'investor-b@auth-e2e.example.test');
    const expiringSession = await apiCall(expiryPage, '/api/auth/sessions', { user: expiryUser.id });
    expect(expiringSession.status).toBe(200);
    const currentSession = expiringSession.body.data.find((session) => session.is_current);
    expect(currentSession).toBeTruthy();
    execFileSync('php', [
        path.resolve('tests/e2e/authFixture.php'), 'expire-session', currentSession.id,
    ], { cwd: path.resolve('.') });
    expect((await apiCall(expiryPage, '/api/portfolios', { user: expiryUser.id })).status).toBe(401);
    expect((await apiCall(tabA, '/api/auth/me', { context: 'admin' })).body.user.id).toBe(admin.id);
    await expiryContext.close();
    await context.close();
});
