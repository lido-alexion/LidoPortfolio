/** A production smoke request must be allowed before it can reach the server. */
export function allowProductionSmokeRequest(method, url, baseUrl, { login = false } = {}) {
    const target = new URL(url);
    if (target.origin !== new URL(baseUrl).origin) return false;
    if (method === 'GET' || method === 'HEAD') return true;
    if (method !== 'POST') return false;
    if (login && target.pathname === '/api/auth/login') return true;
    return ['/api/logs/frontend', '/api/telemetry', '/api/telemetry/route-view', '/api/telemetry/otlp/v1/traces'].includes(target.pathname);
}


/** Only the known automatic welcome counter is answered locally during smoke setup. */
export function neutralizeProductionSmokeRequest(method, url, baseUrl, body) {
    const target = new URL(url);
    if (target.origin !== new URL(baseUrl).origin || method !== 'PUT' || target.pathname !== '/api/guided-tour') return false;
    try {
        const data = JSON.parse(body);
        return data !== null && typeof data === 'object' && !Array.isArray(data)
            && Object.keys(data).length === 1 && data.action === 'record_welcome_shown';
    } catch {
        return false;
    }
}

/** Allow only the explicitly clicked welcome Skip action to be fulfilled locally. */
export function allowTransientWelcomeSkipRequest(method, url, baseUrl, body, { uiActionInProgress = false } = {}) {
    if (uiActionInProgress !== true || method !== 'PUT') return false;
    try {
        const target = new URL(url);
        const base = new URL(baseUrl);
        if (target.origin !== base.origin || target.pathname !== '/api/guided-tour' || target.search || target.hash) return false;
        const data = JSON.parse(body);
        return data !== null && typeof data === 'object' && !Array.isArray(data)
            && Object.keys(data).length === 1 && data.action === 'skip_prompt';
    } catch {
        return false;
    }
}

/** Create a one-use gate so a click cannot authorize concurrent Skip-shaped requests. */
export function createTransientWelcomeSkipGate(baseUrl) {
    let claimed = false;
    return (method, url, body, { uiActionInProgress = false } = {}) => {
        if (claimed || !allowTransientWelcomeSkipRequest(method, url, baseUrl, body, { uiActionInProgress })) return false;
        claimed = true;
        return true;
    };
}

/** Mirror only the real skip_prompt state transition, without server persistence. */
export function transientWelcomeSkipPayload(currentState) {
    if (!currentState || typeof currentState !== 'object' || Array.isArray(currentState)) return null;
    const currentCount = Number(currentState.welcome_prompt_count);
    const maximumCount = Number(currentState.max_auto_prompts);
    if (!Number.isFinite(currentCount) || !Number.isFinite(maximumCount)) return null;
    const welcomePromptCount = currentCount + 1;
    return {
        ...currentState,
        welcome_prompt_count: welcomePromptCount,
        show_welcome_prompt: Boolean(currentState.eligible)
            && currentState.completed !== true
            && currentState.permanently_dismissed !== true
            && welcomePromptCount < maximumCount
            && currentState.tour_in_progress !== true,
    };
}


// Diagnostics are fixed categories, never URLs, query strings, headers or bodies.
const BLOCK_REASONS = ['cross_origin', 'guided_tour_write', 'late_login_write', 'other_write'];
export function productionSmokeBlockReason(method, url, baseUrl) {
    const target = new URL(url);
    if (target.origin !== new URL(baseUrl).origin) return 'cross_origin';
    if (method === 'PUT' && target.pathname === '/api/guided-tour') return 'guided_tour_write';
    if (method === 'POST' && target.pathname === '/api/auth/login') return 'late_login_write';
    return 'other_write';
}
export function summarizeProductionSmokeBlocks(stdout = '') {
    const match = String(stdout).match(/^AUTHENTICATED_SMOKE_BLOCKED:([a-z_,]+)$/m);
    return [...new Set((match?.[1] || '').split(',').filter(reason => BLOCK_REASONS.includes(reason)))].sort();
}
