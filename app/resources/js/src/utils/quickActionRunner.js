import { showToast } from '../toast';
import {
    canRunQuickAction as checkQuickActionAccess,
    ensureNavigationBootstrapped,
    navigationRegistry,
} from '../navigation';
import { CORE_QUICK_ACTION_HANDLERS } from '../navigation/coreActionHandlers';

/** @deprecated Use CORE_QUICK_ACTION_HANDLERS or the registry. */
export const QUICK_ACTION_HANDLERS = CORE_QUICK_ACTION_HANDLERS;

export { CORE_QUICK_ACTION_HANDLERS };

/**
 * @param {import('../config/quickActions').QuickAction} action
 * @param {import('../navigation/permissions').NavAccessContext} [ctx]
 */
export function canRunQuickAction(action, ctx = {}) {
    return checkQuickActionAccess(action, ctx);
}

/**
 * @param {import('../navigation/permissions').NavAccessContext} [ctx]
 */
export function getEnabledQuickActions(ctx = {}) {
    ensureNavigationBootstrapped();
    return navigationRegistry.getQuickActions(ctx);
}

/**
 * @param {import('../config/quickActions').QuickAction} action
 * @param {{ navigate: Function, onDone?: Function }} opts
 */
export async function runQuickAction(action, { navigate, onDone } = {}) {
    ensureNavigationBootstrapped();
    if (!action) {
        return;
    }

    if (action.type === 'navigate' && action.route) {
        navigate(action.route, action.state ? { state: action.state } : undefined);
        onDone?.();
        return;
    }

    if (action.type === 'action' && action.actionId) {
        const handler = navigationRegistry.getActionHandler(action.actionId);
        if (!handler) {
            showToast(`No handler for action “${action.actionId}”.`, 'warning');
            return;
        }
        await handler();
        onDone?.();
    }
}
