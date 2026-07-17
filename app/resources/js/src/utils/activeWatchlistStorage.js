const STORAGE_PREFIX = 'portfolio_active_watchlist_v1_';

export function loadActiveWatchlistId(profileId) {
    if (!profileId) {
        return null;
    }

    try {
        const raw = localStorage.getItem(`${STORAGE_PREFIX}${profileId}`);
        if (!raw) {
            return null;
        }

        const parsed = Number.parseInt(raw, 10);

        return Number.isFinite(parsed) ? parsed : null;
    } catch {
        return null;
    }
}

export function saveActiveWatchlistId(profileId, watchlistId) {
    if (!profileId || !watchlistId) {
        return;
    }

    try {
        localStorage.setItem(`${STORAGE_PREFIX}${profileId}`, String(watchlistId));
    } catch {
        // ignore quota errors
    }
}

export function clearActiveWatchlistId(profileId) {
    if (!profileId) {
        return;
    }

    try {
        localStorage.removeItem(`${STORAGE_PREFIX}${profileId}`);
    } catch {
        // ignore
    }
}
