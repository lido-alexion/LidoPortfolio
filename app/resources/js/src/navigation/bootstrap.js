/**
 * Seed the navigation registry from static catalogs.
 * Import once from the app shell before reading the registry.
 */

import { NAVIGATION_CATALOG } from '../config/navigation';
import { QUICK_ACTIONS_CATALOG } from '../config/quickActions';
import { CORE_QUICK_ACTION_HANDLERS } from './coreActionHandlers';
import './icons';
import { navigationRegistry } from './registry';

let seeded = false;

export function ensureNavigationBootstrapped() {
    if (seeded) {
        return navigationRegistry;
    }
    navigationRegistry
        .registerItems(NAVIGATION_CATALOG, { moduleId: 'core' })
        .registerQuickActions(QUICK_ACTIONS_CATALOG, { moduleId: 'core' })
        .registerActionHandlers(CORE_QUICK_ACTION_HANDLERS);
    seeded = true;
    return navigationRegistry;
}

ensureNavigationBootstrapped();
