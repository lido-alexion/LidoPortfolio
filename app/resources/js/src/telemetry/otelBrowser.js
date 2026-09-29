import { OTLPTraceExporter } from '@opentelemetry/exporter-trace-otlp-http';
import { SpanStatusCode } from '@opentelemetry/api';
import { FetchInstrumentation } from '@opentelemetry/instrumentation-fetch';
import { resourceFromAttributes } from '@opentelemetry/resources';
import { BatchSpanProcessor } from '@opentelemetry/sdk-trace-base';
import { WebTracerProvider } from '@opentelemetry/sdk-trace-web';

let registered = false;

const MAX_ATTRIBUTE_LENGTH = 160;

function bounded(value, fallback) {
    const normalized = String(value ?? '').trim().replace(/[\u0000-\u001f\u007f]/g, ' ');
    return (normalized || fallback).slice(0, MAX_ATTRIBUTE_LENGTH);
}

function redactSensitiveText(value) {
    return bounded(value, 'unknown')
        .replace(/\bBearer\s+[A-Za-z0-9._~+/=-]+/gi, 'Bearer [REDACTED]')
        .replace(/\b(?:password|passwd|secret|token|api[_-]?key|authorization)\s*[:=]\s*[^\s,;]+/gi, '$1=[REDACTED]')
        .replace(/([?&](?:token|secret|api[_-]?key|password)=)[^&\s]+/gi, '$1[REDACTED]');
}

/**
 * Keep browser failure attributes safe for export. Do not attach the raw
 * Error object: its stack and message can contain request data or user input.
 */
export function browserFailureAttributes(kind, reason) {
    const errorType = reason instanceof Error
        ? reason.name
        : (reason?.constructor?.name || typeof reason);
    const message = reason instanceof Error ? reason.message : reason;
    const path = typeof window !== 'undefined' ? window.location?.pathname : '';

    return {
        'stox.browser.failure_kind': bounded(kind, 'unknown'),
        'error.type': bounded(errorType, 'UnknownError'),
        'error.message': redactSensitiveText(message),
        'url.path': bounded(path, '/'),
    };
}

function recordBrowserFailure(tracer, kind, reason) {
    try {
        const span = tracer.startSpan(`stox.browser.${kind}`);
        span.setAttributes(browserFailureAttributes(kind, reason));
        span.setStatus({ code: SpanStatusCode.ERROR });
        span.end();
    } catch {
        // Error telemetry must never become an application error path.
    }
}

/**
 * Enable standard browser fetch instrumentation only with an explicit OTLP
 * endpoint. Manual route/business telemetry remains the privacy-filtered
 * event path; this adds automatic network spans without tracing telemetry.
 */
export function registerBrowserOpenTelemetry() {
    if (registered || typeof window === 'undefined' || typeof document === 'undefined') {
        return false;
    }

    const enabled = import.meta.env.VITE_LIDO_TELEMETRY_ENABLED === 'true'
        || import.meta.env.VITE_LIDO_TELEMETRY_ENABLED === '1';
    const endpoint = String(import.meta.env.VITE_LIDO_TELEMETRY_OTLP_TRACES_ENDPOINT || '').trim();
    if (!enabled || !endpoint) {
        return false;
    }

    try {
        const exporter = new OTLPTraceExporter({ url: endpoint });
        const provider = new WebTracerProvider({
            resource: resourceFromAttributes({
                'service.name': import.meta.env.VITE_LIDO_TELEMETRY_SERVICE_NAME || 'stox',
                'service.version': import.meta.env.VITE_LIDO_TELEMETRY_SERVICE_VERSION || 'v8',
                'deployment.environment.name': import.meta.env.VITE_LIDO_TELEMETRY_ENVIRONMENT || 'production',
            }),
            spanProcessors: [new BatchSpanProcessor(exporter)],
        });

        provider.register();
        const instrumentation = new FetchInstrumentation({
            ignoreUrls: [
                /\/api\/telemetry(?:\/|$)/,
                /\/api\/logs\/frontend(?:\/|$)/,
            ],
            clearTimingResources: true,
            ignoreNetworkEvents: true,
        });
        instrumentation.enable();
        const tracer = provider.getTracer('stox.browser', 'v8');
        window.addEventListener('error', (event) => {
            recordBrowserFailure(tracer, 'uncaught_exception', event?.error || event?.message);
        });
        window.addEventListener('unhandledrejection', (event) => {
            recordBrowserFailure(tracer, 'unhandled_rejection', event?.reason);
        });
        registered = true;
        return true;
    } catch {
        // Telemetry must never prevent the StoX application from starting.
        return false;
    }
}
