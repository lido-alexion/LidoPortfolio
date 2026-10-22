import test from 'node:test';
import assert from 'node:assert/strict';
import { createGuidedTourResponseCapture, hasPresetChartGeometry, readGuidedTourPayload, safeAuthenticatedSmokeStage, waitForPresetChartGeometry } from '../e2e/productionSmokeDiagnostics.js';
import { allowProductionSmokeRequest, allowTransientWelcomeSkipRequest } from '../e2e/productionSmokePolicy.js';
import { EventEmitter } from 'node:events';

test('authenticated smoke reports only fixed, stage-level diagnostics', () => {
    for (const stage of [
        'configuration', 'browser_launch', 'login', 'review',
        'guided_tour_response_wait', 'guided_tour_response_status', 'guided_tour_payload_parse', 'guided_tour_payload_shape',
        'guided_tour_payload_invalid_json', 'guided_tour_payload_read_failed',
        'welcome_skip_payload', 'welcome_modal_render', 'welcome_modal_visibility', 'resume_modal_visibility', 'combo_navigation',
        'combo_preference_response', 'combo_preference_scope', 'combo_chart_attached', 'combo_chart_visible',
        'combo_default_selection', 'combo_default_options', 'combo_default_resolution', 'combo_default_precedence', 'combo_chart_data_rendered', 'combo_keyboard_navigation',
        'combo_legend_control', 'combo_legend_read', 'combo_legend_toggle_off',
        'account_onboarding_prompt', 'combo_legend_toggle_restore', 'combo_legend_verify_restore',
        'combo_selection_restore', 'request_safety',
    ]) {
        assert.equal(safeAuthenticatedSmokeStage(stage), stage);
    }
    assert.equal(safeAuthenticatedSmokeStage('secret@example.test'), 'unknown');
    assert.equal(safeAuthenticatedSmokeStage('provider-response-with-private-data'), 'unknown');
    assert.equal(safeAuthenticatedSmokeStage('https://example.test/path?token=private'), 'unknown');
});

test('guided-tour JSON diagnostics separate syntax from other read failures without exposing details', async () => {
    const valid = await readGuidedTourPayload({ json: async () => ({ data: { show_welcome_prompt: false } }) });
    assert.deepEqual(valid, { payload: { data: { show_welcome_prompt: false } }, failureStage: null });

    const invalidJson = await readGuidedTourPayload({ json: async () => { throw new SyntaxError('private response bytes'); } });
    assert.deepEqual(invalidJson, { payload: null, failureStage: 'guided_tour_payload_invalid_json' });

    const unavailableBody = await readGuidedTourPayload({ json: async () => { throw new Error('private body/session detail'); } });
    assert.deepEqual(unavailableBody, { payload: null, failureStage: 'guided_tour_payload_read_failed' });
    assert.equal(safeAuthenticatedSmokeStage(invalidJson.failureStage), invalidJson.failureStage);
    assert.equal(safeAuthenticatedSmokeStage(unavailableBody.failureStage), unavailableBody.failureStage);
    assert.equal(JSON.stringify([invalidJson, unavailableBody]).includes('private'), false);
});

test('guided-tour capture ignores pre-navigation responses and accepts a post-commit request across child-frame navigation', async () => {
    const origin = 'https://stox.example.test';
    const frame = { currentURL: `${origin}/login`, url() { return this.currentURL; } };
    const childFrame = { url: () => `${origin}/review` };
    const page = new EventEmitter();
    page.mainFrame = () => frame;
    const capture = createGuidedTourResponseCapture(page, origin, 20);
    const staleRequest = { method: () => 'GET', url: () => `${origin}/api/guided-tour`, frame: () => frame };
    let staleBodyRead = false;
    const staleResponse = {
        request: () => staleRequest,
        json: async () => { staleBodyRead = true; throw new Error('stale document body'); },
    };
    let selected = false;
    const result = capture.response.then(value => { selected = true; return value; });

    page.emit('request', staleRequest);
    frame.currentURL = `${origin}/review`;
    page.emit('framenavigated', childFrame);
    page.emit('framenavigated', frame);
    page.emit('response', staleResponse);
    await Promise.resolve();
    assert.equal(selected, false);
    assert.equal(staleBodyRead, false);

    const currentRequest = { method: () => 'GET', url: () => `${origin}/api/guided-tour`, frame: () => frame };
    const currentResponse = { request: () => currentRequest, json: async () => ({ data: { show_welcome_prompt: false } }) };
    page.emit('request', currentRequest);
    page.emit('framenavigated', childFrame);
    page.emit('response', currentResponse);
    assert.strictEqual(await result, currentResponse);
    assert.equal(page.listenerCount('request'), 0);
    assert.equal(page.listenerCount('response'), 0);
    assert.equal(page.listenerCount('framenavigated'), 0);
    capture.dispose();
});

test('guided-tour capture resets request identity across main-frame documents and fails closed on body-read errors', async () => {
    const origin = 'https://stox.example.test';
    const frame = { currentURL: `${origin}/login`, url() { return this.currentURL; } };
    const page = new EventEmitter();
    page.mainFrame = () => frame;
    frame.currentURL = `${origin}/review`;
    const capture = createGuidedTourResponseCapture(page, origin, 20);
    page.emit('framenavigated', frame);
    const oldDocumentRequest = { method: () => 'GET', url: () => `${origin}/api/guided-tour`, frame: () => frame };
    page.emit('request', oldDocumentRequest);
    frame.currentURL = `${origin}/login`;
    page.emit('framenavigated', frame);
    page.emit('response', { request: () => oldDocumentRequest });
    frame.currentURL = `${origin}/review`;
    page.emit('framenavigated', frame);

    const wrongFrameRequest = { method: () => 'GET', url: () => `${origin}/api/guided-tour`, frame: () => ({}) };
    const wrongOriginRequest = { method: () => 'GET', url: () => 'https://other.test/api/guided-tour', frame: () => frame };
    const wrongMethodRequest = { method: () => 'POST', url: () => `${origin}/api/guided-tour`, frame: () => frame };
    page.emit('request', wrongFrameRequest);
    page.emit('request', wrongOriginRequest);
    page.emit('request', wrongMethodRequest);
    page.emit('response', { request: () => wrongFrameRequest });
    page.emit('response', { request: () => wrongOriginRequest });
    page.emit('response', { request: () => wrongMethodRequest });
    const oldDocumentOutcome = capture.response.then(() => 'resolved', () => 'rejected');
    const timedOut = await oldDocumentOutcome;
    assert.equal(timedOut, 'rejected');
    assert.equal(page.listenerCount('request'), 0);
    assert.equal(page.listenerCount('response'), 0);
    assert.equal(page.listenerCount('framenavigated'), 0);

    const currentCapture = createGuidedTourResponseCapture(page, origin, 1000);
    page.emit('framenavigated', frame);
    const currentRequest = { method: () => 'GET', url: () => `${origin}/api/guided-tour`, frame: () => frame };
    const currentResponse = { request: () => currentRequest, json: async () => { throw new Error('private body detail'); } };
    page.emit('request', currentRequest);
    page.emit('response', currentResponse);
    const failedRead = await readGuidedTourPayload(await currentCapture.response);
    currentCapture.dispose();
    assert.deepEqual(failedRead, { payload: null, failureStage: 'guided_tour_payload_read_failed' });
    assert.equal(JSON.stringify(failedRead).includes('private'), false);
});

test('a response from a previous committed Review document cannot satisfy a later Review capture', async () => {
    const origin = 'https://stox.example.test';
    const frame = { currentURL: `${origin}/login`, url() { return this.currentURL; } };
    const page = new EventEmitter();
    page.mainFrame = () => frame;
    const capture = createGuidedTourResponseCapture(page, origin, 1000);
    frame.currentURL = `${origin}/review`;
    page.emit('framenavigated', frame);
    const firstDocumentRequest = { method: () => 'GET', url: () => `${origin}/api/guided-tour`, frame: () => frame };
    page.emit('request', firstDocumentRequest);

    frame.currentURL = `${origin}/account`;
    page.emit('framenavigated', frame);
    const staleResponse = { request: () => firstDocumentRequest };
    frame.currentURL = `${origin}/review`;
    page.emit('framenavigated', frame);
    page.emit('response', staleResponse);

    let selected = false;
    const result = capture.response.then(value => { selected = true; return value; });
    await Promise.resolve();
    assert.equal(selected, false);

    const secondDocumentRequest = { method: () => 'GET', url: () => `${origin}/api/guided-tour`, frame: () => frame };
    const secondDocumentResponse = { request: () => secondDocumentRequest };
    page.emit('request', secondDocumentRequest);
    page.emit('response', secondDocumentResponse);
    assert.strictEqual(await result, secondDocumentResponse);
    capture.dispose();
});

test('readiness diagnostics preserve the existing read-only and one-use welcome policy', () => {
    const base = 'https://stox.example.test';
    const endpoint = `${base}/api/guided-tour`;
    assert.equal(allowProductionSmokeRequest('GET', endpoint, base), true);
    assert.equal(allowProductionSmokeRequest('PUT', endpoint, base), false);
    assert.equal(allowProductionSmokeRequest('POST', `${base}/api/v1/orders`, base, { login: true }), false);
    assert.equal(allowTransientWelcomeSkipRequest('PUT', endpoint, base, '{"action":"skip_prompt"}', { uiActionInProgress: false }), false);
    assert.equal(allowTransientWelcomeSkipRequest('PUT', endpoint, base, '{"action":"skip_prompt"}', { uiActionInProgress: true }), true);
});

test('combo geometry acceptance requires non-empty preset-appropriate plotted paths', () => {
    assert.equal(hasPresetChartGeometry({ linePathData: ['M0 0L1 1', 'M0 1L1 0'] }, 'price-pe'), true);
    assert.equal(hasPresetChartGeometry({ linePathData: ['M0 0L1 1'], barPathData: ['M0 0h2v3z'] }, 'price-volume'), true);
    assert.equal(hasPresetChartGeometry({ linePathData: [' '], barPathData: [''] }, 'price-volume'), false);
    assert.equal(hasPresetChartGeometry({ linePathData: ['M0 0L1 1'] }, 'price-pe'), false);
});

test('geometry polling stops at its raw deadline when a chart remains empty', async () => {
    const budgetMs = 25;
    let elapsedMs = 0;
    let reads = 0;
    const accepted = await waitForPresetChartGeometry({
        presetId: 'price-volume',
        deadline: budgetMs,
        now: () => elapsedMs,
        wait: async milliseconds => {
            elapsedMs += milliseconds;
            if (elapsedMs > budgetMs) throw new Error('geometry polling exceeded its deterministic deadline');
        },
        readGeometry: async () => {
            reads += 1;
            return { linePathData: [''], barPathData: [' '] };
        },
    });
    assert.equal(accepted, false);
    assert.equal(elapsedMs, budgetMs);
    assert.ok(reads > 0);
    assert.ok(reads < 10);
});

test('geometry reads that resolve after the deadline are rejected by the watchdog', async () => {
    const budgetMs = 25;
    let elapsedMs = 0;
    let resolveLateGeometry;
    const lateGeometry = new Promise(resolve => { resolveLateGeometry = resolve; });
    const accepted = await waitForPresetChartGeometry({
        presetId: 'price-pe',
        deadline: budgetMs,
        now: () => elapsedMs,
        scheduleTimeout: (callback, milliseconds) => { elapsedMs += milliseconds; callback(); return 1; },
        cancelTimeout: () => {},
        readGeometry: () => lateGeometry,
    });
    assert.equal(accepted, false);
    assert.equal(elapsedMs, budgetMs);
    resolveLateGeometry({ linePathData: ['M0 0L1 1', 'M1 0L0 1'] });
});
