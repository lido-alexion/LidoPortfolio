import { describe, expect, it } from 'vitest';
import { NAVIGATION_CATALOG } from '../../../resources/js/src/config/navigation';
import { createNavAccessContext, canAccessNavItem } from '../../../resources/js/src/navigation/permissions';
import { buildAdminSidebarCatalog } from '../../../resources/js/src/navigation/adminSidebarCatalog';

describe('admin sidebar navigation', () => {
    it('shows VPS Health in the Administration group for admins only', () => {
        const item = buildAdminSidebarCatalog(NAVIGATION_CATALOG).find((entry) => entry.id === 'vps-health');

        expect(item).toBeDefined();
        expect(item.title).toBe('VPS Health');
        expect(item.route).toBe('/settings/vps-health');
        expect(item.showInSidebar).toBe(true);
        expect(canAccessNavItem(item, createNavAccessContext({ is_admin: true }))).toBe(true);
        expect(canAccessNavItem(item, createNavAccessContext({ is_admin: false }))).toBe(false);
    });
});
