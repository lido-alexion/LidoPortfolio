import {
    canAccessNavItem,
    ensureNavigationBootstrapped,
    navigationRegistry,
    pathEquals,
    ROUTES,
} from '../navigation';

/**
 * @typedef {import('../config/navigation').NavItem} NavItem
 * @typedef {import('../navigation/permissions').NavAccessContext} NavAccessContext
 * @typedef {{ id: string, title: string, route: string|null, kind?: string }} BreadcrumbCrumb
 */

export { canAccessNavItem };

function getCatalog() {
    ensureNavigationBootstrapped();
    return navigationRegistry.getCatalog();
}

function byOrder(a, b) {
    return (a.order ?? 0) - (b.order ?? 0);
}

function isNavAncestor(ancestor, node, catalog) {
    let cursor = node;
    const guard = new Set();
    while (cursor?.parent && !guard.has(cursor.id)) {
        guard.add(cursor.id);
        if (cursor.parent === ancestor.id) {
            return true;
        }
        cursor = catalog.find((item) => item.id === cursor.parent) || null;
    }
    return false;
}

/**
 * @param {string} pathname
 * @param {NavItem} item
 */
export function isNavItemActive(pathname, item) {
    if (!item) {
        return false;
    }
    if (item.external) {
        return false;
    }
    if (typeof item.match === 'function') {
        return item.match(pathname);
    }
    if (!item.route) {
        return false;
    }
    if (pathEquals(item.route, ROUTES.HOME) || item.route === ROUTES.HOME) {
        return pathEquals(pathname, ROUTES.HOME);
    }
    return pathname === item.route || pathname.startsWith(`${item.route}/`);
}

/**
 * Build a tree: top-level groups with sidebar-visible page children (sorted).
 * @param {NavItem[]} [catalog]
 * @param {NavAccessContext} [ctx]
 * @returns {Array<NavItem & { children: NavItem[] }>}
 */
export function buildSidebarNavigation(catalog, ctx = {}) {
    const source = catalog || getCatalog();
    const accessible = source.filter((item) => canAccessNavItem(item, ctx));
    const groups = accessible
        .filter((item) => item.kind === 'group' && item.showInSidebar)
        .slice()
        .sort(byOrder);

    return groups.map((group) => {
        const children = accessible
            .filter((item) => (
                item.kind === 'page'
                && item.showInSidebar
                && item.parent === group.id
            ))
            .slice()
            .sort(byOrder)
            .map((item) => ({ ...item, children: null }));

        return {
            ...group,
            children,
        };
    }).filter((group) => group.children.length > 0 || group.showInSidebar);
}

/**
 * @param {NavAccessContext} [ctx]
 */
export function getSidebarPages(ctx = {}) {
    return buildSidebarNavigation(undefined, ctx)
        .flatMap((group) => group.children);
}

/**
 * @param {string} pathname
 * @param {NavAccessContext} [ctx]
 */
export function findActiveNavItem(pathname, ctx = {}) {
    const catalog = getCatalog();
    const candidates = catalog
        .filter((item) => item.kind === 'page'
            && !item.external
            && canAccessNavItem(item, ctx)
            && isNavItemActive(pathname, item));

    if (candidates.length === 0) {
        return null;
    }

    return candidates.slice().sort((a, b) => {
        if (a.parent === b.id) {
            return -1;
        }
        if (b.parent === a.id) {
            return 1;
        }
        if (isNavAncestor(b, a, catalog)) {
            return -1;
        }
        if (isNavAncestor(a, b, catalog)) {
            return 1;
        }
        const aLen = a.route?.length ?? 0;
        const bLen = b.route?.length ?? 0;
        if (bLen !== aLen) {
            return bLen - aLen;
        }
        const aSidebar = a.showInSidebar ? 1 : 0;
        const bSidebar = b.showInSidebar ? 1 : 0;
        if (aSidebar !== bSidebar) {
            return aSidebar - bSidebar;
        }
        return (b.order ?? 0) - (a.order ?? 0);
    })[0];
}

/**
 * @param {string} pathname
 * @param {NavAccessContext} [ctx]
 * @returns {string|null}
 */
export function findActiveSidebarPageId(pathname, ctx = {}) {
    const catalog = getCatalog();
    const active = findActiveNavItem(pathname, ctx);
    if (!active) {
        return null;
    }
    let cursor = active;
    const guard = new Set();
    while (cursor && !guard.has(cursor.id)) {
        guard.add(cursor.id);
        if (cursor.kind === 'page' && cursor.showInSidebar) {
            return cursor.id;
        }
        if (!cursor.parent) {
            break;
        }
        cursor = catalog.find((item) => item.id === cursor.parent) || null;
    }
    return null;
}

/**
 * @param {string} pathname
 * @param {NavAccessContext} [ctx]
 */
export function findActiveGroupId(pathname, ctx = {}) {
    const catalog = getCatalog();
    const active = findActiveNavItem(pathname, ctx);
    if (!active) {
        return null;
    }
    if (active.group) {
        return active.group;
    }
    if (active.parent) {
        const parent = catalog.find((item) => item.id === active.parent);
        return parent?.kind === 'group' ? parent.id : parent?.group ?? null;
    }
    return null;
}

/**
 * @param {string} pathname
 * @param {NavAccessContext} [ctx]
 * @returns {BreadcrumbCrumb[]}
 */
export function buildBreadcrumbs(pathname, ctx = {}) {
    const catalog = getCatalog();
    /** @type {BreadcrumbCrumb[]} */
    const crumbs = [{ id: 'home', title: 'Home', route: ROUTES.HOME, kind: 'home' }];
    const active = findActiveNavItem(pathname, ctx);
    if (!active) {
        if (pathname && pathname !== ROUTES.HOME) {
            crumbs.push({
                id: 'current',
                title: titleFromPath(pathname),
                route: null,
                kind: 'page',
            });
        }
        return crumbs;
    }

    const chain = [];
    let cursor = active;
    const guard = new Set();
    while (cursor && !guard.has(cursor.id)) {
        guard.add(cursor.id);
        chain.unshift(cursor);
        if (!cursor.parent) {
            break;
        }
        cursor = catalog.find((item) => item.id === cursor.parent) || null;
    }

    for (const item of chain) {
        if (item.kind === 'group') {
            crumbs.push({
                id: item.id,
                title: item.title,
                route: null,
                kind: 'group',
            });
            continue;
        }
        crumbs.push({
            id: item.id,
            title: item.title,
            route: item.external ? null : (item.route || null),
            kind: 'page',
        });
    }

    return crumbs;
}

/**
 * @param {string} pathname
 * @param {NavAccessContext} [ctx]
 */
export function getPageTitle(pathname, ctx = {}) {
    const active = findActiveNavItem(pathname, ctx);
    if (active?.title) {
        return active.title;
    }
    if (!pathname || pathname === ROUTES.HOME) {
        return 'Dashboard';
    }
    return titleFromPath(pathname);
}

function titleFromPath(pathname) {
    const segment = pathname.replace(/\/$/, '').split('/').filter(Boolean).pop() || 'Page';
    return segment
        .split('-')
        .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
        .join(' ');
}

/**
 * @param {NavAccessContext} [ctx]
 */
export function getMainTabItemsFromNavigation(ctx = {}) {
    return getSidebarPages(ctx)
        .filter((item) => !item.external && !item.disabled)
        .map((item) => ({
            to: item.route,
            label: item.title,
            end: item.route === ROUTES.HOME,
            match: (p) => isNavItemActive(p, item),
        }));
}
