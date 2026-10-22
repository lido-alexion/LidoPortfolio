const AUTHENTICATED_SMOKE_STAGES = new Set([
    'configuration',
    'browser_launch',
    'login',
    'review',
    'guided_tour_response_wait',
    'guided_tour_response_status',
    'guided_tour_payload_parse',
    'guided_tour_payload_invalid_json',
    'guided_tour_payload_read_failed',
    'guided_tour_payload_shape',
    'welcome_skip_payload',
    'welcome_modal_render',
    'welcome_modal_visibility',
    'resume_modal_visibility',
    'combo_navigation',
    'combo_preference_response',
    'combo_preference_scope',
    'combo_chart_attached',
    'combo_chart_visible',
    'combo_default_selection',
    'combo_default_options',
    'combo_default_resolution',
    'combo_default_precedence',
    'combo_chart_data_rendered',
    'combo_keyboard_navigation',
    'account_onboarding_prompt',
    'combo_legend_control',
    'combo_legend_read',
    'combo_legend_toggle_off',
    'combo_legend_toggle_restore',
    'combo_legend_verify_restore',
    'combo_selection_restore',
    'request_safety',
]);

// Only fixed labels may cross the production-smoke logging boundary.
export function safeAuthenticatedSmokeStage(stage) {
    return AUTHENTICATED_SMOKE_STAGES.has(stage) ? stage : 'unknown';
}

// Return only fixed categories; never propagate browser error text or response data.
export async function readGuidedTourPayload(response) {
    try {
        return { payload: await response.json(), failureStage: null };
    } catch (error) {
        return {
            payload: null,
            failureStage: error instanceof SyntaxError
                ? 'guided_tour_payload_invalid_json'
                : 'guided_tour_payload_read_failed',
        };
    }
}

/** Capture only a guided-tour request observed after the Review document commits. */
export function createGuidedTourResponseCapture(page, baseURL, timeoutMs = 20_000) {
    const baseOrigin = new URL(baseURL).origin;
    let acceptedRequests = new WeakSet();
    let reviewDocumentCommitted = false;
    let settled = false;
    let resolveResponse;
    let rejectResponse;
    let timeout;

    const response = new Promise((resolve, reject) => {
        resolveResponse = resolve;
        rejectResponse = reject;
    });
    // A navigation failure may dispose the capture before the caller awaits it.
    response.catch(() => {});

    const finish = (callback, value) => {
        if (settled) return;
        settled = true;
        clearTimeout(timeout);
        page.off('framenavigated', onFrameNavigated);
        page.off('request', onRequest);
        page.off('response', onResponse);
        callback(value);
    };

    const onFrameNavigated = frame => {
        if (frame !== page.mainFrame()) return;
        acceptedRequests = new WeakSet();
        try {
            const target = new URL(frame.url());
            reviewDocumentCommitted = target.origin === baseOrigin && target.pathname === '/review';
        } catch {
            reviewDocumentCommitted = false;
        }
    };

    const onRequest = request => {
        if (!reviewDocumentCommitted) return;
        try {
            const target = new URL(request.url());
            if (request.frame() !== page.mainFrame()
                || request.method() !== 'GET'
                || target.origin !== baseOrigin
                || target.pathname !== '/api/guided-tour') return;
            acceptedRequests.add(request);
        } catch {
            // Requests without a readable page frame or URL are not eligible.
        }
    };

    const onResponse = received => {
        if (acceptedRequests.has(received.request())) finish(resolveResponse, received);
    };

    page.on('framenavigated', onFrameNavigated);
    page.on('request', onRequest);
    page.on('response', onResponse);
    timeout = setTimeout(() => finish(rejectResponse, new Error()), timeoutMs);

    return {
        response,
        dispose() {
            finish(rejectResponse, new Error());
        },
    };
}

// Keep the production acceptance rule shared with browser regression coverage.
// The caller scopes these paths to the Playwright-located combo chart region.
export function hasPresetChartGeometry({ linePathData = [], barPathData = [] }, presetId) {
    const lineCount = linePathData.filter(value => typeof value === 'string' && value.trim()).length;
    const barCount = barPathData.filter(value => typeof value === 'string' && value.trim()).length;
    return ['price-pe', 'price-pb', 'price-ps'].includes(presetId)
        ? lineCount >= 2
        : lineCount >= 1 && barCount >= 1;
}

export async function waitForPresetChartGeometry({
    readGeometry,
    presetId,
    deadline,
    now = () => Date.now(),
    wait = milliseconds => new Promise(resolve => setTimeout(resolve, milliseconds)),
    scheduleTimeout = setTimeout,
    cancelTimeout = clearTimeout,
}) {
    while (now() < deadline) {
        const remainingMs = deadline - now();
        const timedOut = Symbol('geometry-read-deadline');
        let deadlineTimer;
        let geometry;
        try {
            geometry = await Promise.race([
                Promise.resolve().then(readGeometry),
                new Promise(resolve => {
                    deadlineTimer = scheduleTimeout(() => resolve(timedOut), remainingMs);
                }),
            ]);
        } finally {
            cancelTimeout(deadlineTimer);
        }
        if (geometry === timedOut || now() >= deadline) return false;
        if (hasPresetChartGeometry(geometry, presetId)) return true;
        const nextRemainingMs = deadline - now();
        if (nextRemainingMs <= 0) return false;
        await wait(Math.min(100, nextRemainingMs));
    }
    return false;
}
