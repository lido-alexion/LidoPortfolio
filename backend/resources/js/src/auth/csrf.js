import axios from 'axios';
import { appUrl } from '../appBase';

let csrfReady = false;
let csrfTokenMemory = null;

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

async function waitForCsrfToken(maxAttempts = 20, delayMs = 50) {
    for (let attempt = 0; attempt < maxAttempts; attempt += 1) {
        const token = readCsrfTokenFromDocument();
        if (token) {
            csrfTokenMemory = token;
            return token;
        }
        await new Promise((resolve) => {
            setTimeout(resolve, delayMs);
        });
    }
    return null;
}

/** Token for X-XSRF-TOKEN header (memory first, then document.cookie). */
export function getRequestCsrfToken() {
    if (csrfTokenMemory) {
        return csrfTokenMemory;
    }
    const fromCookie = readCsrfTokenFromDocument();
    if (fromCookie) {
        csrfTokenMemory = fromCookie;
    }
    return fromCookie;
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
    if (!force && csrfReady && getRequestCsrfToken()) {
        return;
    }

    csrfReady = false;
    csrfTokenMemory = null;

    const url = appUrl('/sanctum/csrf-cookie');

    await axios.get(url, {
        withCredentials: true,
        headers: { Accept: 'application/json' },
    });

    const token = await waitForCsrfToken();
    if (!token) {
        throw new Error(
            `Could not read CSRF cookie after ${url}. `
            + 'Confirm APP_URL includes /portfolio, run config:cache on the server, '
            + 'and check DevTools → Application → Cookies for XSRF-TOKEN under /portfolio.',
        );
    }

    csrfReady = true;
}

export function resetCsrfCookie() {
    csrfReady = false;
    csrfTokenMemory = null;
}
