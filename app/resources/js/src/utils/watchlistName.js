export const WATCHLIST_NAME_PATTERN = /^[A-Za-z0-9 _-]+$/;

export function validateWatchlistName(name) {
    const trimmed = typeof name === 'string' ? name.trim() : '';

    if (!trimmed) {
        return 'Name is required.';
    }

    if (trimmed.length > 80) {
        return 'Name must be 80 characters or fewer.';
    }

    if (!WATCHLIST_NAME_PATTERN.test(trimmed)) {
        return 'Use only letters, numbers, spaces, hyphens, and underscores.';
    }

    return null;
}
