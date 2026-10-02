import { describe, expect, it, vi } from 'vitest';

const { tracer, provider, exporter, instrumentation } = vi.hoisted(() => {
    const tracer = {
        startSpan: vi.fn(() => ({
            setAttributes: vi.fn(),
            setStatus: vi.fn(),
            end: vi.fn(),
        })),
    };
    return {
        tracer,
        provider: vi.fn(function MockProvider() {
            this.register = vi.fn();
            this.getTracer = vi.fn(() => tracer);
        }),
        exporter: vi.fn(),
        instrumentation: vi.fn(function MockInstrumentation() {
            this.enable = vi.fn();
            this.disable = vi.fn();
            this.setTracerProvider = vi.fn();
        }),
    };
});

vi.mock('@opentelemetry/exporter-trace-otlp-http', () => ({
    OTLPTraceExporter: exporter,
}));
vi.mock('@opentelemetry/instrumentation-fetch', () => ({
    FetchInstrumentation: instrumentation,
}));
vi.mock('@opentelemetry/instrumentation-xml-http-request', () => ({
    XMLHttpRequestInstrumentation: instrumentation,
}));
vi.mock('@opentelemetry/sdk-trace-web', () => ({
    WebTracerProvider: provider,
}));
vi.mock('@opentelemetry/sdk-trace-base', () => ({
    BatchSpanProcessor: vi.fn(),
}));

describe('browser OpenTelemetry failure hooks', () => {
    it('registers uncaught exception and rejection hooks without exporting raw reasons', async () => {
        vi.stubEnv('VITE_LIDO_TELEMETRY_ENABLED', 'true');
        vi.stubEnv('VITE_LIDO_TELEMETRY_OTLP_TRACES_ENDPOINT', 'https://telemetry.example.test/v1/traces');

        const addEventListener = vi.spyOn(window, 'addEventListener');
        const { registerBrowserOpenTelemetry } = await import('../../../resources/js/src/telemetry/otelBrowser.js');

        expect(registerBrowserOpenTelemetry()).toBe(true);
        expect(provider).toHaveBeenCalledOnce();
        expect(instrumentation).toHaveBeenCalledTimes(2);
        for (const result of instrumentation.mock.results) {
            expect(result.value.setTracerProvider).toHaveBeenCalledWith(provider.mock.instances[0]);
            expect(result.value.enable).toHaveBeenCalledOnce();
        }
        expect(registerBrowserOpenTelemetry()).toBe(false);
        expect(tracer.startSpan).not.toHaveBeenCalled();

        const uncaught = addEventListener.mock.calls.find(([name]) => name === 'error')?.[1];
        const rejection = addEventListener.mock.calls.find(([name]) => name === 'unhandledrejection')?.[1];
        expect(uncaught).toEqual(expect.any(Function));
        expect(rejection).toEqual(expect.any(Function));

        uncaught({ error: new Error('authorization=super-secret token=abc123') });
        rejection({ reason: 'Bearer top-secret-value' });

        expect(tracer.startSpan).toHaveBeenCalledTimes(2);
        const firstAttributes = tracer.startSpan.mock.results[0].value.setAttributes.mock.calls[0][0];
        const secondAttributes = tracer.startSpan.mock.results[1].value.setAttributes.mock.calls[0][0];
        expect(firstAttributes['stox.browser.failure_kind']).toBe('uncaught_exception');
        expect(firstAttributes).not.toHaveProperty('error.message');
        expect(secondAttributes).not.toHaveProperty('error.message');
        expect(firstAttributes['stox.browser.reason_present']).toBe(true);
        expect(tracer.startSpan.mock.results[0].value.setStatus).toHaveBeenCalled();
        expect(tracer.startSpan.mock.results[0].value.end).toHaveBeenCalledOnce();
    });

    it('keeps the application fail-open when telemetry setup throws', async () => {
        vi.stubEnv('VITE_LIDO_TELEMETRY_ENABLED', 'true');
        vi.stubEnv('VITE_LIDO_TELEMETRY_OTLP_TRACES_ENDPOINT', 'https://telemetry.example.test/v1/traces');
        exporter.mockImplementationOnce(() => {
            throw new Error('collector unavailable');
        });

        const { registerBrowserOpenTelemetry } = await import('../../../resources/js/src/telemetry/otelBrowser.js?failure');
        expect(registerBrowserOpenTelemetry()).toBe(false);
    });
});

it('removes query values, credentials, headers, events and status messages at export', async () => {
    const { privacySafeBrowserSpan } = await import('../../../resources/js/src/telemetry/otelBrowser.js');
    const span = {
        attributes: {
            'url.full': 'https://user:secret@example.test/invite/secret-path-marker?token=secret#secret',
            'url.path': '/invite/secret-path-marker',
            'http.request.method': 'GET',
            'http.response.status_code': 500,
            'http.request.header.authorization': 'Bearer secret',
            'http.response.header.set_cookie': 'secret',
        },
        events: [{ name: 'exception', attributes: { 'exception.message': 'secret' } }],
        links: [{ attributes: { token: 'secret' } }],
        status: { code: 2, message: 'secret' },
    };
    const safe = privacySafeBrowserSpan(span);
    expect(safe.attributes).toEqual({
        'http.request.method': 'GET', 'http.response.status_code': 500,
    });
    expect(safe.events).toEqual([]);
    expect(safe.links).toEqual([]);
    expect(safe.status).toEqual({ code: 2 });
    expect(span.status.message).toBe('secret');
});

it('disables partially registered instrumentation and keeps manual propagation available', async () => {
    vi.stubEnv('VITE_LIDO_TELEMETRY_ENABLED', 'true');
    vi.stubEnv('VITE_LIDO_TELEMETRY_OTLP_TRACES_ENDPOINT', '/api/telemetry/otlp/traces');
    const disable = vi.fn();
    instrumentation.mockImplementationOnce(function () {
        this.setTracerProvider = vi.fn();
        this.enable = () => { throw new Error('patch failed'); };
        this.disable = disable;
    });
    const telemetry = await import('../../../resources/js/src/telemetry/otelBrowser.js?partial-failure');
    expect(telemetry.registerBrowserOpenTelemetry()).toBe(false);
    expect(telemetry.isBrowserOpenTelemetryRegistered()).toBe(false);
    expect(disable).toHaveBeenCalledOnce();
});

it('does not register when telemetry or the export endpoint is disabled', async () => {
    const telemetry = await import('../../../resources/js/src/telemetry/otelBrowser.js?disabled');
    vi.stubEnv('VITE_LIDO_TELEMETRY_ENABLED', 'false');
    expect(telemetry.registerBrowserOpenTelemetry()).toBe(false);
    vi.stubEnv('VITE_LIDO_TELEMETRY_ENABLED', 'true');
    vi.stubEnv('VITE_LIDO_TELEMETRY_OTLP_TRACES_ENDPOINT', '');
    expect(telemetry.registerBrowserOpenTelemetry()).toBe(false);
    expect(telemetry.isBrowserOpenTelemetryRegistered()).toBe(false);
});
