/** A production smoke request must be allowed before it can reach the server. */
export function allowProductionSmokeRequest(method, url, baseUrl, { login = false } = {}) {
    const target = new URL(url);
    if (target.origin !== new URL(baseUrl).origin) return false;
    if (method === 'GET' || method === 'HEAD') return true;
    if (method !== 'POST') return false;
    if (login && target.pathname === '/api/auth/login') return true;
    return target.pathname === '/api/logs/frontend'
        || target.pathname === '/api/telemetry'
        || target.pathname.startsWith('/api/telemetry/');
}
