const ORDER_KEY_PREFIX = 'portfolio_knowledge_board_order_';
const SORT_KEY = 'portfolio_knowledge_board_sort';

const VALID_SORT_VALUES = new Set([
    'manual',
    'updated_at',
    'created_at',
    'title',
    'pinned_first',
]);

export function loadSortPreference() {
    try {
        const stored = localStorage.getItem(SORT_KEY);
        if (stored && VALID_SORT_VALUES.has(stored)) {
            return stored;
        }
    } catch {
        // private mode — ignore
    }
    return 'updated_at';
}

export function saveSortPreference(sort) {
    if (!VALID_SORT_VALUES.has(sort)) {
        return;
    }
    try {
        localStorage.setItem(SORT_KEY, sort);
    } catch {
        // Quota or private mode — ignore.
    }
}

export function manualOrderStorageKey(profileId) {
    return `${ORDER_KEY_PREFIX}${profileId || 'default'}`;
}

export function loadManualOrder(profileId) {
    try {
        const raw = localStorage.getItem(manualOrderStorageKey(profileId));
        if (!raw) {
            return [];
        }
        const parsed = JSON.parse(raw);
        return Array.isArray(parsed) ? parsed.map(String) : [];
    } catch {
        return [];
    }
}

export function saveManualOrder(profileId, orderedIds) {
    try {
        localStorage.setItem(
            manualOrderStorageKey(profileId),
            JSON.stringify(orderedIds.map(String)),
        );
    } catch {
        // Quota or private mode — ignore.
    }
}

/**
 * @param {Array<{ id: number|string, updated_at?: string }>} notes
 * @param {string[]} orderIds
 */
export function applyManualOrder(notes, orderIds) {
    if (!orderIds.length) {
        return [...notes].sort((a, b) => (
            new Date(b.updated_at || 0) - new Date(a.updated_at || 0)
        ));
    }

    const rank = new Map(orderIds.map((id, index) => [String(id), index]));
    const known = [];
    const unknown = [];

    for (const note of notes) {
        if (rank.has(String(note.id))) {
            known.push(note);
        } else {
            unknown.push(note);
        }
    }

    known.sort((a, b) => rank.get(String(a.id)) - rank.get(String(b.id)));
    unknown.sort((a, b) => new Date(b.updated_at || 0) - new Date(a.updated_at || 0));

    return [...known, ...unknown];
}
