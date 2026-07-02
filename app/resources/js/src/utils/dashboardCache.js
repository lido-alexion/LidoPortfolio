/**
 * Client-side dashboard payload cache (localStorage + in-memory).
 * Scoped per user + active portfolio; invalidated on transactions, sync, portfolio switch, logout.
 */

const CACHE_VERSION = 1;
const CACHE_KEY_PREFIX = 'portfolio_dashboard_cache_v1';
/** Default TTL — dashboard data is mostly stable until the next daily price sync. */
export const DASHBOARD_CACHE_TTL_MS = 24 * 60 * 60 * 1000;

/** @type {{ key: string, entry: object } | null} */
let memoryCache = null;

export function buildDashboardCacheKey(userId, profileId) {
    return `${CACHE_KEY_PREFIX}_${userId}_${profileId}`;
}

/**
 * @param {unknown} apiData
 * @returns {Array<{
 *   stock_id: number,
 *   symbol: string,
 *   pattern_id: string,
 *   pattern_name: string,
 *   category: string,
 *   bar_date: string,
 *   variant: string,
 * }>}
 */
export function flattenPatternScanResults(apiData) {
    const flat = [];
    for (const stock of apiData?.results || []) {
        for (const match of stock.matches || []) {
            flat.push({
                stock_id: stock.stock_id,
                symbol: stock.symbol,
                pattern_id: match.id,
                pattern_name: match.name,
                category: match.category,
                bar_date: match.bar_date,
                variant: match.variant,
            });
        }
    }
    return flat;
}

/** Drop unused OHLCV rows — dashboard only needs benchmark.symbol. */
export function stripDashboardPayloadForCache(dashboard) {
    if (!dashboard || typeof dashboard !== 'object') {
        return dashboard;
    }

    const copy = { ...dashboard };
    if (copy.nifty_comparison && typeof copy.nifty_comparison === 'object') {
        const { prices: _prices, ...niftyRest } = copy.nifty_comparison;
        copy.nifty_comparison = niftyRest;
    }
    return copy;
}

export function extractLastSyncDate(dashboard) {
    const today = dashboard?.daily_market_sync?.today;
    return typeof today === 'string' && today.length > 0 ? today : null;
}

function isExpired(entry) {
    if (!entry?.expiresAt) {
        return true;
    }
    return Date.parse(entry.expiresAt) <= Date.now();
}

function isSyncInProgress(dashboard) {
    return Boolean(dashboard?.daily_market_sync?.in_progress);
}

function parseStoredEntry(raw) {
    if (!raw) {
        return null;
    }
    try {
        const entry = JSON.parse(raw);
        if (entry?.v !== CACHE_VERSION) {
            return null;
        }
        if (!entry.dashboard || !Array.isArray(entry.patternRows)) {
            return null;
        }
        if (isExpired(entry)) {
            return null;
        }
        if (isSyncInProgress(entry.dashboard)) {
            return null;
        }
        return entry;
    } catch {
        return null;
    }
}

/**
 * @returns {{
 *   dashboard: object,
 *   patternRows: object[],
 *   cachedAt: string,
 *   expiresAt: string,
 *   lastSyncDate: string | null,
 * } | null}
 */
export function readDashboardCache(userId, profileId) {
    if (userId == null || profileId == null) {
        return null;
    }

    const key = buildDashboardCacheKey(userId, profileId);

    if (memoryCache?.key === key) {
        const parsed = parseStoredEntry(JSON.stringify(memoryCache.entry));
        if (parsed) {
            return {
                dashboard: parsed.dashboard,
                patternRows: parsed.patternRows,
                cachedAt: parsed.cachedAt,
                expiresAt: parsed.expiresAt,
                lastSyncDate: parsed.lastSyncDate ?? null,
            };
        }
        memoryCache = null;
    }

    try {
        const raw = localStorage.getItem(key);
        const entry = parseStoredEntry(raw);
        if (!entry) {
            if (raw) {
                localStorage.removeItem(key);
            }
            return null;
        }

        memoryCache = { key, entry };

        return {
            dashboard: entry.dashboard,
            patternRows: entry.patternRows,
            cachedAt: entry.cachedAt,
            expiresAt: entry.expiresAt,
            lastSyncDate: entry.lastSyncDate ?? null,
        };
    } catch {
        return null;
    }
}

export function writeDashboardCache(userId, profileId, { dashboard, patternRows }) {
    if (userId == null || profileId == null || !dashboard) {
        return;
    }

    const cachedAt = new Date().toISOString();
    const entry = {
        v: CACHE_VERSION,
        userId,
        profileId,
        cachedAt,
        expiresAt: new Date(Date.now() + DASHBOARD_CACHE_TTL_MS).toISOString(),
        lastSyncDate: extractLastSyncDate(dashboard),
        dashboard: stripDashboardPayloadForCache(dashboard),
        patternRows: Array.isArray(patternRows) ? patternRows : [],
    };

    const key = buildDashboardCacheKey(userId, profileId);

    try {
        localStorage.setItem(key, JSON.stringify(entry));
        memoryCache = { key, entry };
    } catch {
        memoryCache = { key, entry };
    }
}

export function clearDashboardCache(userId, profileId) {
    if (userId == null || profileId == null) {
        return;
    }
    const key = buildDashboardCacheKey(userId, profileId);
    try {
        localStorage.removeItem(key);
    } catch {
        // ignore
    }
    if (memoryCache?.key === key) {
        memoryCache = null;
    }
}

export function clearAllDashboardCaches() {
    memoryCache = null;
    try {
        const keysToRemove = [];
        for (let i = 0; i < localStorage.length; i += 1) {
            const key = localStorage.key(i);
            if (key && key.startsWith(`${CACHE_KEY_PREFIX}_`)) {
                keysToRemove.push(key);
            }
        }
        keysToRemove.forEach((key) => localStorage.removeItem(key));
    } catch {
        // ignore
    }
}

/**
 * @param {string} iso
 * @returns {string}
 */
export function formatDashboardCacheLabel(iso) {
    if (!iso) {
        return '';
    }
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) {
        return '';
    }
    return date.toLocaleString(undefined, {
        day: 'numeric',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    });
}
