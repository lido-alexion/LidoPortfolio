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
