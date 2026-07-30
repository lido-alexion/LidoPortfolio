/**
 * Icon registry — single place to register Lucide (or custom) icons by name.
 * Catalog entries reference icons by PascalCase string; components never import Lucide directly
 * except through this registry (and NavIcon).
 */

import {
    ArrowLeftRight,
    BarChart4,
    Bell,
    BookOpen,
    Briefcase,
    Building2,
    CalendarDays,
    CandlestickChart,
    ChevronRight,
    ClipboardCheck,
    Cpu,
    Eye,
    ExternalLink,
    Filter,
    Gauge,
    Globe2,
    GripVertical,
    Hourglass,
    Layers,
    Library,
    Lightbulb,
    LineChart,
    Pencil,
    RefreshCw,
    Search,
    Settings,
    ShieldCheck,
    SquareCheck,
    Star,
    Tags,
    Target,
    Users,
    Wallet,
    Zap,
} from 'lucide-react';

/** @type {Map<string, import('react').ComponentType<any>>} */
const ICON_REGISTRY = new Map();

/**
 * @param {string} name
 * @param {import('react').ComponentType<any>} component
 */
export function registerIcon(name, component) {
    if (!name || !component) {
        return;
    }
    ICON_REGISTRY.set(name, component);
}

/**
 * @param {Record<string, import('react').ComponentType<any>>} map
 */
export function registerIcons(map) {
    Object.entries(map || {}).forEach(([name, component]) => {
        registerIcon(name, component);
    });
}

/**
 * @param {string} name
 * @returns {import('react').ComponentType<any>|null}
 */
export function getIconComponent(name) {
    if (!name) {
        return null;
    }
    return ICON_REGISTRY.get(name) || null;
}

/** Built-in Lucide set used by the core catalog. Plugins may call registerIcon / registerIcons. */
export const CORE_NAV_ICONS = Object.freeze({
    ArrowLeftRight,
    BarChart4,
    Bell,
    BookOpen,
    Briefcase,
    Building2,
    CalendarDays,
    CandlestickChart,
    ChevronRight,
    ClipboardCheck,
    Cpu,
    Eye,
    ExternalLink,
    Filter,
    Gauge,
    Globe2,
    GripVertical,
    Hourglass,
    Layers,
    Library,
    Lightbulb,
    LineChart,
    Pencil,
    RefreshCw,
    Search,
    Settings,
    ShieldCheck,
    SquareCheck,
    Star,
    Tags,
    Target,
    Users,
    Wallet,
    Zap,
});

registerIcons(CORE_NAV_ICONS);

export { ChevronRight, GripVertical, Star, ExternalLink };
