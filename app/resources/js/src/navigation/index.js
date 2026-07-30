/**
 * Public navigation API — import from here in app code.
 */

import './bootstrap';

export { ROUTES, pathEquals, pathStartsWith, holdingsPricesPath, screenerEditorPath } from './routes';
export { MAX_NAV_FAVOURITES, STORAGE_KEYS, favouritesStorageKey } from './constants';
export {
    createNavAccessContext,
    canAccessNavItem,
    canRunQuickAction,
} from './permissions';
export {
    registerIcon,
    registerIcons,
    getIconComponent,
    CORE_NAV_ICONS,
    ChevronRight,
    GripVertical,
    Star,
} from './icons';
export { navigationRegistry, NavigationRegistry } from './registry';
export { ensureNavigationBootstrapped } from './bootstrap';
