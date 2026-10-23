import { appUrl } from '../appBase';

const MAX_QUEUE = 8;
const DEDUPE_MS = 60_000;
const seen = new Map();
let queue = [];
let timer = null;
let registered = false;

function enqueue(type) {
    try {
        if (!['react_render', 'uncaught_exception', 'unhandled_rejection'].includes(type)) return;
        const now = Date.now();
        if (seen.has(type) && now - seen.get(type) < DEDUPE_MS) return;
        seen.set(type, now);
        if (seen.size > 8) seen.delete(seen.keys().next().value);
        if (queue.length >= MAX_QUEUE) queue.shift();
        queue.push(type);
        if (!timer) timer = setTimeout(flush, 250);
    } catch { /* diagnostics must never replace the original application failure */ }
}

async function flush() {
    timer = null;
    const batch = queue.splice(0, MAX_QUEUE);
    if (!batch.length) return;
    let csrf = null;
    try {
        const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
        if (match) csrf = decodeURIComponent(match[1]);
    } catch { /* malformed cookie: request will fail closed at auth/CSRF middleware */ }

    for (const type of batch) {
        try {
            await fetch(appUrl('/api/ops/browser-errors'), {
                method: 'POST', credentials: 'include', keepalive: true,
                headers: {
                    'Content-Type': 'application/json', Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-StoX-Skip-Api-Failure-Reporting': '1',
                    ...(csrf ? { 'X-XSRF-TOKEN': csrf } : {}),
                },
                body: JSON.stringify({ type }),
            });
        } catch { /* no logging or retry; reporter failures cannot recurse */ }
    }
    if (queue.length && !timer) timer = setTimeout(flush, 250);
}

function isExpectedRejection(reason) {
    if (!(reason instanceof Error)) return true; // arbitrary strings/objects are not trusted evidence
    if (reason.name === 'AbortError' || reason.name === 'SecurityError'
        || reason.name === 'NotAllowedError' || reason.name === 'ValidationError'
        || reason.name === 'AuthenticationError' || reason.code === 'ERR_CANCELED'
        || reason.isAxiosError === true) return true;
    const status = Number(reason.response?.status);
    if (status >= 400 && status < 500) return true;
    return /(?:chrome|moz|safari-web)-extension:\/\//i.test(String(reason.stack || ''));
}

export function registerBrowserErrorReporter() {
    if (registered || typeof window === 'undefined') return false;
    registered = true;
    window.addEventListener('error', (event) => {
        if (event?.target !== window || !(event.error instanceof Error) || !event.filename) return;
        try {
            if (new URL(event.filename, window.location.origin).origin !== window.location.origin) return;
        } catch { return; }
        enqueue('uncaught_exception');
    });
    window.addEventListener('unhandledrejection', (event) => {
        if (isExpectedRejection(event?.reason)) return;
        enqueue('unhandled_rejection');
    });
    return true;
}

export function reportReactRenderFailure() {
    enqueue('react_render');
}
