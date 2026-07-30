/** Sidebar / navigation constants (not user-facing copy). */

export const MAX_NAV_FAVOURITES = 8;

export const STORAGE_KEYS = Object.freeze({
    FAVOURITES_PREFIX: 'lido-nav-favourites-u',
    SIDEBAR_COLLAPSED: 'lido-sidebar-collapsed',
    NAV_GROUPS: 'lido-nav-groups',
    SIDEBAR_SCROLL: 'lido-sidebar-scroll',
});

export function favouritesStorageKey(userId) {
    return `${STORAGE_KEYS.FAVOURITES_PREFIX}${userId || 'anon'}`;
}
