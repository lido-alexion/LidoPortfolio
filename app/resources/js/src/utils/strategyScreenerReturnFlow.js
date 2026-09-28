const STORAGE_KEY = 'lido:strategy:screener-create-return:v1';

/**
 * WP-09 — transient Strategy editor state while creating a Screener (sessionStorage only).
 * @typedef {{ strategyId: string, meta: object, config: object, section?: string, savedAt: number }} StrategyScreenerReturnDraft
 */

/** @returns {StrategyScreenerReturnDraft|null} */
export function readStrategyScreenerReturnDraft() {
    if (typeof window === 'undefined') return null;
    try {
        const raw = window.sessionStorage.getItem(STORAGE_KEY);
        if (!raw) return null;
        const parsed = JSON.parse(raw);
        if (!parsed || typeof parsed !== 'object' || !parsed.strategyId) return null;
        return parsed;
    } catch {
        return null;
    }
}

/** @param {StrategyScreenerReturnDraft} draft */
export function writeStrategyScreenerReturnDraft(draft) {
    if (typeof window === 'undefined') return;
    try {
        window.sessionStorage.setItem(STORAGE_KEY, JSON.stringify({
            ...draft,
            savedAt: Date.now(),
        }));
    } catch {
        // Quota or private mode — navigation still works; user may lose unsaved fields.
    }
}

export function clearStrategyScreenerReturnDraft() {
    if (typeof window === 'undefined') return;
    try {
        window.sessionStorage.removeItem(STORAGE_KEY);
    } catch {
        // ignore
    }
}

export const STRATEGY_SCREENER_CREATE_QUERY = 'from';
export const STRATEGY_SCREENER_CREATE_VALUE = 'strategy';
export const STRATEGY_SCREENER_CREATED_PARAM = 'screener_created';
export const STRATEGY_SCREENER_RETURN_PARAM = 'screener_return';
export const STRATEGY_SCREENER_RETURN_CANCEL = 'cancel';

/**
 * @param {object} config
 * @param {number} screenerId
 * @param {{ name?: string, description?: string }} screenerMeta
 */
export function configWithEligibilityScreener(config, screenerId, screenerMeta = {}) {
    const id = Number(screenerId);
    if (!id) return config;
    const sources = config?.eligibility_sources || [];
    if (sources.some((row) => Number(row.screener_id) === id)) {
        return config;
    }
    return {
        ...config,
        eligibility_sources: [
            ...sources,
            {
                screener_id: id,
                screener_name: screenerMeta.name || `Screener #${id}`,
                description: screenerMeta.description || '',
                enabled: true,
                priority: sources.length + 1,
                display_order: sources.length,
                condition_count: null,
            },
        ],
    };
}
