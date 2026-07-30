/**
 * Configurable sidebar Quick Actions.
 * Add entries here — Sidebar renders from the navigation registry (no JSX changes).
 *
 * Types:
 *   navigate — go to `route` with optional `state`
 *   action   — run handler registered under `actionId` via navigationRegistry.registerActionHandler
 */

import { ROUTES } from '../navigation/routes';

/** @typedef {'navigate'|'action'} QuickActionType */

/**
 * @typedef {object} QuickAction
 * @property {string} id
 * @property {string} title
 * @property {string} icon Lucide name (must be registered)
 * @property {number} order
 * @property {boolean} [enabled]
 * @property {QuickActionType} type
 * @property {string|null} [route]
 * @property {object|null} [state] router location.state
 * @property {string|null} [actionId]
 * @property {string|null} [permission]
 * @property {string|null} [moduleId]
 */

/** @type {QuickAction[]} */
export const QUICK_ACTIONS_CATALOG = [
    {
        id: 'add-transaction',
        title: 'Add Transaction',
        icon: 'ArrowLeftRight',
        order: 10,
        enabled: true,
        type: 'navigate',
        route: ROUTES.TRANSACTIONS,
        state: { focusAddForm: true },
        actionId: null,
        permission: null,
    },
    {
        id: 'add-watchlist-stock',
        title: 'Add Watchlist Stock',
        icon: 'Eye',
        order: 20,
        enabled: true,
        type: 'navigate',
        route: ROUTES.WATCHLIST,
        state: { focusAddSearch: true },
        actionId: null,
        permission: null,
    },
    {
        id: 'review-recommendations',
        title: 'Review Recommendations',
        icon: 'Lightbulb',
        order: 30,
        enabled: true,
        type: 'navigate',
        route: ROUTES.RECOMMENDATIONS,
        state: null,
        actionId: null,
        permission: null,
    },
    {
        id: 'refresh-market-data',
        title: 'Refresh Market Data',
        icon: 'RefreshCw',
        order: 40,
        enabled: true,
        type: 'action',
        route: null,
        state: null,
        actionId: 'refresh-market-data',
        permission: 'admin',
    },
];
