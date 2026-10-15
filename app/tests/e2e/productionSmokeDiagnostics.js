const AUTHENTICATED_SMOKE_STAGES = new Set([
    'configuration',
    'browser_launch',
    'login',
    'review',
    'account_onboarding_readiness',
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
