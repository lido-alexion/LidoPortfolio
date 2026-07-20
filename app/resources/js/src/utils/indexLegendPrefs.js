const STORAGE_PREFIX = 'portfolio_indices_legend_v1_';

/**
 * @param {string|number|null} profileId
 * @param {string[]} availableSymbols
 * @returns {Set<string>|null} null when no saved preference
 */
export function loadIndexLegendSelection(profileId, availableSymbols) {
    if (!profileId) {
        return null;
    }

    try {
        const raw = localStorage.getItem(`${STORAGE_PREFIX}${profileId}`);
        if (!raw) {
            return null;
        }
        const parsed = JSON.parse(raw);
        if (!Array.isArray(parsed)) {
            return null;
        }
        const available = new Set(availableSymbols);
        const selected = parsed
            .map((s) => String(s).toUpperCase())
            .filter((s) => available.has(s));
        return new Set(selected);
    } catch {
        return null;
    }
}

/**
 * @param {string|number|null} profileId
 * @param {Iterable<string>} symbols
 */
export function saveIndexLegendSelection(profileId, symbols) {
    if (!profileId) {
        return;
    }

    try {
        const list = Array.from(symbols).map((s) => String(s).toUpperCase());
        localStorage.setItem(`${STORAGE_PREFIX}${profileId}`, JSON.stringify(list));
    } catch {
        // ignore quota errors
    }
}
