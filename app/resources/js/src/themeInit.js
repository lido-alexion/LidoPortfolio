const STORAGE_KEY = 'lido-theme';

export function resolveThemePreference(preference) {
    if (preference === 'light' || preference === 'dark') {
        return preference;
    }
    if (preference === 'system') {
        return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }
    return 'dark';
}

export function readStoredThemePreference() {
    const stored = localStorage.getItem(STORAGE_KEY);
    if (stored === 'light' || stored === 'dark' || stored === 'system') {
        return stored;
    }
    return 'dark';
}

export function applyResolvedTheme(resolved) {
    document.documentElement.setAttribute('data-theme', resolved);
    const meta = document.querySelector('meta[name="theme-color"]');
    if (meta) {
        meta.setAttribute('content', resolved === 'light' ? '#f3f4f6' : '#000000');
    }
}

export function initTheme() {
    const preference = readStoredThemePreference();
    applyResolvedTheme(resolveThemePreference(preference));
}

initTheme();

export { STORAGE_KEY };
