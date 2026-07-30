/**
 * Extensible navigation registry.
 *
 * Core app seeds items + quick actions at bootstrap.
 * Future plugins / marketplace modules / custom dashboards call:
 *   navigationRegistry.registerModule({ id, items, quickActions, icons, actionHandlers })
 */

import { registerIcons } from './icons';
import { canAccessNavItem, canRunQuickAction } from './permissions';

/**
 * @typedef {import('../config/navigation').NavItem} NavItem
 * @typedef {import('../config/quickActions').QuickAction} QuickAction
 */

class NavigationRegistry {
    constructor() {
        /** @type {Map<string, NavItem>} */
        this.items = new Map();
        /** @type {Map<string, QuickAction>} */
        this.quickActions = new Map();
        /** @type {Map<string, Function>} */
        this.actionHandlers = new Map();
        /** @type {Map<string, object>} */
        this.modules = new Map();
    }

    /**
     * @param {NavItem[]} items
     * @param {{ moduleId?: string|null }} [opts]
     */
    registerItems(items, opts = {}) {
        (items || []).forEach((item) => {
            if (!item?.id) {
                return;
            }
            const next = {
                ...item,
                moduleId: item.moduleId ?? opts.moduleId ?? null,
            };
            this.items.set(next.id, next);
        });
        return this;
    }

    /**
     * @param {string} id
     */
    unregisterItem(id) {
        this.items.delete(id);
        return this;
    }

    /**
     * @param {QuickAction[]} actions
     * @param {{ moduleId?: string|null }} [opts]
     */
    registerQuickActions(actions, opts = {}) {
        (actions || []).forEach((action) => {
            if (!action?.id) {
                return;
            }
            this.quickActions.set(action.id, {
                ...action,
                moduleId: action.moduleId ?? opts.moduleId ?? null,
            });
        });
        return this;
    }

    /**
     * @param {string} actionId
     * @param {Function} handler
     */
    registerActionHandler(actionId, handler) {
        if (actionId && typeof handler === 'function') {
            this.actionHandlers.set(actionId, handler);
        }
        return this;
    }

    /**
     * @param {Record<string, Function>} handlers
     */
    registerActionHandlers(handlers) {
        Object.entries(handlers || {}).forEach(([id, handler]) => {
            this.registerActionHandler(id, handler);
        });
        return this;
    }

    /**
     * Register a dynamic module (plugin, marketplace pack, custom dashboard pack).
     * @param {{
     *   id: string,
     *   items?: NavItem[],
     *   quickActions?: QuickAction[],
     *   icons?: Record<string, import('react').ComponentType<any>>,
     *   actionHandlers?: Record<string, Function>,
     * }} module
     */
    registerModule(module) {
        if (!module?.id) {
            throw new Error('registerModule requires an id');
        }
        this.modules.set(module.id, module);
        if (module.icons) {
            registerIcons(module.icons);
        }
        if (module.items) {
            this.registerItems(module.items, { moduleId: module.id });
        }
        if (module.quickActions) {
            this.registerQuickActions(module.quickActions, { moduleId: module.id });
        }
        if (module.actionHandlers) {
            this.registerActionHandlers(module.actionHandlers);
        }
        return this;
    }

    /**
     * @param {string} moduleId
     */
    unregisterModule(moduleId) {
        const module = this.modules.get(moduleId);
        if (!module) {
            return this;
        }
        (module.items || []).forEach((item) => this.unregisterItem(item.id));
        (module.quickActions || []).forEach((action) => this.quickActions.delete(action.id));
        Object.keys(module.actionHandlers || {}).forEach((id) => this.actionHandlers.delete(id));
        this.modules.delete(moduleId);
        return this;
    }

    /** @returns {NavItem[]} */
    getCatalog() {
        return Array.from(this.items.values());
    }

    /**
     * @param {string} id
     * @returns {NavItem|undefined}
     */
    getItem(id) {
        return this.items.get(id);
    }

    /**
     * @param {import('./permissions').NavAccessContext} [ctx]
     * @returns {QuickAction[]}
     */
    getQuickActions(ctx = {}) {
        return Array.from(this.quickActions.values())
            .filter((action) => canRunQuickAction(action, ctx))
            .slice()
            .sort((a, b) => (a.order ?? 0) - (b.order ?? 0));
    }

    /**
     * @param {string} actionId
     */
    getActionHandler(actionId) {
        return this.actionHandlers.get(actionId) || null;
    }

    /**
     * Favourite-eligible pages currently visible to the user.
     * @param {import('./permissions').NavAccessContext} [ctx]
     */
    getFavouriteEligiblePages(ctx = {}) {
        return this.getCatalog().filter((item) => (
            item.kind === 'page'
            && item.favouriteEligible
            && item.route
            && !item.disabled
            && canAccessNavItem(item, ctx)
        ));
    }
}

/** App-wide singleton. */
export const navigationRegistry = new NavigationRegistry();

export { NavigationRegistry };
