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
        }),
    };
});

vi.mock('@opentelemetry/exporter-trace-otlp-http', () => ({
    OTLPTraceExporter: exporter,
}));
vi.mock('@opentelemetry/instrumentation-fetch', () => ({
    FetchInstrumentation: instrumentation,
}));
vi.mock('@opentelemetry/sdk-trace-web', () => ({
    WebTracerProvider: provider,
}));
vi.mock('@opentelemetry/sdk-trace-base', () => ({
    BatchSpanProcessor: vi.fn(),
}));

describe('browser OpenTelemetry failure hooks', () => {
    it('registers uncaught exception and rejection hooks without exporting secrets', async () => {
        vi.stubEnv('VITE_LIDO_TELEMETRY_ENABLED', 'true');
        vi.stubEnv('VITE_LIDO_TELEMETRY_OTLP_TRACES_ENDPOINT', 'https://telemetry.example.test/v1/traces');

        const addEventListener = vi.spyOn(window, 'addEventListener');
        const { registerBrowserOpenTelemetry } = await import('../../../resources/js/src/telemetry/otelBrowser.js');

        expect(registerBrowserOpenTelemetry()).toBe(true);
        expect(provider).toHaveBeenCalledOnce();
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
        expect(firstAttributes['error.message']).not.toContain('super-secret');
        expect(firstAttributes['error.message']).not.toContain('abc123');
        expect(secondAttributes['error.message']).not.toContain('top-secret-value');
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
