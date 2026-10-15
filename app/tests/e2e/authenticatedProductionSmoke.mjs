import { allowProductionSmokeRequest, neutralizeProductionSmokeRequest, productionSmokeBlockReason } from './productionSmokePolicy.js';
import { readOnlyReviewHeading } from './productionSmokeSelectors.js';
import { safeAuthenticatedSmokeStage, waitForPresetChartGeometry } from './productionSmokeDiagnostics.js';

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
    stage = 'combo_navigation';
    const preferenceResponsePromise = page.waitForResponse(response => response.request().method() === 'GET' && new URL(response.url()).pathname === '/api/v1/stock-details/combo-chart-preference');
    await page.goto('/watchlist/TCS');
    stage = 'combo_preference_response';
    const preferenceResponse = await preferenceResponsePromise;
    if (!preferenceResponse.ok()) throw new Error();
    stage = 'combo_preference_scope';
    const preferencePayload = await preferenceResponse.json();
    if (preferencePayload?.data?.scope !== 'account') throw new Error();
    const explicitDefault = preferencePayload?.data?.default_preset_id ?? null;
    const comboChart = page.getByRole('region', { name: 'Stock Details combo chart', exact: true });
    const chartAcceptanceDeadline = Date.now() + 20_000;
    const remainingChartAcceptanceMs = () => Math.max(1, chartAcceptanceDeadline - Date.now());
    stage = 'combo_chart_attached';
    await comboChart.waitFor({ state: 'attached', timeout: remainingChartAcceptanceMs() });
    stage = 'combo_chart_visible';
    await comboChart.waitFor({ state: 'visible', timeout: remainingChartAcceptanceMs() });
    const preset = comboChart.getByLabel('Combo chart preset');
    stage = 'combo_default_options';
    if (await preset.locator('option:not([value=""])').count() !== 7) throw new Error();
    stage = 'combo_default_resolution';
    const resolvedExpectedDefault = await page.waitForFunction((savedDefault) => {
        const selector = document.querySelector('#combo-chart-preset');
        if (!selector) return null;
        const options = [...selector.options];
        const saved = options.find(option => option.value === savedDefault);
        if (saved?.textContent.includes('Loading historical')) return null;
        const enabledIds = options.filter(option => !option.disabled).map(option => option.value);
        if (savedDefault && enabledIds.includes(savedDefault)) return savedDefault;
        return enabledIds.includes('price-volume') ? 'price-volume' : enabledIds[0] ?? null;
    }, explicitDefault, { timeout: remainingChartAcceptanceMs() });
    const expectedDefault = await resolvedExpectedDefault.jsonValue();
    stage = 'combo_default_selection';
    const openingPreset = await preset.inputValue();
    stage = 'combo_default_precedence';
    if (!expectedDefault || openingPreset !== expectedDefault) throw new Error();
    stage = 'combo_chart_data_rendered';
    await comboChart.scrollIntoViewIfNeeded({ timeout: remainingChartAcceptanceMs() });
    const chartRegion = await comboChart.elementHandle({ timeout: remainingChartAcceptanceMs() });
    const chartGeometryAccepted = await waitForPresetChartGeometry({
        presetId: openingPreset,
        deadline: chartAcceptanceDeadline,
        readGeometry: () => chartRegion.evaluate(region => ({
            linePathData: [...region.querySelectorAll('.recharts-line-curve')].map(path => path.getAttribute('d')),
            barPathData: [...region.querySelectorAll('.recharts-bar-rectangle .recharts-rectangle')].map(rect => rect.getAttribute('d')),
        })),
    });
    if (!chartGeometryAccepted) throw new Error();
    stage = 'combo_keyboard_navigation';
    const nextPreset = comboChart.getByRole('button', { name: 'Next combo chart' });
    await nextPreset.focus();
    await page.keyboard.press('Enter');
    const enabledPresets = await preset.locator('option:not([disabled])').count();
    if (enabledPresets > 1) {
        await page.waitForFunction(({ selector, opening }) => document.querySelector(selector)?.value !== opening, { selector: '#combo-chart-preset', opening: openingPreset });
    } else {
        await page.waitForFunction(({ selector, opening }) => document.querySelector(selector)?.value === opening, { selector: '#combo-chart-preset', opening: openingPreset });
    }
    stage = 'combo_legend_toggle';
    const legend = comboChart.getByRole('checkbox').first();
    const checked = await legend.isChecked();
    await legend.setChecked(!checked);
    await legend.setChecked(checked);
    stage = 'combo_selection_restore';
    await preset.selectOption(openingPreset);
    stage = 'request_safety';
    if (blocked) throw new Error();
    console.log('AUTHENTICATED_SMOKE_PASSED');
} catch {
    // Never print Playwright errors: fill arguments or network bodies may contain secrets.
    if (blockedReasons.size) console.log(`AUTHENTICATED_SMOKE_BLOCKED:${[...blockedReasons].sort().join(',')}`);
    console.log(`AUTHENTICATED_SMOKE_FAILED:${safeAuthenticatedSmokeStage(stage)}`);
    process.exitCode = 1;
} finally {
    if (browser) await browser.close().catch(() => {});
}
