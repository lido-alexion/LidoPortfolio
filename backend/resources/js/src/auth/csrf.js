import axios from 'axios';
import { appUrl } from '../appBase';

let csrfReady = false;

/**
 * Prime Laravel Sanctum CSRF cookie (HTTP-only XSRF-TOKEN) before state-changing requests.
 */
export async function ensureCsrfCookie() {
    if (csrfReady) {
        return;
    }

    await axios.get('/sanctum/csrf-cookie', {
        baseURL: appUrl('/'),
        withCredentials: true,
        headers: { Accept: 'application/json' },
    });

    csrfReady = true;
}

export function resetCsrfCookie() {
    csrfReady = false;
}
