/**
 * App URL path prefix when hosted in a subdirectory (e.g. /portfolio).
 * Set via <meta name="app-base"> in app.blade.php from APP_URL.
 */
export function getAppBase() {
    const meta = document.querySelector('meta[name="app-base"]');
    const raw = meta?.getAttribute('content') ?? '';
    const base = raw.replace(/\/$/, '');
    return base === '/' ? '' : base;
}

export function appUrl(path = '') {
    const base = getAppBase();
    const suffix = path.startsWith('/') ? path : `/${path}`;
    return `${base}${suffix}` || '/';
}
