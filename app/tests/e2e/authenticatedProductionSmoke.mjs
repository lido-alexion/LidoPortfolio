import { allowProductionSmokeRequest, neutralizeProductionSmokeRequest, productionSmokeBlockReason } from './productionSmokePolicy.js';
import { readOnlyReviewHeading } from './productionSmokeSelectors.js';

let stage = 'configuration';
let browser;
const blockedReasons = new Set();
try {
    const baseURL = process.env.STOX_E2E_BASE_URL;
    const email = process.env.STOX_SMOKE_EMAIL;
    const password = process.env.STOX_SMOKE_PASSWORD;
    if (!baseURL || !email || !password || new URL(baseURL).protocol !== 'https:') throw new Error();
    stage = 'browser_launch';
    const { chromium } = await import('playwright');
    browser = await chromium.launch({ headless: true });
    // No Playwright Test reporter/instrumentation, tracing, HAR, video or screenshot.
    const context = await browser.newContext({ baseURL, serviceWorkers: 'block' });
    const page = await context.newPage();
    page.setDefaultTimeout(20_000);
    let login = true;
    let blocked = false;
    await page.route('**/*', async route => {
        const request = route.request();
        const method = request.method();
        const url = request.url();
        if (neutralizeProductionSmokeRequest(method, url, baseURL, request.postData())) {
            return route.fulfill({ status: 204 });
        }
        if (!allowProductionSmokeRequest(method, url, baseURL, { login })) {
            blocked = true;
            blockedReasons.add(productionSmokeBlockReason(method, url, baseURL));
            return route.abort();
        }
        return route.continue();
    });
    stage = 'login';
    const response = await page.goto('/login');
    if (!response?.ok()) throw new Error();
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(password);
    await page.getByRole('button', { name: 'Login', exact: true }).click();
    await page.getByRole('heading', { name: 'Login', exact: true }).waitFor({ state: 'hidden', timeout: 30_000 });
    login = false;
    stage = 'review';
    await page.goto('/review');
    await readOnlyReviewHeading(page).waitFor({ state: 'visible' });
    stage = 'request_safety';
    if (blocked) throw new Error();
    console.log('AUTHENTICATED_SMOKE_PASSED');
} catch {
    // Never print Playwright errors: fill arguments or network bodies may contain secrets.
    if (blockedReasons.size) console.log(`AUTHENTICATED_SMOKE_BLOCKED:${[...blockedReasons].sort().join(',')}`);
    console.log(`AUTHENTICATED_SMOKE_FAILED:${stage}`);
    process.exitCode = 1;
} finally {
    if (browser) await browser.close().catch(() => {});
}
