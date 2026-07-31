/**
 * Canonical app route paths for navigation, quick actions, and deep links.
 * Prefer importing ROUTES over string literals in nav-related code.
 */

export const ROUTES = Object.freeze({
    HOME: '/',
    HOLDINGS: '/holdings',
    WATCHLIST: '/watchlist',
    TRANSACTIONS: '/transactions',
    TRANSACTIONS_PENDING: '/transactions/pending',
    TRANSACTIONS_CLOSED: '/transactions/closed',
    CASH: '/cash',
    CORPORATE_ACTION: '/corporate-action',
    CANDIDATES: '/candidates',
    EVALUATIONS: '/evaluations',
    EXPLORER: '/explorer',
    PATTERNS: '/patterns',
    INDICES: '/indices',
    CALENDAR: '/calendar',
    MARKET_DEPTH: '/market-depth',
    RECOMMENDATIONS: '/recommendations',
    REVIEW: '/review',
    STRATEGY: '/strategy',
    STRATEGY_REGISTRY: '/strategy/registry',
    BACKTESTS: '/backtests',
    SCREENERS: '/screeners',
    SCREENER_REGISTRY: '/screeners/registry',
    KNOWLEDGE_BOARD: '/knowledge-board',
    KNOWLEDGE_TAGS: '/knowledge-board/tags',
    SETTINGS: '/settings',
    SETTINGS_GLOBAL: '/settings/global',
    SETTINGS_PORTFOLIO: '/settings/portfolio',
    SETTINGS_ACCOUNT: '/settings/account',
    SETTINGS_ALERT_POLICIES: '/settings/alert-policies',
    SETTINGS_USERS: '/settings/users',
    SETTINGS_SYNC_LOGS: '/settings/sync-logs',
    SETTINGS_DATA_QUALITY: '/settings/data-quality',
    SETTINGS_INDICATORS: '/settings/indicators',
    SETTINGS_ADMIN_ALERTS: '/settings/admin-alerts',
    SETTINGS_UNIVERSE_PRICE_SYNC: '/settings/universe-price-sync',
    SETTINGS_SCREENER_REGISTRY: '/settings/screener-registry',
    SETTINGS_STRATEGY_REGISTRY: '/settings/strategy-registry',
    NOTIFICATION_HISTORY: '/notification-history',
    PORTFOLIOS: '/portfolios',
    PROFILE: '/profile',
    DOCUMENTATION: '/documentation',
});

/**
 * @param {string} pathname
 * @param {string} route
 */
export function pathEquals(pathname, route) {
    if (route === ROUTES.HOME) {
        return pathname === '/' || pathname === '';
    }
    return pathname === route;
}

/**
 * @param {string} pathname
 * @param {string} route
 */
export function pathStartsWith(pathname, route) {
    if (route === ROUTES.HOME) {
        return pathEquals(pathname, route);
    }
    return pathname === route || pathname.startsWith(`${route}/`);
}

/**
 * @param {string} stockId
 */
export function holdingsPricesPath(stockId) {
    return `${ROUTES.HOLDINGS}/${stockId}/prices`;
}

/**
 * @param {string} screenerId
 */
export function screenerEditorPath(screenerId) {
    return `${ROUTES.SCREENERS}/${screenerId}`;
}

/**
 * @param {string|number} id
 */
export function backtestDetailPath(id) {
    return `${ROUTES.BACKTESTS}/${id}`;
}
