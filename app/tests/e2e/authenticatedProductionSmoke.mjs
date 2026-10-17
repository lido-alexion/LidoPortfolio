import { allowProductionSmokeRequest, createTransientWelcomeSkipGate, neutralizeProductionSmokeRequest, productionSmokeBlockReason, transientWelcomeSkipPayload } from './productionSmokePolicy.js';
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
    let welcomeSkipUiActionInProgress = false;
    let welcomeSkipFulfilledLocally = false;
    let welcomeSkipRequest = null;
    let welcomeSkipResponsePayload = null;
    const claimWelcomeSkipRequest = createTransientWelcomeSkipGate(baseURL);
    await page.route('**/*', async route => {
        const request = route.request();
        const method = request.method();
        const url = request.url();
        if (neutralizeProductionSmokeRequest(method, url, baseURL, request.postData())) {
            return route.fulfill({ status: 204 });
        }
        if (claimWelcomeSkipRequest(method, url, request.postData(), { uiActionInProgress: welcomeSkipUiActionInProgress })) {
            if (!welcomeSkipResponsePayload) {
                blocked = true;
                blockedReasons.add(productionSmokeBlockReason(method, url, baseURL));
                return route.abort();
            }
            welcomeSkipRequest = request;
            welcomeSkipFulfilledLocally = true;
            return route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({ data: welcomeSkipResponsePayload }),
            });
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
    const guidedTourResponsePromise = page.waitForResponse(response => {
        const request = response.request();
        const target = new URL(response.url());
        return request.method() === 'GET' && target.origin === new URL(baseURL).origin && target.pathname === '/api/guided-tour';
    });
    await page.goto('/review');
    await readOnlyReviewHeading(page).waitFor({ state: 'visible' });
    stage = 'guided_tour_response_wait';
    const guidedTourResponse = await guidedTourResponsePromise;
    stage = 'guided_tour_response_status';
    if (!guidedTourResponse.ok()) throw new Error();
    stage = 'guided_tour_payload_parse';
    const guidedTourPayload = await guidedTourResponse.json();
    stage = 'guided_tour_payload_shape';
    const welcomeExpected = guidedTourPayload?.data?.show_welcome_prompt;
    if (typeof welcomeExpected !== 'boolean') throw new Error();
    stage = 'welcome_skip_payload';
    welcomeSkipResponsePayload = transientWelcomeSkipPayload(guidedTourPayload.data);
    if (!welcomeSkipResponsePayload) throw new Error();
    stage = 'welcome_modal_render';
    await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
    const welcome = page.locator('.lido-guided-tour-modal[aria-labelledby="guided-tour-welcome-title"]');
    const resume = page.locator('.lido-guided-tour-modal[aria-labelledby="guided-tour-resume-title"]');
    stage = 'welcome_modal_visibility';
    if (welcomeExpected) await welcome.waitFor({ state: 'visible' });
    else if (await welcome.count()) throw new Error();
    stage = 'resume_modal_visibility';
    if (await resume.count()) throw new Error();
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
    stage = 'account_onboarding_prompt';
    if (welcomeExpected) {
        await welcome.waitFor({ state: 'visible' });
        const skip = welcome.getByRole('button', { name: 'Skip for now', exact: true });
        const skipResponsePromise = page.waitForResponse(response => {
            return response.request() === welcomeSkipRequest;
        });
        welcomeSkipUiActionInProgress = true;
        let skipResponse;
        try {
            await skip.click();
            skipResponse = await skipResponsePromise;
        } finally {
            welcomeSkipUiActionInProgress = false;
        }
        if (!skipResponse.ok() || !welcomeSkipFulfilledLocally) throw new Error();
        await welcome.waitFor({ state: 'hidden' });
        await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
        if (await resume.count()) throw new Error();
    } else if (await welcome.count()) {
        throw new Error();
    }
    stage = 'combo_legend_control';
    const legend = comboChart.getByRole('checkbox').first();
    stage = 'combo_legend_read';
    const checked = await legend.isChecked();
    stage = 'combo_legend_toggle_off';
    await legend.setChecked(!checked);
    stage = 'combo_legend_toggle_restore';
    await legend.setChecked(checked);
    stage = 'combo_legend_verify_restore';
    if (await legend.isChecked() !== checked) throw new Error();
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
