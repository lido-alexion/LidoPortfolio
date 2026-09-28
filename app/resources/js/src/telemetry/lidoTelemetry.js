import { appUrl } from '../appBase';

function randomHex(bytes) {
    const arr = new Uint8Array(bytes);
    crypto.getRandomValues(arr);
    return Array.from(arr, (b) => b.toString(16).padStart(2, '0')).join('');
}

let activeTrace = null;
let routeState = null;
let hiddenStartedAt = null;
let visibilityListenerAttached = false;

function onVisibilityChange() {
    if (!routeState) {
        return;
    }
    if (document.hidden) {
        hiddenStartedAt = performance.now();
        return;
    }
    if (hiddenStartedAt !== null) {
        routeState.hiddenMs += performance.now() - hiddenStartedAt;
        hiddenStartedAt = null;
    }
}

function attachVisibilityListener() {
    if (visibilityListenerAttached || typeof document === 'undefined') {
        return;
    }
    document.addEventListener('visibilitychange', onVisibilityChange);
    visibilityListenerAttached = true;
}

export function isTelemetryEnabled() {
    return import.meta.env.VITE_LIDO_TELEMETRY_ENABLED === 'true'
        || import.meta.env.VITE_LIDO_TELEMETRY_ENABLED === '1';
}

export function getTraceparent() {
    if (!isTelemetryEnabled()) {
        return null;
    }
    if (!activeTrace) {
        activeTrace = {
            traceId: randomHex(16),
            spanId: randomHex(8),
        };
    }
    return `00-${activeTrace.traceId}-${activeTrace.spanId}-01`;
}

async function flushRouteView() {
    if (!isTelemetryEnabled() || !routeState) {
        return;
    }

    const wallMs = Math.round(performance.now() - routeState.startedAt);
    let hiddenMs = routeState.hiddenMs;
    if (hiddenStartedAt !== null) {
        hiddenMs += performance.now() - hiddenStartedAt;
    }
    const activeMs = Math.max(0, wallMs - Math.round(hiddenMs));
    const payload = {
        route: routeState.route,
        wall_duration_ms: wallMs,
        active_duration_ms: activeMs,
    };

    routeState = null;
    hiddenStartedAt = null;

    try {
        const headers = {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        };
        const traceparent = getTraceparent();
        if (traceparent) {
            headers.traceparent = traceparent;
        }
        await fetch(appUrl('/api/telemetry/route-view'), {
            method: 'POST',
            credentials: 'include',
            headers,
            body: JSON.stringify(payload),
            keepalive: true,
        });
    } catch {
        // Fail-open per FEAT-052.
    }
}

export function recordRouteView(pathname) {
    if (!isTelemetryEnabled() || !pathname) {
        return;
    }

    void flushRouteView();
    attachVisibilityListener();

    activeTrace = {
        traceId: randomHex(16),
        spanId: randomHex(8),
        route: pathname,
        startedAt: performance.now(),
    };

    routeState = {
        route: pathname,
        startedAt: performance.now(),
        hiddenMs: 0,
    };
}
