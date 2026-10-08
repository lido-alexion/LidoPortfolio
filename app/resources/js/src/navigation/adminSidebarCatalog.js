const ADMIN_SIDEBAR_ITEM_IDS = new Set([
    'stocks-admin',
    'users',
    'sync-logs',
    'data-quality',
    'indicator-registry',
    'fundamental-data',
    'ml-scoring',
    'admin-alerts',
    'audit-explorer',
    'universe-price-sync',
    'notification-history',
    'notification-settings',
    'profile',
    'vps-health',
]);

export function buildAdminSidebarCatalog(catalog) {
    return catalog
        .filter((item) => item.id === 'group-administration' || ADMIN_SIDEBAR_ITEM_IDS.has(item.id))
        .map((item) => item.kind === 'page' ? {
            ...item,
            group: 'group-administration',
            parent: 'group-administration',
            showInSidebar: true,
            favouriteEligible: false,
        } : item);
}
