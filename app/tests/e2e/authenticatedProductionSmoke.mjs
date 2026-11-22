import { allowProductionSmokeRequest, createTransientWelcomeSkipGate, neutralizeProductionSmokeRequest, productionSmokeBlockReason, transientWelcomeSkipPayload } from './productionSmokePolicy.js';
import { readOnlyReviewHeading } from './productionSmokeSelectors.js';
import { createGuidedTourResponseCapture, readGuidedTourPayload, safeAuthenticatedSmokeStage, waitForPresetChartGeometry } from './productionSmokeDiagnostics.js';

let stage = 'configuration';
let browser;
let statusResponseWatchdog;
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
    const guidedTourCapture = createGuidedTourResponseCapture(page, baseURL);
    let guidedTourResponse;
    try {
        await page.goto('/review');
        await readOnlyReviewHeading(page).waitFor({ state: 'visible' });
        stage = 'guided_tour_response_wait';
        guidedTourResponse = await guidedTourCapture.response;
    } finally {
        guidedTourCapture.dispose();
    }
    stage = 'guided_tour_response_status';
    if (!guidedTourResponse.ok()) throw new Error();
    stage = 'guided_tour_payload_parse';
    const parsedGuidedTourPayload = await readGuidedTourPayload(guidedTourResponse);
    if (parsedGuidedTourPayload.failureStage) {
        stage = parsedGuidedTourPayload.failureStage;
        throw new Error();
    }
    const guidedTourPayload = parsedGuidedTourPayload.payload;
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
    const baseOrigin = new URL(baseURL).origin;
    const preferenceResponsePromise = page.waitForResponse(response => {
        try {
            const target = new URL(response.url());
            return response.request().method() === 'GET'
                && target.origin === baseOrigin
                && target.pathname === '/api/v1/stock-details/combo-chart-preference';
        } catch {
            return false;
        }
    });
    await page.goto('/watchlist/TCS');
    stage = 'combo_preference_response_wait';
    const preferenceResponse = await preferenceResponsePromise;
    stage = 'combo_preference_response_status';
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
    stage = 'dashboard_document_navigation';
    let physicalBrokerStatusRequests = 0;
    const countPhysicalBrokerStatusRequest = request => {
        try {
            const target = new URL(request.url());
            if (request.method() === 'GET' && target.pathname === '/api/v1/broker/status') physicalBrokerStatusRequests += 1;
        } catch {
            // Unreadable request metadata cannot contribute to the allowlisted count.
        }
    };
    page.on('request', countPhysicalBrokerStatusRequest);
    let resolvePhysicalBrokerStatusResponse;
    const brokerStatusResponsePromise = new Promise(resolve => {
        resolvePhysicalBrokerStatusResponse = resolve;
        statusResponseWatchdog = setTimeout(() => resolve(null), 15_000);
    });
    const capturePhysicalBrokerStatusResponse = response => {
        const request = response.request();
        try {
            if (request.method() === 'GET' && new URL(response.url()).pathname === '/api/v1/broker/status') {
                clearTimeout(statusResponseWatchdog);
                resolvePhysicalBrokerStatusResponse(response);
            }
        } catch {
            // Unreadable response metadata cannot satisfy the expected status read.
        }
    };
    page.on('response', capturePhysicalBrokerStatusResponse);
    const authenticatedUserResponsePromise = page.waitForResponse(response => {
        const request = response.request();
        try { return request.method() === 'GET' && new URL(response.url()).pathname === '/api/auth/me'; } catch { return false; }
    });
    let brokerStatusPayload = null;
    await page.goto('/');
    stage = 'dashboard_identity_response';
    const authenticatedUserResponse = await authenticatedUserResponsePromise;
    if (!authenticatedUserResponse.ok()) throw new Error();
    const authenticatedUserPayload = await authenticatedUserResponse.json();
    const isAdmin = authenticatedUserPayload?.user?.is_admin;
    if (typeof isAdmin !== 'boolean') throw new Error();
    const identityClass = isAdmin ? 'admin' : 'investor';
    const header = page.locator('.lido-header');
    stage = 'dashboard_header_visible';
    await header.waitFor({ state: 'visible' });
    await header.locator('.lido-profile-toggle').waitFor({ state: 'visible' });
    const kiteControl = header.locator('.lido-kite-header-control');
    if (isAdmin) {
        stage = 'dashboard_kite_admin_ineligible';
        if (await kiteControl.count()) throw new Error();
        if (physicalBrokerStatusRequests !== 0) throw new Error();
    } else {
        stage = 'dashboard_kite_status_response';
        const physicalBrokerStatusResponse = await brokerStatusResponsePromise;
        if (!physicalBrokerStatusResponse || !physicalBrokerStatusRequests) throw new Error();
        stage = 'dashboard_kite_status_shape';
        if (physicalBrokerStatusResponse.ok()) {
            const payload = await physicalBrokerStatusResponse.json();
            const data = payload?.data;
            if (typeof data?.configured === 'boolean' && typeof data?.connected === 'boolean' && typeof data?.usable === 'boolean') brokerStatusPayload = data;
        }
        stage = 'dashboard_kite_control_visible';
        await kiteControl.waitFor({ state: 'visible' });
    }
    let kiteCoverage;
    if (isAdmin) {
        kiteCoverage = 'not_eligible_admin';
    } else {
        // The emergency controls are hydrated from a separate read-only execution-state
        // response. Wait for them before asserting their order relative to Kite.
        const safetyControls = header.locator('.lido-execution-safety');
        stage = 'dashboard_kite_safety_controls';
        await safetyControls.waitFor({ state: 'visible' });
        stage = 'dashboard_kite_header_order';
        const headerOrder = await header.locator('.lido-header-actions').evaluate(actions => {
            const kite = actions.querySelector('.lido-kite-header-control');
            const safety = actions.querySelector('.lido-execution-safety');
            return Boolean(kite && safety && (kite.compareDocumentPosition(safety) & Node.DOCUMENT_POSITION_FOLLOWING));
        });
        if (!headerOrder) throw new Error();
        stage = 'dashboard_kite_accessible_label';
        const kitePrimaryButton = kiteControl.getByRole('button').first();
        const kitePrimaryLabel = await kitePrimaryButton.getAttribute('aria-label');
        if (!kitePrimaryLabel || !/^(?:Connect Kite|Kite connected(?: · expires .+)?|Kite session expired · Connect Kite|Kite status unknown · Refresh to check|Kite is not configured on this server)$/.test(kitePrimaryLabel)) throw new Error();
        const emergencyControlsBefore = await safetyControls.locator('button').evaluateAll(buttons => buttons.map(button => button.getAttribute('aria-label')));
        if (!emergencyControlsBefore.includes('Emergency Kite disconnect')
            || !emergencyControlsBefore.includes('Cancel eligible open orders and disconnect Kite')) throw new Error();
        stage = 'dashboard_kite_menu';
        // The accessible name changes when the status GET hydrates. Keep the
        // menu target stable across that render instead of using a stale label
        // or ElementHandle; still verify the current accessible label exactly.
        const kiteMenuButton = kiteControl.locator('button[aria-haspopup="menu"]');
        if (await kiteMenuButton.count() !== 1) throw new Error();
        if (brokerStatusPayload?.configured === false) {
            if (!(await kiteMenuButton.isDisabled())) throw new Error();
        } else {
            stage = 'dashboard_kite_menu_ready';
            await page.waitForFunction(requireConfirmed => {
                const control = document.querySelector('.lido-header .lido-kite-header-control');
                const primary = control?.querySelector('button:not([aria-haspopup="menu"])');
                const menu = control?.querySelector('button[aria-haspopup="menu"]');
                const label = primary?.getAttribute('aria-label');
                return Boolean(menu && !menu.disabled && label
                    && menu.getAttribute('aria-label') === `${label} menu`
                    && (!requireConfirmed || (
                        label !== 'Kite status unknown · Refresh to check'
                        && label !== 'Kite is not configured on this server'
                    )));
            }, brokerStatusPayload?.configured === true);
            const livePrimaryLabel = await kitePrimaryButton.getAttribute('aria-label');
            if (!livePrimaryLabel || await kiteMenuButton.getAttribute('aria-label') !== `${livePrimaryLabel} menu`) throw new Error();
            stage = 'dashboard_kite_menu_open';
            await kiteMenuButton.click();
            stage = 'dashboard_kite_menu_expanded';
            await page.waitForFunction(() => document.querySelector(
                '.lido-header .lido-kite-header-control button[aria-haspopup="menu"]'
            )?.getAttribute('aria-expanded') === 'true');
            stage = 'dashboard_kite_menu_items';
            const kiteMenu = page.getByRole('menu');
            await kiteMenu.waitFor({ state: 'visible' });
            await kiteMenu.getByRole('menuitem', { name: 'Kite settings', exact: true }).waitFor({ state: 'visible' });
            const disconnectItem = page.getByRole('menuitem', { name: 'Disconnect Kite…', exact: true });
            if (brokerStatusPayload?.connected) await disconnectItem.waitFor({ state: 'visible' });
            else if (await disconnectItem.count()) throw new Error();
            stage = 'dashboard_kite_menu_close';
            await kiteMenuButton.click();
            stage = 'dashboard_kite_menu_collapsed';
            await page.waitForFunction(() => document.querySelector(
                '.lido-header .lido-kite-header-control button[aria-haspopup="menu"]'
            )?.getAttribute('aria-expanded') === 'false');
        }
        if (JSON.stringify(await safetyControls.locator('button').evaluateAll(buttons => buttons.map(button => button.getAttribute('aria-label')))) !== JSON.stringify(emergencyControlsBefore)) throw new Error();
        kiteCoverage = 'verified';
    }
    stage = 'dashboard_reconciliation_gate';
    const reconciliationCard = page.locator('.card').filter({ hasText: 'Broker portfolio reconciliation' });
    if (await reconciliationCard.count()) {
        await reconciliationCard.waitFor({ state: 'visible' });
        const reconcile = reconciliationCard.getByRole('button', { name: 'Reconcile', exact: true });
        await reconcile.waitFor({ state: 'visible' });
        const brokerUsable = brokerStatusPayload?.usable === true
            && (!brokerStatusPayload.expires_at || Date.parse(brokerStatusPayload.expires_at) > Date.now());
        if (brokerUsable ? await reconcile.isDisabled() : !(await reconcile.isDisabled())) throw new Error();
        if (brokerUsable) {
            await reconciliationCard.getByRole('button', { name: 'Sync CNC trades', exact: true }).waitFor({ state: 'visible' });
        }
    } else if (await page.getByText('Broker portfolio reconciliation', { exact: true }).count()) {
        throw new Error();
    }
    for (const width of [390, 1280]) {
        await page.setViewportSize({ width, height: 844 });
        if (!isAdmin && !(await kiteControl.isVisible())) throw new Error();
        if (await reconciliationCard.count() && !(await reconciliationCard.isVisible())) throw new Error();
    }
    stage = 'request_safety';
    if (blocked) throw new Error();
    page.off('request', countPhysicalBrokerStatusRequest);
    page.off('response', capturePhysicalBrokerStatusResponse);
    clearTimeout(statusResponseWatchdog);
    console.log(`AUTHENTICATED_SMOKE_IDENTITY:${identityClass}`);
    console.log(`AUTHENTICATED_SMOKE_KITE_COVERAGE:${kiteCoverage}`);
    console.log(`AUTHENTICATED_SMOKE_KITE_STATUS:${brokerStatusPayload ? brokerStatusPayload.usable ? 'usable' : brokerStatusPayload.connected ? 'connected_not_usable' : brokerStatusPayload.configured ? 'configured_disconnected' : 'unconfigured' : 'unavailable'}`);
    console.log(`AUTHENTICATED_SMOKE_RECONCILIATION:${await reconciliationCard.count() ? 'eligible_card_verified' : 'not_eligible_card_absent'}`);
    console.log('AUTHENTICATED_SMOKE_PASSED');
} catch {
    // Never print Playwright errors: fill arguments or network bodies may contain secrets.
    if (blockedReasons.size) console.log(`AUTHENTICATED_SMOKE_BLOCKED:${[...blockedReasons].sort().join(',')}`);
    console.log(`AUTHENTICATED_SMOKE_FAILED:${safeAuthenticatedSmokeStage(stage)}`);
    process.exitCode = 1;
} finally {
    clearTimeout(statusResponseWatchdog);
    if (browser) await browser.close().catch(() => {});
}
