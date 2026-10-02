// @vitest-environment-options {"url":"http://127.0.0.1:43127/"}
import { createServer } from 'node:http';
import { once } from 'node:events';
import { expect, it, vi } from 'vitest';

vi.unmock('../../../resources/js/src/api');

it('exports actual Axios XHR span IDs matching the single wire traceparent through JSON OTLP', async () => {
    const requests = [];
    const batches = [];
    const server = createServer(async (req, res) => {
        let body = '';
        for await (const chunk of req) body += chunk;
        requests.push({ url: req.url, headers: req.headers });
        if (req.url === '/api/telemetry/otlp/traces') batches.push(JSON.parse(body));
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end('{}');
    });
    server.listen(43127, '127.0.0.1');
    await once(server, 'listening');
    try {
        vi.stubEnv('VITE_LIDO_TELEMETRY_ENABLED', 'true');
        vi.stubEnv('VITE_LIDO_TELEMETRY_OTLP_TRACES_ENDPOINT', '/api/telemetry/otlp/traces');
        const { default: api } = await import('../../../resources/js/src/api');
        const { registerBrowserOpenTelemetry } = await import('../../../resources/js/src/telemetry/otelBrowser');
        const fallback = await api.get('/watchlists', {
            adapter: async (config) => ({ data: config.headers.traceparent, status: 200, config }),
        });
        expect(fallback.data).toMatch(/^00-[a-f0-9]{32}-[a-f0-9]{16}-01$/);
        expect(registerBrowserOpenTelemetry()).toBe(true);
        await api.get('/watchlists?token=private-query-marker', {
            adapter: 'xhr',
            headers: { Traceparent: fallback.data, Tracestate: 'vendor=private-state', Authorization: 'Bearer private-header-marker' },
        });
        await api.get('/watchlists/1/items', { adapter: 'xhr' });
        await api.post('/telemetry/route-view?probe=1', {}, { adapter: 'xhr' });
        await api.post('/logs/frontend?probe=1', {}, { adapter: 'xhr' });
        await vi.waitFor(() => expect(batches.length).toBeGreaterThan(0), { timeout: 10000 });
        const spans = batches.flatMap((batch) => batch.resourceSpans.flatMap((resource) => resource.scopeSpans.flatMap((scope) => scope.spans)));
        expect(spans).toHaveLength(2);
        for (const request of requests.filter((request) => request.url.startsWith('/api/watchlists'))) {
            const header = request.headers.traceparent;
            expect(header).toMatch(/^00-[a-f0-9]{32}-[a-f0-9]{16}-01$/);
            const [, traceId, spanId] = header.split('-');
            expect(spans).toEqual(expect.arrayContaining([expect.objectContaining({ traceId, spanId, kind: 3 })]));
            expect(request.headers.tracestate).toBeUndefined();
        }
        expect(JSON.stringify(batches)).not.toMatch(/private-query-marker|private-header-marker|private-state|test-csrf/);
        expect(requests.find((request) => request.url === '/api/telemetry/otlp/traces').headers['content-type']).toContain('application/json');
        for (const request of requests.filter((request) => /telemetry|logs/.test(request.url))) {
            expect(request.headers.traceparent).toBeUndefined();
        }
    } finally {
        server.closeAllConnections();
        await new Promise((resolve) => server.close(resolve));
        vi.unstubAllEnvs();
    }
});
