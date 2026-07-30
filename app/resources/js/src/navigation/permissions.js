/**
 * Navigation access context and permission checks.
 * Designed for future permission lists, workspaces, and plugin gates.
 */

/**
 * @typedef {object} NavAccessContext
 * @property {boolean} [isAdmin]
 * @property {string[]} [permissions]
 * @property {string|number|null} [workspaceId] Future: active workspace / portfolio scope
 * @property {string|null} [moduleFilter] Future: limit to a dynamic module id
 */

/**
 * @param {{ is_admin?: boolean, permissions?: string[], active_portfolio_id?: string|number|null }} [user]
 * @returns {NavAccessContext}
 */
export function createNavAccessContext(user = null) {
    return {
        isAdmin: Boolean(user?.is_admin),
        permissions: Array.isArray(user?.permissions) ? user.permissions.slice() : [],
        workspaceId: user?.active_portfolio_id ?? null,
        moduleFilter: null,
    };
}

/**
 * @param {{ permission?: string|string[]|null, moduleId?: string|null }} item
 * @param {NavAccessContext} [ctx]
 */
export function canAccessNavItem(item, ctx = {}) {
    if (!item) {
        return false;
    }

    if (ctx.moduleFilter && item.moduleId && item.moduleId !== ctx.moduleFilter) {
        return false;
    }

    const required = item.permission;
    if (!required) {
        return true;
    }

    if (required === 'admin') {
        return Boolean(ctx.isAdmin);
    }

    const needed = Array.isArray(required) ? required : [required];
    const granted = new Set(ctx.permissions || []);
    if (ctx.isAdmin) {
        return true;
    }
    return needed.every((key) => granted.has(key));
}

/**
 * @param {{ permission?: string|null, enabled?: boolean }} action
 * @param {NavAccessContext} [ctx]
 */
export function canRunQuickAction(action, ctx = {}) {
    if (!action || action.enabled === false) {
        return false;
    }
    return canAccessNavItem(action, ctx);
}
