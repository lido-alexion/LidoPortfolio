import { OTLPTraceExporter } from '@opentelemetry/exporter-trace-otlp-http';
import { SpanStatusCode } from '@opentelemetry/api';
import { XMLHttpRequestInstrumentation } from '@opentelemetry/instrumentation-xml-http-request';
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

/**
 * Keep browser failure attributes safe for export. Do not attach the raw
 */
export function browserFailureAttributes(kind, reason) {
    const errorType = reason instanceof Error
        ? reason.name
        : (reason?.constructor?.name || typeof reason);
    const path = typeof window !== 'undefined' ? window.location?.pathname : '';

    return {
        'stox.browser.failure_kind': bounded(kind, 'unknown'),
        'error.type': bounded(errorType, 'UnknownError'),
        'stox.browser.reason_present': Boolean(reason),
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

// Sanitize at the exporter boundary: instrumentation can add attributes after
// its custom-attributes hook (including full URLs and error descriptions).
export function privacySafeBrowserSpan(span) {
    const attributes = {};
    for (const key of [
        'http.request.method', 'http.method', 'http.response.status_code', 'http.status_code',
        'stox.browser.failure_kind', 'stox.browser.reason_present', 'error.type',
    ]) {
        if (span.attributes[key] !== undefined) attributes[key] = span.attributes[key];
    }
    // Omit URL paths too: invite and reset routes can carry secret tokens.
    // Preserve SDK getters and identifiers without mutating the ended span.
    return Object.create(span, {
        attributes: { value: attributes },
        events: { value: [] },
        links: { value: [] },
        status: { value: { code: span.status.code } },
    });
}

export function isBrowserOpenTelemetryRegistered() {
    return registered;
}

/**
 * Export standard fetch/XHR spans via the configured JSON OTLP relay.
 * Manual route/business telemetry retains its existing independent lifecycle.
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

    const instrumentations = [];
    let provider;
    try {
        const exporter = new OTLPTraceExporter({ url: endpoint });
        const safeExporter = {
            export: (spans, callback) => exporter.export(spans.map(privacySafeBrowserSpan), callback),
            shutdown: () => exporter.shutdown(),
        };
        provider = new WebTracerProvider({
            resource: resourceFromAttributes({
                'service.name': import.meta.env.VITE_LIDO_TELEMETRY_SERVICE_NAME || 'stox',
                'service.version': import.meta.env.VITE_LIDO_TELEMETRY_SERVICE_VERSION
                    || import.meta.env.VITE_BUILD_ID
                    || 'local',
                'deployment.environment.name': import.meta.env.VITE_LIDO_TELEMETRY_ENVIRONMENT || 'production',
            }),
            spanProcessors: [new BatchSpanProcessor(safeExporter)],
        });

        provider.register();
        const config = {
            enabled: false,
            ignoreUrls: [
                /\/api\/telemetry(?:[/?#]|$)/,
                /\/api\/logs\/frontend(?:[/?#]|$)/,
                endpoint,
            ],
            // Same-origin propagation is automatic; never opt third parties in.
            propagateTraceHeaderCorsUrls: [],
            clearTimingResources: true,
            ignoreNetworkEvents: true,
        };
        instrumentations.push(new FetchInstrumentation(config));
        instrumentations.push(new XMLHttpRequestInstrumentation(config));
        for (const instrumentation of instrumentations) {
            instrumentation.setTracerProvider(provider);
            instrumentation.enable();
        }
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
        for (const instrumentation of instrumentations) {
            try { instrumentation.disable(); } catch { /* Fail open. */ }
        }
        try { void provider?.shutdown()?.catch(() => {}); } catch { /* Fail open. */ }
        // Telemetry must never prevent the StoX application from starting.
        return false;
    }
}
