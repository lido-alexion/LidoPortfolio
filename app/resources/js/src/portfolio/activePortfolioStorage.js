const STORAGE_KEY = 'portfolio_active_id';

export function getActivePortfolioId() {
    try {
        return sessionStorage.getItem(STORAGE_KEY);
    } catch {
        return null;
    }
}

export function setActivePortfolioId(id) {
    try {
        if (id == null || id === '') {
            sessionStorage.removeItem(STORAGE_KEY);
            return;
        }
        sessionStorage.setItem(STORAGE_KEY, String(id));
    } catch {
        // sessionStorage may be unavailable in some contexts
    }
}

export function clearActivePortfolioId() {
    try {
        sessionStorage.removeItem(STORAGE_KEY);
    } catch {
        // ignore
    }
}
