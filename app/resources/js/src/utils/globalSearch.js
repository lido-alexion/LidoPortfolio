import { NAVIGATION_CATALOG } from '../config/navigation';
import { createNavAccessContext } from '../navigation/permissions';
import { getSidebarPages } from './navigationTree';

function normalize(value) {
    return String(value || '').trim().toLowerCase();
}

function matchRank(label, query) {
    const normalizedLabel = normalize(label);
    if (normalizedLabel === query) return 0;
    if (normalizedLabel.startsWith(query)) return 1;
    if (normalizedLabel.split(/\s+/).some((word) => word.startsWith(query))) return 2;
    if (normalizedLabel.includes(query)) return 3;
    return null;
}

export function searchVisiblePages(query, user, limit = 8) {
    const normalizedQuery = normalize(query);
    if (!normalizedQuery) return [];

    const groupLabels = new Map(
        NAVIGATION_CATALOG
            .filter((item) => item.kind === 'group')
            .map((item) => [item.id, item.title]),
    );
    const context = createNavAccessContext(user);

    return getSidebarPages(context)
        .map((item) => ({
            kind: 'page',
            label: item.title,
            secondary: groupLabels.get(item.group) || 'StoX',
            destination: item.route,
            sourceId: item.id,
            order: item.order ?? 0,
            rank: matchRank(item.title, normalizedQuery),
        }))
        .filter((item) => item.rank !== null && item.destination)
        .sort((a, b) => a.rank - b.rank || a.order - b.order || a.label.localeCompare(b.label))
        .slice(0, limit)
        .map(({ order, rank, ...item }) => item);
}

export function normalizeGlobalSearchQuery(value) {
    return normalize(value);
}

