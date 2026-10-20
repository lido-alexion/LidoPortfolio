import { describe, expect, it } from 'vitest';
import { createApiFailureDiagnosticCollector, handleApiFailureDiagnosticForPage, installApiFailureDiagnosticBoundary, isApiFailureDiagnosticPayload, verifyApiFailureDiagnostics } from '../../e2e/apiFailureDiagnosticBoundary.js';

function makeBoundary() {
    let handler;
    const listeners = new Map();
    const page = {
        route: async (pattern, callback) => { expect(pattern).toBe('**/*'); handler = callback; },
        on: (event, callback) => { const set = listeners.get(event) || new Set(); set.add(callback); listeners.set(event, set); },
        emit: (event, request) => (listeners.get(event) || []).forEach(callback => callback(request)),
    };
    const collector = createApiFailureDiagnosticCollector(page);
    const install = installApiFailureDiagnosticBoundary(page, { origin: 'http://127.0.0.1:4177', collector });
    return { install, page, collector, dispatch: async ({ url, method = 'POST', payload }) => {
        let action = null;
        const response = {
            finished: async () => null,
            status: () => 202,
            json: async () => ({ data: { accepted: true } }),
        };
        const request = {
            url: () => url,
            method: () => method,
            postDataJSON: () => payload,
            response: async () => action?.kind === 'fulfilled' ? response : null,
        };
        const route = {
            request: () => request,
            fulfill: async options => { action = { kind: 'fulfilled', options }; page.emit('requestfinished', request); },
            abort: async () => { action = { kind: 'aborted' }; page.emit('requestfailed', request); },
            fallback: async () => {
                const parsed = new URL(url);
                const write = /^(POST|PUT|PATCH|DELETE)$/.test(method);
                action = parsed.origin !== 'http://127.0.0.1:4177' || write && parsed.pathname !== '/api/logs/frontend'
                    ? { kind: 'aborted-by-existing-write-guard' }
                    : { kind: 'fallback' };
                page.emit(action.kind === 'aborted-by-existing-write-guard' ? 'requestfailed' : 'requestfinished', request);
            },
        };
        page.emit('request', request);
        await handler(route);
        return action;
    } };
}

const report = {
    method: 'get',
    endpoint: '/api/transactions?scope=private',
    status: 503,
    message: 'Request failed with status code 503',
    request_id: 'synthetic-request-123',
};

describe('E2E API failure diagnostic boundary', () => {
    it('accepts only the bounded controller payload and returns a terminal local receipt', async () => {
        expect(isApiFailureDiagnosticPayload(report)).toBe(false);
        const valid = { ...report, endpoint: '/api/transactions' };
        expect(isApiFailureDiagnosticPayload(valid)).toBe(true);
        const boundary = makeBoundary();
        await boundary.install;
        const action = await boundary.dispatch({ url: 'http://127.0.0.1:4177/api/ops/api-failures', payload: valid });
        expect(action).toEqual({ kind: 'fulfilled', options: { status: 202, contentType: 'application/json', body: '{"data":{"accepted":true}}' } });
        expect(boundary.collector.receipts).toEqual([{ method: 'get', endpoint: '/api/transactions', status: 503, responseStatus: 202, finished: true, accepted: true }]);
        await verifyApiFailureDiagnostics(boundary.page);
    });

    it('aborts a malformed diagnostic and fails the final verification barrier', async () => {
        const boundary = makeBoundary();
        await boundary.install;
        const malformed = { ...report, endpoint: '/api/transactions', extra: 'must not pass' };
        expect(await boundary.dispatch({ url: 'http://127.0.0.1:4177/api/ops/api-failures', payload: malformed })).toEqual({ kind: 'aborted' });
        expect(boundary.collector.receipts).toEqual([]);
        expect(boundary.collector.violations).toEqual(['Malformed API failure diagnostic request.']);
        await expect(verifyApiFailureDiagnostics(boundary.page)).rejects.toThrow('Malformed API failure diagnostics were observed.');
    });

    it('matches the bounded client payload subset for status, endpoint, message, and request ID', () => {
        const valid = { ...report, endpoint: '/api/transactions' };
        expect(isApiFailureDiagnosticPayload({ ...valid, status: 400 })).toBe(true);
        expect(isApiFailureDiagnosticPayload({ ...valid, status: 599 })).toBe(true);
        expect(isApiFailureDiagnosticPayload({ ...valid, status: null })).toBe(true);
        expect(isApiFailureDiagnosticPayload({ ...valid, status: 399 })).toBe(false);
        expect(isApiFailureDiagnosticPayload({ ...valid, status: 600 })).toBe(false);
        expect(isApiFailureDiagnosticPayload({ ...valid, endpoint: '/api/transactions?scope=private' })).toBe(false);
        expect(isApiFailureDiagnosticPayload({ ...valid, message: 'x'.repeat(500) })).toBe(true);
        expect(isApiFailureDiagnosticPayload({ ...valid, message: 'x'.repeat(501) })).toBe(false);
        expect(isApiFailureDiagnosticPayload({ ...valid, request_id: 'x'.repeat(129) })).toBe(false);
    });

    it('handles a diagnostic directly inside a fixture guard and verifies its terminal receipt', async () => {
        const listeners = new Map();
        const page = {
            on: (event, callback) => { const set = listeners.get(event) || new Set(); set.add(callback); listeners.set(event, set); },
            emit: (event, request) => (listeners.get(event) || []).forEach(callback => callback(request)),
        };
        createApiFailureDiagnosticCollector(page);
        const valid = { ...report, endpoint: '/api/transactions' };
        const response = { finished: async () => null, status: () => 202, json: async () => ({ data: { accepted: true } }) };
        let action = null;
        const request = {
            url: () => 'http://127.0.0.1:4177/api/ops/api-failures',
            method: () => 'POST',
            postDataJSON: () => valid,
            response: async () => action === 'fulfilled' ? response : null,
        };
        const route = {
            request: () => request,
            fulfill: async options => { action = 'fulfilled'; expect(options.status).toBe(202); page.emit('requestfinished', request); },
            abort: async () => { action = 'aborted'; },
        };
        page.emit('request', request);
        expect(await handleApiFailureDiagnosticForPage(route, page, 'http://127.0.0.1:4177')).toBe(true);
        expect(action).toBe('fulfilled');
        await verifyApiFailureDiagnostics(page);
    });

    it('leaves unexpected business POSTs and foreign diagnostics to the existing guard', async () => {
        const boundary = makeBoundary();
        await boundary.install;
        expect(await boundary.dispatch({ url: 'http://127.0.0.1:4177/api/transactions', payload: {} })).toEqual({ kind: 'aborted-by-existing-write-guard' });
        expect(await boundary.dispatch({ url: 'https://example.test/api/ops/api-failures', payload: { ...report, endpoint: '/api/transactions' } })).toEqual({ kind: 'aborted-by-existing-write-guard' });
        expect(boundary.collector.receipts).toEqual([]);
    });
});
