import { createNavAccessContext } from '../navigation/permissions';
import { findActiveNavItem } from './navigationTree';

export const PAGE_HISTORY_VERSION = 1;
export const PAGE_HISTORY_LIMIT = 12;
export const PAGE_HISTORY_STORAGE_PREFIX = 'stox-page-history:v1:investor:';

const DETAIL_LABELS = [
    [/^\/backtests\/[^/]+$/, 'Backtest Detail'],
    [/^\/review\/reports\/[^/]+$/, 'Review Report'],
    [/^\/knowledge-board\/wiki\/[^/]+$/, 'Wiki Page'],
    [/^\/strategy\/registry\/[^/]+$/, 'Strategy Detail'],
    [/^\/screeners\/registry\/[^/]+$/, 'Screener Registry Detail'],
    [/^\/screeners\/[^/]+$/, 'Screener'],
    [/^\/artifact-library\/[^/]+$/, 'Artifact Detail'],
    [/^\/holdings\/[^/]+\/prices$/, 'Stock Prices'],
];

const EXCLUDED_PREFIXES = [
    '/documentation',
    '/wiki/shared',
    '/invite',
    '/reset-password',
    '/login',
    '/callback',
];

const EXCLUDED_PATHS = new Set(['/evaluations', '/settings']);
const EXCLUDED_ADMIN_PREFIXES = [
    '/settings/users',
    '/settings/stocks',
    '/settings/sync-logs',
    '/settings/admin-alerts',
    '/settings/audit',
    '/settings/universe-price-sync',
    '/settings/data-quality',
    '/settings/indicators',
    '/settings/fundamentals',
    '/settings/ml-scoring',
    '/settings/screener-registry',
    '/settings/strategy-registry',
];

export function normalizeVisitPath(pathname) {
    const raw = String(pathname || '/').split('?')[0].split('#')[0];
    const withLeadingSlash = raw.startsWith('/') ? raw : `/${raw}`;
    const normalized = withLeadingSlash.replace(/\/+/g, '/').replace(/\/{2,}/g, '/').replace(/\/$/, '');
    return normalized || '/';
}

export function pageHistoryStorageKey(userId) {
    return `${PAGE_HISTORY_STORAGE_PREFIX}${String(userId)}`;
}

export function getPageVisitDescriptor(pathname, user = null) {
    const visitKey = normalizeVisitPath(pathname);
    if (
        EXCLUDED_PATHS.has(visitKey)
        || EXCLUDED_PREFIXES.some((prefix) => visitKey === prefix || visitKey.startsWith(`${prefix}/`))
        || EXCLUDED_ADMIN_PREFIXES.some((prefix) => visitKey === prefix || visitKey.startsWith(`${prefix}/`))
    ) {
        return null;
    }
    if (user?.is_admin) return null;

    for (const [pattern, label] of DETAIL_LABELS) {
        if (pattern.test(visitKey)) {
            return { visitKey, destination: visitKey, label };
        }
    }

    const active = findActiveNavItem(visitKey, createNavAccessContext(user));
    if (!active?.title || active.permission === 'admin') {
        return null;
    }

    return { visitKey, destination: visitKey, label: active.title };
}

export function isValidPageVisit(value) {
    return Boolean(
        value
        && typeof value === 'object'
        && typeof value.visitKey === 'string'
        && typeof value.destination === 'string'
        && typeof value.label === 'string'
        && value.visitKey === normalizeVisitPath(value.destination)
        && value.label.trim(),
    );
}

export function readPageVisitHistory(storage, userId) {
    if (!storage || userId === null || userId === undefined) return [];
    try {
        const parsed = JSON.parse(storage.getItem(pageHistoryStorageKey(userId)) || '');
        if (parsed?.version !== PAGE_HISTORY_VERSION || !Array.isArray(parsed.visits)) return [];
        return parsed.visits.filter(isValidPageVisit).slice(0, PAGE_HISTORY_LIMIT);
    } catch {
        return [];
    }
}

export function writePageVisitHistory(storage, userId, visits) {
    if (!storage || userId === null || userId === undefined) return;
    try {
        storage.setItem(pageHistoryStorageKey(userId), JSON.stringify({
            version: PAGE_HISTORY_VERSION,
            visits: visits.slice(0, PAGE_HISTORY_LIMIT),
        }));
    } catch {
        // Storage can be unavailable; the in-memory hook state remains usable.
    }
}

export function addPageVisit(visits, descriptor) {
    if (!descriptor || !isValidPageVisit(descriptor)) return visits;
    if (visits[0]?.visitKey === descriptor.visitKey) return visits;
    return [descriptor, ...visits].slice(0, PAGE_HISTORY_LIMIT);
}
