/**
 * Centralized frontend logger. Do not use console.log directly in app code.
 * Important warn/error events are sent to POST /api/logs/frontend (async, non-blocking).
 */

import { appUrl } from '../appBase';

const LEVELS = { debug: 0, info: 1, warn: 2, error: 3 };
const BACKEND_LEVELS = new Set(['warn', 'error']);
const STORAGE_KEY = 'logLevel';
const MAX_QUEUE = 20;

let queue = [];
let flushTimer = null;

function currentLevel() {
    const stored = (localStorage.getItem(STORAGE_KEY) || 'info').toLowerCase();
    return LEVELS[stored] ?? LEVELS.info;
}

function shouldLog(level) {
    return LEVELS[level] >= currentLevel();
}

function writeToConsole(level, message, extra) {
    const fn = level === 'error' ? console.error
        : level === 'warn' ? console.warn
            : level === 'info' ? console.info
                : console.debug;
    if (extra !== undefined) {
        fn(`[${level.toUpperCase()}] ${message}`, extra);
    } else {
        fn(`[${level.toUpperCase()}] ${message}`);
    }
}

function sanitizeMessage(message) {
    return String(message)
        .replace(/(password|token|secret|authorization)=["']?[^"'\s]+/gi, '$1=[REDACTED]')
        .slice(0, 2000);
}

function enqueueBackend(level, message, extra = {}) {
    if (!BACKEND_LEVELS.has(level)) {
        return;
    }

    if (!document.cookie.includes('XSRF-TOKEN') && !document.cookie.includes('-session')) {
        return;
    }

    queue.push({
        level,
        message: sanitizeMessage(message),
        url: window.location.pathname,
        userAgent: navigator.userAgent,
        timestamp: new Date().toISOString(),
        requestId: extra.requestId || null,
        extra: {
            category: extra.category || 'UI',
            ...extra,
        },
    });

    if (queue.length > MAX_QUEUE) {
        queue = queue.slice(-MAX_QUEUE);
    }

    if (!flushTimer) {
        flushTimer = setTimeout(flushBackendQueue, 300);
    }
}

async function flushBackendQueue() {
    flushTimer = null;
    if (queue.length === 0) {
        return;
    }

    const batch = queue.splice(0, MAX_QUEUE);

    for (const entry of batch) {
        try {
            const csrfMatch = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
            const headers = {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-Request-ID': entry.requestId || crypto.randomUUID?.() || String(Date.now()),
            };
            if (csrfMatch) {
                headers['X-XSRF-TOKEN'] = decodeURIComponent(csrfMatch[1]);
            }

            await fetch(appUrl('/api/logs/frontend'), {
                method: 'POST',
                headers,
                credentials: 'include',
                body: JSON.stringify(entry),
                keepalive: true,
            });
        } catch {
            // Avoid recursive logging loops; local console only.
            writeToConsole('debug', 'Failed to ship frontend log to backend', entry);
        }
    }
}

function log(level, message, extra) {
    if (!shouldLog(level)) {
        return;
    }
    writeToConsole(level, message, extra);
    enqueueBackend(level, message, extra);
}

export const logger = {
    debug: (message, extra) => log('debug', message, extra),
    info: (message, extra) => log('info', message, extra),
    warn: (message, extra) => log('warn', message, extra),
    error: (message, extra) => log('error', message, extra),
    setLevel: (level) => localStorage.setItem(STORAGE_KEY, level),
    getLevel: () => localStorage.getItem(STORAGE_KEY) || 'info',
};

export function createRequestId() {
    if (typeof crypto !== 'undefined' && crypto.randomUUID) {
        return crypto.randomUUID();
    }
    return `req-${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

export default logger;
