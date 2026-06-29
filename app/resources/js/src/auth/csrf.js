import axios from 'axios';
import { appUrl } from '../appBase';

let csrfReady = false;
let csrfTokenMemory = null;
/** @type {'cookie' | 'plain' | null} */
let csrfTokenSource = null;

function readCsrfTokenFromDocument() {
    let token = null;
    for (const part of document.cookie.split(';')) {
        const cookie = part.trim();
        if (cookie.startsWith('XSRF-TOKEN=')) {
            token = decodeURIComponent(cookie.slice('XSRF-TOKEN='.length));
        }
    }
    return token;
}

function clearLegacyCsrfCookies() {
    const base = appUrl('').replace(/\/$/, '') || '';
    const paths = [...new Set(['/', base].filter(Boolean))];
    const host = window.location.hostname.replace(/^www\./i, '');
    const domainVariants = ['', host, `.${host}`, `www.${host}`];

    for (const path of paths) {
        for (const domain of domainVariants) {
            const domainPart = domain ? `; domain=${domain}` : '';
            document.cookie = `XSRF-TOKEN=; Max-Age=0; path=${path}${domainPart}`;
        }
    }
}

async function waitForCsrfToken(maxAttempts = 20, delayMs = 50) {
    for (let attempt = 0; attempt < maxAttempts; attempt += 1) {
        const token = readCsrfTokenFromDocument();
        if (token) {
            csrfTokenMemory = token;
            csrfTokenSource = 'cookie';
            return token;
        }
        await new Promise((resolve) => {
            setTimeout(resolve, delayMs);
        });
    }
    return null;
}

async function fetchPlainCsrfToken() {
    const response = await axios.get(appUrl('/api/auth/csrf-token'), {
        withCredentials: true,
        headers: { Accept: 'application/json' },
    });
    const token = response?.data?.token;
    if (!token || typeof token !== 'string') {
        throw new Error('CSRF token missing from /api/auth/csrf-token response.');
    }
    csrfTokenMemory = token;
    csrfTokenSource = 'plain';
    return token;
}

/** Token for CSRF headers (memory first, then document.cookie). */
export function getRequestCsrfToken() {
    if (csrfTokenMemory) {
        return csrfTokenMemory;
    }
    const fromCookie = readCsrfTokenFromDocument();
    if (fromCookie) {
        csrfTokenMemory = fromCookie;
        csrfTokenSource = 'cookie';
    }
    return fromCookie;
}

/** True when token came from API (send X-CSRF-TOKEN, not X-XSRF-TOKEN). */
export function isPlainCsrfToken() {
    return csrfTokenSource === 'plain';
}

/** @deprecated use getRequestCsrfToken */
export function readCsrfToken() {
    return getRequestCsrfToken();
}

/**
 * Prime Laravel Sanctum CSRF cookie before state-changing requests.
 * @param {{ force?: boolean }} options - force=true always refetches (use before login/register).
 */
export async function ensureCsrfCookie({ force = false } = {}) {
    if (!force && csrfReady && csrfTokenMemory && csrfTokenSource === 'plain') {
        return;
    }

    csrfReady = false;
    csrfTokenMemory = null;
    csrfTokenSource = null;

    if (force) {
        clearLegacyCsrfCookies();
    }

    const sanctumUrl = appUrl('/sanctum/csrf-cookie');

    await axios.get(sanctumUrl, {
        withCredentials: true,
        headers: { Accept: 'application/json' },
    });

    // Always read the session token from the API after sanctum. Mobile browsers can
    // still expose a stale XSRF-TOKEN at path / alongside the fresh /portfolio cookie.
    try {
        await fetchPlainCsrfToken();
    } catch (error) {
        const token = await waitForCsrfToken();
        if (!token) {
            const hint = `Use ${window.location.origin}${appUrl('/')} consistently (www or non-www), `
                + 'clear site cookies for lidoalexion.com, then retry.';
            throw new Error(`Could not read CSRF token after ${sanctumUrl}. ${hint}`);
        }
    }

    csrfReady = true;
}

export function resetCsrfCookie() {
    csrfReady = false;
    csrfTokenMemory = null;
    csrfTokenSource = null;
}
