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
    stage = 'combo_chart';
    const preferenceResponsePromise = page.waitForResponse(response => response.request().method() === 'GET' && new URL(response.url()).pathname === '/api/v1/stock-details/combo-chart-preference');
    await page.goto('/watchlist/TCS');
    const preferenceResponse = await preferenceResponsePromise;
    if (!preferenceResponse.ok()) throw new Error();
    const preferencePayload = await preferenceResponse.json();
    if (preferencePayload?.data?.scope !== 'account') throw new Error();
    const explicitDefault = preferencePayload?.data?.default_preset_id ?? null;
    const comboChart = page.getByRole('region', { name: 'Stock Details combo chart', exact: true });
    await comboChart.waitFor({ state: 'visible' });
    const preset = comboChart.getByLabel('Combo chart preset');
    const openingPreset = await preset.inputValue();
    const expectedDefault = await preset.locator('option:not([disabled])').evaluateAll((options, savedDefault) => {
        const ids = options.map(option => option.value);
        return savedDefault && ids.includes(savedDefault) ? savedDefault : ids.includes('price-volume') ? 'price-volume' : ids[0] ?? null;
    }, explicitDefault);
    if (!expectedDefault || openingPreset !== expectedDefault) throw new Error();
    const nextPreset = comboChart.getByRole('button', { name: 'Next combo chart' });
    await nextPreset.focus();
    await page.keyboard.press('Enter');
    const enabledPresets = await preset.locator('option:not([disabled])').count();
    if (enabledPresets > 1) {
        await page.waitForFunction(({ selector, opening }) => document.querySelector(selector)?.value !== opening, { selector: '#combo-chart-preset', opening: openingPreset });
    } else {
        await page.waitForFunction(({ selector, opening }) => document.querySelector(selector)?.value === opening, { selector: '#combo-chart-preset', opening: openingPreset });
    }
    const legend = comboChart.getByRole('checkbox').first();
    const checked = await legend.isChecked();
    await legend.setChecked(!checked);
    await legend.setChecked(checked);
    await preset.selectOption(openingPreset);
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
