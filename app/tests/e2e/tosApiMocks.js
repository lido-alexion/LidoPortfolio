import {
    CAPITAL_RESOLUTION,
    OPEN_BUY_RECOMMENDATION,
    TEST_PORTFOLIO,
    TEST_USER,
    apiEnvelope,
} from '../js/tos/fixtures/tosApi.js';

function json(route, body, status = 200) {
    return route.fulfill({
        status,
        contentType: 'application/json',
        body: JSON.stringify(body),
    });
}

/**
 * Intercept Sanctum + /api so the E2E harness never hits a live backend.
 */
export async function installTosApiMocks(page, { recommendations = [OPEN_BUY_RECOMMENDATION] } = {}) {
    let recs = recommendations.map((r) => ({ ...r }));

    await page.route(/\/(sanctum\/csrf-cookie|api\/)/, async (route) => {
        const request = route.request();
        const url = new URL(request.url());
        const path = url.pathname;
        const method = request.method();

        if (path.endsWith('/sanctum/csrf-cookie')) {
            return route.fulfill({ status: 204, body: '' });
        }
        if (path.endsWith('/api/auth/csrf-token')) {
            return json(route, { token: 'e2e-csrf' });
        }
        if (path.endsWith('/api/auth/me') && method === 'GET') {
            return json(route, { user: TEST_USER });
        }
        if (path.endsWith('/api/portfolios') && method === 'GET') {
            return json(route, { data: [TEST_PORTFOLIO] });
        }
        if (path.endsWith('/api/v1/recommendations') && method === 'GET') {
            return json(route, apiEnvelope(recs));
        }
        const recMatch = path.match(/\/api\/v1\/recommendations\/(\d+)$/);
        if (recMatch && method === 'GET') {
            const rec = recs.find((r) => String(r.id) === recMatch[1]);
            if (!rec) {
                return json(route, { success: false, error: { code: 'NOT_FOUND', message: 'Recommendation not found.' } }, 404);
            }
            return json(route, apiEnvelope(rec));
        }
        if (/\/api\/v1\/recommendations\/\d+\/capital-resolution$/.test(path) && method === 'GET') {
            return json(route, apiEnvelope(CAPITAL_RESOLUTION));
        }
        const reviewMatch = path.match(/\/api\/v1\/recommendations\/(\d+)\/review$/);
        if (reviewMatch && method === 'POST') {
            recs = recs.filter((r) => String(r.id) !== reviewMatch[1]);
            return json(route, apiEnvelope({ status: 'pending_execution' }));
        }
        if (path.endsWith('/api/logs/frontend')) {
            return json(route, { ok: true });
        }
        if (path.endsWith('/api/v1/execution/mode') && method === 'GET') {
            return json(route, apiEnvelope({
                execution_mode: 'manual',
                entitled: false,
                totp_enabled: false,
                blockers: ['entitlement', 'totp', 'broker'],
                can_submit_semi_automatic: false,
                can_submit_automatic: false,
            }));
        }
        if (path.endsWith('/api/v1/recommendations/pending-execution') && method === 'GET') {
            return json(route, apiEnvelope(recs.filter((r) => r.status === 'pending_execution' || r.can_execute_manually), {
                cash: { cash_balance: 0, reserved_cash: 0, available_investable_cash: 0 },
            }));
        }

        return json(route, { success: false, error: { code: 'UNMOCKED', message: `${method} ${path}` } }, 501);
    });
}
