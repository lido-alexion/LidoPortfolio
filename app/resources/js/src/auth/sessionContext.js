const STORAGE_KEY = 'stox_auth_session_context';
const USER_KEY = 'stox_auth_session_user_id';

export function getAuthSessionContext() {
    try {
        const requested = new URLSearchParams(window.location.search).get('auth_context');
        if (requested === 'admin' || requested === 'investor') {
            sessionStorage.setItem(STORAGE_KEY, requested);
            return requested;
        }
        return sessionStorage.getItem(STORAGE_KEY) === 'admin' ? 'admin' : 'investor';
    } catch {
        return 'investor';
    }
}

export function setAuthSessionUserId(userId) {
    try {
        if (userId == null) sessionStorage.removeItem(USER_KEY);
        else sessionStorage.setItem(USER_KEY, String(userId));
    } catch {
        // Identity is only used to scope local cache invalidation.
    }
}

export function getAuthSessionUserId() {
    try { return sessionStorage.getItem(USER_KEY); } catch { return null; }
}

export function isCurrentAuthSessionUserId(userId) {
    const current = getAuthSessionUserId();
    return (current ?? null) === (userId == null ? null : String(userId));
}
