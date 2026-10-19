import assert from 'node:assert/strict';
import { isDeepStrictEqual } from 'node:util';

const REPORT_PATH = '/api/ops/api-failures';
const REPORT_KEYS = ['endpoint', 'message', 'method', 'request_id', 'status'];
const HTTP_METHODS = new Set(['get', 'post', 'put', 'patch', 'delete', 'head', 'options']);
const API_ENDPOINT = /^\/api\/(?!\/)[A-Za-z0-9_./{}:-]+$/;
const collectors = new WeakMap();

export function isApiFailureDiagnosticPayload(payload) {
    if (!payload || typeof payload !== 'object' || Array.isArray(payload)) return false;
    const keys = Object.keys(payload).sort();
    if (keys.length !== REPORT_KEYS.length || keys.some((key, index) => key !== REPORT_KEYS[index])) return false;
    if (typeof payload.method !== 'string' || !HTTP_METHODS.has(payload.method.toLowerCase()) || payload.method.length > 12) return false;
    if (typeof payload.endpoint !== 'string' || payload.endpoint.length > 500 || !API_ENDPOINT.test(payload.endpoint)) return false;
    if (payload.status !== null && (!Number.isInteger(payload.status) || payload.status < 400 || payload.status > 599)) return false;
    if (payload.message !== null && (typeof payload.message !== 'string' || payload.message.length > 500)) return false;
    if (payload.request_id !== null && (typeof payload.request_id !== 'string' || payload.request_id.length > 128)) return false;
    return true;
}

export function createApiFailureDiagnosticCollector(page, origin = 'http://127.0.0.1:4177') {
    assert.equal(new URL(origin).origin, origin, 'The diagnostic boundary needs an exact origin.');
    const collector = { origin, receipts: [], violations: [], failures: [], pending: new Set(), observed: new Map(), waiters: new Set() };
    collectors.set(page, collector);
    page.on('request', request => {
        let url;
        try { url = new URL(request.url()); } catch { return; }
        if (url.origin === origin && url.pathname === REPORT_PATH) collector.observed.set(request, { settled: false, failed: false });
    });
    const settle = (request, failed) => {
        const observed = collector.observed.get(request);
        if (!observed) return;
        observed.settled = true;
        observed.failed = failed;
        collector.waiters.forEach(resolve => resolve());
        collector.waiters.clear();
    };
    page.on('requestfinished', request => settle(request, false));
    page.on('requestfailed', request => settle(request, true));
    return collector;
}

async function hasTerminalReceipt(request) {
    try {
        const response = await request.response();
        if (!response || await response.finished() !== null || response.status() !== 202) return false;
        return isDeepStrictEqual(await response.json(), { data: { accepted: true } });
    } catch {
        return false;
    }
}

/**
 * Call from a journey's existing catch-all guard. Only the exact same-origin
 * diagnostic endpoint is handled; all other requests fall through unchanged.
 */
export async function handleApiFailureDiagnostic(route, { origin, collector }) {
    assert.equal(collector.origin, origin, 'The diagnostic handler and collector origins must match.');
    const request = route.request();
    let url;
    try {
        url = new URL(request.url());
    } catch {
        return false;
    }

    if (url.pathname !== REPORT_PATH || url.origin !== origin) return false;

    const violation = reason => {
        collector.violations.push(reason);
    };
    let payload;
    try {
        payload = request.postDataJSON();
    } catch {
        payload = null;
    }
    if (url.username || url.password || url.search || request.method() !== 'POST' || !isApiFailureDiagnosticPayload(payload)) {
        violation('Malformed API failure diagnostic request.');
        try {
            await route.abort();
        } catch {
            collector.failures.push('Could not abort malformed API failure diagnostic request.');
        }
        return true;
    }

    const terminal = (async () => {
        await route.fulfill({ status: 202, contentType: 'application/json', body: JSON.stringify({ data: { accepted: true } }) });
        if (!await hasTerminalReceipt(request)) throw new Error('terminal receipt proof failed');
        // Do not retain message or request_id values after wire validation.
        collector.receipts.push({ method: payload.method, endpoint: payload.endpoint, status: payload.status, responseStatus: 202, finished: true, accepted: true });
    })();
    collector.pending.add(terminal);
    try {
        await terminal;
    } catch {
        collector.failures.push('API failure diagnostic did not receive its verified terminal receipt.');
    } finally {
        collector.pending.delete(terminal);
    }
    return true;
}

export async function installApiFailureDiagnosticBoundary(page, options) {
    assert.equal(options.collector.origin, options.origin, 'The diagnostic handler and collector origins must match.');
    await page.route('**/*', async route => {
        if (await handleApiFailureDiagnostic(route, options)) return;
        await route.fallback();
    });
}

export async function verifyApiFailureDiagnostics(page) {
    const collector = collectors.get(page);
    if (!collector) return;
    while (collector.pending.size || [...collector.observed.values()].some(request => !request.settled)) {
        const pending = [...collector.pending];
        if (pending.length) await Promise.all(pending);
        if ([...collector.observed.values()].some(request => !request.settled)) await new Promise(resolve => collector.waiters.add(resolve));
    }
    for (const [request, observed] of collector.observed) {
        if (observed.failed || !await hasTerminalReceipt(request)) collector.failures.push('Observed diagnostic request did not receive the exact terminal 202 receipt.');
    }
    assert.deepEqual(collector.violations, [], 'Malformed API failure diagnostics were observed.');
    assert.deepEqual(collector.failures, [], 'API failure diagnostic terminal proof failed.');
    assert.equal(collector.pending.size, 0, 'API failure diagnostic requests are still pending.');
    for (const receipt of collector.receipts) {
        assert.equal(receipt.responseStatus, 202);
        assert.equal(receipt.finished, true);
        assert.equal(receipt.accepted, true);
    }
}

export function registerApiFailureDiagnosticAfterEach(test) {
    test.afterEach(async ({ page }) => verifyApiFailureDiagnostics(page));
}
